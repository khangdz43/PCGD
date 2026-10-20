<?php

namespace App\Services\Households;

use App\Models\Household;
use App\Models\Person;
use App\Models\User;
use App\Support\DataAccessScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HouseholdTrashService
{
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
            ->paginate(15)
            ->withQueryString();

        $pageItems = $items->getCollection();
        $householdIds = $pageItems->where('item_type', 'household')->pluck('id');
        $personIds = $pageItems->where('item_type', 'person')->pluck('id');

        $householdRecords = Household::withTrashed()
            ->with([
                'province',
                'commune',
                'village',
                'deletedBy',
                'persons' => fn ($persons) => $persons
                    ->withoutGlobalScope('active_household')
                    ->withTrashed()
                    ->select('id', 'household_id', 'first_name', 'last_name', 'dob', 'relationship_with_head'),
            ])
            ->withCount(['persons' => fn (Builder $persons): Builder => $persons
                ->withoutGlobalScope('active_household')
                ->withTrashed()])
            ->whereIn('id', $householdIds)
            ->get()
            ->keyBy('id');
        $personRecords = Person::withTrashed()
            ->with([
                'household' => fn ($household) => $household->withTrashed()
                    ->with(['province', 'commune', 'village']),
                'deletedBy',
            ])
            ->whereIn('id', $personIds)
            ->get()
            ->keyBy('id');

        $pageItems->transform(function (object $item) use ($householdRecords, $personRecords): object {
            $item->record = $item->item_type === 'household'
                ? $householdRecords->get($item->id)
                : $personRecords->get($item->id);
            $item->headPerson = $item->item_type === 'household'
                ? $item->record?->persons->firstWhere('relationship_with_head', 'Chủ hộ')
                    ?? $item->record?->persons->first(fn (Person $person): bool =>
                        trim($person->last_name.' '.$person->first_name)
                        === trim($item->record->head_last_name.' '.$item->record->head_first_name))
                : null;

            return $item;
        });

        return ['items' => $items];
    }

    public function restoreHousehold(User $user, int $id): void
    {
        $household = DataAccessScope::households(Household::onlyTrashed(), $user)
            ->findOrFail($id);
        $household->deleted_by = null;
        $household->save();
        $household->restore();
    }

    public function permanentlyDeleteHousehold(User $user, int $id): void
    {
        $household = DataAccessScope::households(Household::onlyTrashed(), $user)->findOrFail($id);
        DB::transaction(function () use ($household): void {
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
        $this->assertCanRestorePerson($person);

        $person->deleted_by = null;
        $person->save();
        $person->restore();
    }

    public function permanentlyDeletePerson(User $user, int $id): void
    {
        $this->trashedPersons($user)->findOrFail($id)->forceDelete();
    }

    public function restoreMany(User $user, array $householdIds, array $personIds): int
    {
        $households = $this->trashedHouseholds($user, $householdIds);
        $persons = $this->trashedPersons($user, $personIds)->get();
        abort_unless($households->count() === count($householdIds), 404);
        abort_unless($persons->count() === count($personIds), 404);

        foreach ($persons as $person) {
            $this->assertCanRestorePerson(
                $person,
                $households->modelKeys()
            );
        }

        return DB::transaction(function () use ($households, $persons): int {
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

    public function householdForDetails(User $user, int $id): Household
    {
        return DataAccessScope::households(Household::withTrashed(), $user)
            ->with([
                'province',
                'commune',
                'village',
                'persons' => fn ($persons) => $persons->withoutGlobalScope('active_household')
                    ->withTrashed()
                    ->with(['ethnicity', 'educations.school', 'disability']),
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

    private function assertCanRestorePerson(Person $person, array $restoringHouseholdIds = []): void
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
