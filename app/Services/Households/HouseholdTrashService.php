<?php

namespace App\Services\Households;

use App\Models\Household;
use App\Models\Person;
use App\Models\User;
use App\Services\Geography\VillageService;
use App\Support\DataAccessScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HouseholdTrashService
{
    public function __construct(private readonly ?VillageService $villages = null) {}

    public function paginate(User $user, array $filters): array
    {
        $households = Household::onlyTrashed()
            ->selectRaw("'household' AS item_type")
            ->addSelect('households.id', 'households.deleted_at');
        DataAccessScope::households($households, $user);
        $this->applyDates($households, $filters);

        $persons = Person::withoutGlobalScope('active_household')
            ->onlyTrashed()
            ->selectRaw("'person' AS item_type")
            ->addSelect('persons.id', 'persons.deleted_at')
            ->whereHas('household', function (Builder $households) use ($user, $filters): void {
                $households->withTrashed();
                DataAccessScope::households($households, $user);
                $this->applyLocationFilters($households, $filters);
            });
        $this->applyDates($persons, $filters);

        if (filled($filters['q'] ?? null)) {
            $search = trim((string) $filters['q']);
            $households->where(function (Builder $query) use ($search): void {
                $query->where('households.household_code', 'like', "%{$search}%")
                    ->orWhere('households.head_first_name', 'like', "%{$search}%")
                    ->orWhere('households.head_last_name', 'like', "%{$search}%");
            });
            $persons->where(function (Builder $query) use ($search): void {
                $query->where('persons.first_name', 'like', "%{$search}%")
                    ->orWhere('persons.last_name', 'like', "%{$search}%")
                    ->orWhereHas('household', fn (Builder $household) => $household
                        ->where('household_code', 'like', "%{$search}%"));
            });
        }

        $this->applyLocationFilters($households, $filters);

        $type = $filters['type'] ?? 'all';
        $query = match ($type) {
            'household' => $households->toBase(),
            'person' => $persons->toBase(),
            default => $households->toBase()->unionAll($persons->toBase()),
        };

        /** @var LengthAwarePaginator $items */
        $items = DB::query()
            ->fromSub($query, 'trash_items')
            ->orderByDesc('deleted_at')
            ->orderByDesc('id')
            ->paginate(min(max((int) ($filters['per_page'] ?? 50), 1), 100))
            ->withQueryString();

        $pageItems = $items->getCollection();
        $householdIds = $pageItems->where('item_type', 'household')->pluck('id');
        $personIds = $pageItems->where('item_type', 'person')->pluck('id');

        $personRecords = Person::withoutGlobalScope('active_household')
            ->withTrashed()
            ->whereIn('id', $personIds)
            ->get(['id', 'household_id', 'first_name', 'last_name'])
            ->keyBy('id');
        $relatedHouseholdIds = $householdIds
            ->merge($personRecords->pluck('household_id'))
            ->unique()
            ->values();
        $householdRecords = Household::withTrashed()
            ->whereIn('id', $relatedHouseholdIds)
            ->get(['id', 'household_code', 'head_first_name', 'head_last_name'])
            ->keyBy('id');

        $pageItems->transform(function (object $item) use ($householdRecords, $personRecords): object {
            if ($item->item_type === 'household') {
                $household = $householdRecords->get($item->id);
                $item->record = $household === null ? null : [
                    'household_code' => $household->household_code,
                    'head_name' => trim($household->head_last_name.' '.$household->head_first_name),
                ];

                return $item;
            }

            $person = $personRecords->get($item->id);
            $household = $person === null ? null : $householdRecords->get($person->household_id);
            $item->record = [
                'household_code' => $household?->household_code,
                'head_name' => $household === null ? null : trim($household->head_last_name.' '.$household->head_first_name),
                'person_name' => $person === null ? null : trim($person->last_name.' '.$person->first_name),
            ];

            return $item;
        });

        return ['items' => $items];
    }

    public function restoreHousehold(User $user, int $id): void
    {
        $household = DataAccessScope::households(Household::onlyTrashed(), $user)
            ->findOrFail($id);
        DB::transaction(function () use ($household): void {
            $this->lockOpenVillagesForHouseholds([$household->id]);
            $household->deleted_by = null;
            $household->save();
            $household->restore();
        });
    }

    public function permanentlyDeleteHousehold(User $user, int $id): void
    {
        $household = DataAccessScope::households(Household::onlyTrashed(), $user)->findOrFail($id);
        DB::transaction(function () use ($household): void {
            $this->lockOpenVillagesForHouseholds([$household->id]);
            Person::withoutGlobalScope('active_household')
                ->withTrashed()
                ->where('household_id', $household->id)
                ->forceDelete();
            $household->forceDelete();
        });
    }

    public function restorePerson(User $user, int $id): void
    {
        $person = $this->trashedPersons($user)->findOrFail($id);
        DB::transaction(function () use ($person): void {
            $this->lockOpenVillagesForHouseholds([$person->household_id]);
            Household::withTrashed()
                ->whereKey($person->household_id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertCanRestorePerson($person);
            $person->deleted_by = null;
            $person->save();
            $person->restore();
        });
    }

    public function permanentlyDeletePerson(User $user, int $id): void
    {
        $person = $this->trashedPersons($user)->findOrFail($id);
        DB::transaction(function () use ($person): void {
            $this->lockOpenVillagesForHouseholds([$person->household_id]);
            $person->forceDelete();
        });
    }

    public function restoreMany(User $user, array $householdIds, array $personIds): int
    {
        $households = $this->trashedHouseholds($user, $householdIds);
        $persons = $this->trashedPersons($user, $personIds)->get();
        abort_unless($households->count() === count($householdIds), 404);
        abort_unless($persons->count() === count($personIds), 404);

        return DB::transaction(function () use ($households, $persons): int {
            $this->lockOpenVillagesForHouseholds([
                ...$households->modelKeys(),
                ...$persons->pluck('household_id')->all(),
            ]);
            Household::withTrashed()
                ->whereIn('id', [
                    ...$households->modelKeys(),
                    ...$persons->pluck('household_id')->all(),
                ])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            foreach ($persons as $person) {
                $this->assertCanRestorePerson(
                    $person,
                    $households->modelKeys(),
                    $persons->modelKeys(),
                );
            }
            foreach ($households as $household) {
                $household->deleted_by = null;
                $household->save();
                $household->restore();
            }
            foreach ($persons as $person) {
                $person->deleted_by = null;
                $person->save();
                $person->restore();
            }

            return $households->count() + $persons->count();
        });
    }

    public function permanentlyDeleteMany(User $user, array $householdIds, array $personIds): int
    {
        $households = $this->trashedHouseholds($user, $householdIds);
        $persons = $this->trashedPersons($user, $personIds)->get();
        abort_unless($households->count() === count($householdIds), 404);
        abort_unless($persons->count() === count($personIds), 404);

        return DB::transaction(function () use ($households, $persons): int {
            $this->lockOpenVillagesForHouseholds([
                ...$households->modelKeys(),
                ...$persons->pluck('household_id')->all(),
            ]);
            foreach ($households as $household) {
                Person::withoutGlobalScope('active_household')
                    ->withTrashed()
                    ->where('household_id', $household->id)
                    ->forceDelete();
                $household->forceDelete();
            }

            foreach ($persons as $person) {
                if (! $households->contains('id', $person->household_id)) {
                    $person->forceDelete();
                }
            }

            return $households->count() + $persons->count();
        });
    }

    private function lockOpenVillagesForHouseholds(array $householdIds): void
    {
        $villageCodes = Household::withTrashed()
            ->whereIn('id', array_unique($householdIds))
            ->pluck('village_code')
            ->filter(fn ($villageCode): bool => $villageCode !== null && $villageCode !== '')
            ->unique()
            ->sort()
            ->values();

        foreach ($villageCodes as $villageCode) {
            $this->villageService()->assertAcceptsHouseholds($villageCode, true);
        }
    }

    private function villageService(): VillageService
    {
        return $this->villages ?? app(VillageService::class);
    }

    public function householdForDetails(User $user, int $id): Household
    {
        return DataAccessScope::households(Household::withTrashed(), $user)
            ->with([
                'province',
                'commune',
                'village',
                'persons' => fn ($persons) => $persons->withoutGlobalScope('active_household')
                    ->withTrashed()
                    ->withDetails(),
            ])
            ->findOrFail($id);
    }

    private function trashedPersons(User $user, ?array $ids = null): Builder
    {
        $query = Person::withoutGlobalScope('active_household')->onlyTrashed()->whereHas(
            'household',
            fn (Builder $households) => DataAccessScope::households($households->withTrashed(), $user)
        );
        if ($ids !== null) {
            $query->whereIn('persons.id', $ids);
        }

        return $query;
    }

    private function trashedHouseholds(User $user, array $ids): \Illuminate\Database\Eloquent\Collection
    {
        return DataAccessScope::households(Household::onlyTrashed(), $user)
            ->whereIn('id', $ids)
            ->get();
    }

    private function assertCanRestorePerson(
        Person $person,
        array $restoringHouseholdIds = [],
        array $restoringPersonIds = [],
    ): void
    {
        $household = Household::withTrashed()->findOrFail($person->household_id);
        if ($household->trashed() && ! in_array($household->id, $restoringHouseholdIds, true)) {
            throw ValidationException::withMessages([
                'trash' => 'Hãy khôi phục phiếu điều tra trước khi khôi phục thành viên trong hộ đó.',
            ]);
        }

        if (
            $person->relationship_with_head === 'Chủ hộ'
            && Person::query()
                ->where('household_id', $person->household_id)
                ->where('relationship_with_head', 'Chủ hộ')
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'trash' => 'Hộ đã có chủ hộ khác. Hãy xử lý chủ hộ hiện tại trước khi khôi phục.',
            ]);
        }

        $relationshipSlot = match ($person->relationship_with_head) {
            'Vợ', 'Chồng' => ['Vợ', 'Chồng'],
            'Cha', 'Mẹ' => ['Cha', 'Mẹ'],
            default => null,
        };
        if ($relationshipSlot === null) {
            return;
        }

        $activeConflict = Person::withoutGlobalScope('active_household')
            ->where('household_id', $person->household_id)
            ->whereIn('relationship_with_head', $relationshipSlot)
            ->whereKeyNot($person->getKey())
            ->exists();
        $restoringConflict = $restoringPersonIds !== []
            && Person::withoutGlobalScope('active_household')
                ->onlyTrashed()
                ->whereIn('id', $restoringPersonIds)
                ->where('household_id', $person->household_id)
                ->whereIn('relationship_with_head', $relationshipSlot)
                ->whereKeyNot($person->getKey())
                ->exists();

        if ($activeConflict || $restoringConflict) {
            $label = $relationshipSlot[0] === 'Vợ' ? 'vợ hoặc chồng' : 'cha hoặc mẹ';
            throw ValidationException::withMessages([
                'trash' => ["Phiếu đã có {$label}; hãy xử lý thành viên hiện tại trước khi khôi phục."],
            ]);
        }
    }

    private function applyDates(Builder $query, array $filters): void
    {
        if (filled($filters['deleted_from'] ?? null)) {
            $query->whereDate('deleted_at', '>=', $filters['deleted_from']);
        }
        if (filled($filters['deleted_to'] ?? null)) {
            $query->whereDate('deleted_at', '<=', $filters['deleted_to']);
        }
    }

    private function applyLocationFilters(Builder $query, array $filters): void
    {
        foreach (['province_code', 'commune_code', 'village_code'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($field, $filters[$field]);
            }
        }
    }
}
