<?php

namespace App\Services\Education;

use App\Models\Role;
use App\Models\ImportLog;
use App\Models\School;
use App\Models\SchoolMergeHistory;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SchoolMergeService
{
    public function availableForMerge(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = School::query()->select([
            'id',
            'code',
            'name',
            'type',
            'is_active',
            'level',
            'province_code',
            'commune_code',
        ]);
        $this->constrainToUserArea($query, $user);
        $targetCode = $filters['target_code'] ?? null;
        $query->where('is_active', filled($targetCode) ? false : true);
        $query->whereIn('level', $this->levelsForUser($user));
        if (filled($targetCode)) {
            $targetQuery = School::query()->where('code', $targetCode);
            $this->constrainToUserArea($targetQuery, $user);
            $target = $targetQuery->first();

            if ($target === null || ! $target->is_active) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('level', $this->sourceLevelsForTarget($target))
                    ->where('code', '!=', $target->code);
                if ($target->type !== 'multi_level') {
                    $query->where('type', 'single_level');
                }
            }
        }

        if (filled($filters['q'] ?? null)) {
            $search = trim((string) $filters['q']);
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        return $query
            ->orderBy('province_code')
            ->orderBy('commune_code')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(min(max((int) ($filters['per_page'] ?? 30), 1), 100))
            ->withQueryString();
    }

    public function merge(User $user, array $sourceCodes, string $targetCode, ?string $decisionNumber = null): int
    {
        $sourceCodes = array_values(array_unique($sourceCodes));
        if ($sourceCodes === [] || in_array($targetCode, $sourceCodes, true)) {
            throw ValidationException::withMessages([
                'source_truong_ids' => 'Chọn ít nhất một trường nguồn khác trường đích.',
            ]);
        }

        return DB::transaction(function () use ($user, $sourceCodes, $targetCode, $decisionNumber): int {
            $schools = School::query()
                ->whereIn('code', [...$sourceCodes, $targetCode])
                ->orderBy('code')
                ->lockForUpdate()
                ->get();
            $target = $schools->firstWhere('code', $targetCode);
            $sources = $schools->whereIn('code', $sourceCodes);

            if ($target === null || $sources->count() !== count($sourceCodes)) {
                throw ValidationException::withMessages([
                    'source_truong_ids' => 'Một hoặc nhiều trường đã thay đổi hoặc không còn hoạt động.',
                ]);
            }

            $this->assertCompatible($user, $target, $sources);
            $this->lockImportAreas($schools);
            $this->assertNoActiveImports($schools);

            $sourceCodes = $sources->pluck('code')->all();
            $sourceNames = $sources->pluck('name')->all();
            $educationCount = DB::table('person_educations')
                ->whereIn('school_code', $sourceCodes)
                ->count();

            $occupiedClasses = DB::table('person_educations')
                ->where('school_code', $target->code)
                ->whereNotNull('current_class')
                ->distinct()
                ->pluck('current_class')
                ->mapWithKeys(fn (string $class): array => [$this->normalizeClass($class) => true])
                ->all();

            foreach ($sources as $source) {
                $this->moveClasses($source, $occupiedClasses);
                DB::table('person_educations')
                    ->where('school_code', $source->code)
                    ->update(['school_code' => $target->code]);
            }

            SchoolMergeHistory::create([
                'province_code' => $target->province_code,
                'commune_code' => $target->commune_code,
                'source_codes' => $sourceCodes,
                'source_names' => $sourceNames,
                'target_code' => $target->code,
                'target_name' => $target->name,
                'qd_sap_nhap' => $decisionNumber,
                'merged_by' => $user->id,
            ]);

            return $educationCount;
        });
    }

    private function moveClasses(School $source, array &$occupiedClasses): void
    {
        $classes = DB::table('person_educations')
            ->where('school_code', $source->code)
            ->whereNotNull('current_class')
            ->distinct()
            ->pluck('current_class');

        foreach ($classes as $class) {
            $baseName = $class.'_'.$source->code;
            $renamed = $baseName;
            $suffix = 2;
            while (isset($occupiedClasses[$this->normalizeClass($renamed)])) {
                $renamed = $baseName.'_'.$suffix;
                $suffix++;
            }
            if (mb_strlen($renamed) > 50) {
                throw ValidationException::withMessages([
                    'source_truong_ids' => "Không thể chuẩn hóa tên lớp {$class} vì vượt quá 50 ký tự.",
                ]);
            }

            DB::table('person_educations')
                ->where('school_code', $source->code)
                ->where('current_class', $class)
                ->update(['current_class' => $renamed]);

            $occupiedClasses[$this->normalizeClass($renamed)] = true;
        }
    }

    private function assertCompatible(User $user, School $target, Collection $sources): void
    {
        if (! $target->is_active) {
            throw ValidationException::withMessages([
                'target_truong_id' => 'Trường đích phải đang hoạt động.',
            ]);
        }

        foreach ($sources as $source) {
            if ($source->is_active) {
                throw ValidationException::withMessages([
                    'source_truong_ids' => 'Phải ngừng hoạt động tất cả trường nguồn trước khi gộp.',
                ]);
            }
        }

        $schools = collect([$target])->concat($sources);
        $allowedLevels = $this->levelsForUser($user);

        foreach ($schools as $school) {
            $this->assertScope($user, $school);
            if (blank($school->province_code)) {
                throw ValidationException::withMessages([
                    'source_truong_ids' => 'Trường phải được gán tỉnh trước khi gộp.',
                ]);
            }
            if (
                $school->province_code !== $target->province_code
            ) {
                throw ValidationException::withMessages([
                    'source_truong_ids' => 'Các trường phải thuộc cùng tỉnh.',
                ]);
            }

            if (! in_array($school->level, $allowedLevels, true)) {
                throw ValidationException::withMessages([
                    'source_truong_ids' => 'Cấp quản lý hiện tại không được phép gộp loại trường này.',
                ]);
            }

            if (($school->level === 'MN') !== ($target->level === 'MN')) {
                throw ValidationException::withMessages([
                    'source_truong_ids' => 'Trường Mầm non chỉ được gộp với trường Mầm non; các cấp phổ thông không gộp với Mầm non.',
                ]);
            }

            if (! in_array($school->level, $this->sourceLevelsForTarget($target), true)) {
                throw ValidationException::withMessages([
                    'source_truong_ids' => $target->type === 'multi_level'
                        ? 'Trường đích liên cấp chỉ nhận trường cùng cấp hoặc cấp phổ thông thấp hơn.'
                        : 'Trường đích một cấp chỉ nhận trường nguồn cùng cấp.',
                ]);
            }

            if ($target->type !== 'multi_level' && $school->type !== 'single_level') {
                throw ValidationException::withMessages([
                    'source_truong_ids' => 'Trường đích một cấp chỉ nhận trường nguồn một cấp cùng cấp.',
                ]);
            }

            if (
                $target->level !== 'THPT'
                && $school->level !== 'THPT'
                && (blank($target->commune_code)
                    || $school->commune_code !== $target->commune_code)
            ) {
                throw ValidationException::withMessages([
                    'source_truong_ids' => 'Trường Mầm non, Tiểu học và THCS chỉ được gộp trong cùng một xã; trường THPT được gộp trong cùng tỉnh.',
                ]);
            }
        }
    }

    private function assertNoActiveImports(Collection $schools): void
    {
        [$communeCodes, $provinceCodes] = $this->importAreas($schools);

        $villageCodes = $communeCodes->isEmpty()
            ? collect()
            : DB::table('villages')
                ->whereIn('commune_code', $communeCodes)
                ->pluck('code');
        $schoolCodes = $schools->pluck('code')->all();

        $activeImportExists = ImportLog::query()
            ->whereIn('status', ['PENDING', 'PROCESSING'])
            ->where(function (Builder $query) use ($communeCodes, $villageCodes, $provinceCodes, $schoolCodes): void {
                if ($communeCodes->isNotEmpty()) {
                    $query->whereIn('context->commune_code', $communeCodes);
                }
                if ($villageCodes->isNotEmpty()) {
                    $query->orWhereIn('context->village_code', $villageCodes);
                }
                if ($provinceCodes->isNotEmpty()) {
                    $query->orWhereIn('context->province_code', $provinceCodes);
                }
                $query->orWhereExists(function (QueryBuilder $educationQuery) use ($schoolCodes): void {
                    $educationQuery->selectRaw('1')
                        ->from('person_educations')
                        ->whereColumn('person_educations.import_log_id', 'import_logs.id')
                        ->whereIn('person_educations.school_code', $schoolCodes);
                });
            })
            ->exists();

        if ($activeImportExists) {
            throw ValidationException::withMessages([
                'source_truong_ids' => 'Không thể gộp khi còn tệp import đang chờ hoặc xử lý tại địa bàn của các trường đã chọn.',
            ]);
        }
    }

    private function lockImportAreas(Collection $schools): void
    {
        [$communeCodes] = $this->importAreas($schools);
        if ($communeCodes->isEmpty()) {
            return;
        }

        DB::table('communes')
            ->whereIn('code', $communeCodes)
            ->orderBy('code')
            ->lockForUpdate()
            ->get(['code']);
    }

    private function importAreas(Collection $schools): array
    {
        $communeCodes = $schools->pluck('commune_code')->filter()->unique()->values();
        $provinceCodes = $schools
            ->filter(fn (School $school): bool => blank($school->commune_code))
            ->pluck('province_code')
            ->filter()
            ->unique()
            ->values();

        if ($provinceCodes->isNotEmpty()) {
            $communeCodes = $communeCodes
                ->merge(DB::table('communes')
                    ->whereIn('province_code', $provinceCodes)
                    ->pluck('code'))
                ->unique()
                ->values();
        }

        return [$communeCodes, $provinceCodes];
    }

    private function assertScope(User $user, School $school): void
    {
        if ($user->hasRole(Role::SYSTEM_ADMIN)) {
            return;
        }

        $allowed = $user->roleScopeLevel() === 'global'
            || (in_array($user->roleScopeLevel(), ['province', 'commune'], true)
                && $user->operatesInArea($school->province_code, $school->commune_code));

        abort_unless($allowed, 403);
    }

    private function constrainToUserArea(Builder $query, User $user): void
    {
        if ($user->hasRole(Role::SYSTEM_ADMIN)) {
            return;
        }

        match ($user->roleScopeLevel()) {
            'global' => null,
            'province' => $query->whereIn('province_code', $user->operatingProvinceCodes()),
            'commune' => $query->whereIn('commune_code', $user->operatingCommuneCodes()),
            default => $query->whereRaw('1 = 0'),
        };
    }

    private function levelsForUser(User $user): array
    {
        return match ($user->roleScopeLevel()) {
            'commune' => ['MN', 'TH', 'THCS'],
            'province', 'global' => ['MN', 'TH', 'THCS', 'THPT'],
            default => [],
        };
    }

    private function sourceLevelsForTarget(School $target): array
    {
        if ($target->level === 'MN') {
            return ['MN'];
        }

        if ($target->type !== 'multi_level') {
            return [$target->level];
        }

        return match ($target->level) {
            'TH' => ['TH'],
            'THCS' => ['TH', 'THCS'],
            'THPT' => ['TH', 'THCS', 'THPT'],
            default => [$target->level],
        };
    }

    private function normalizeClass(string $class): string
    {
        return mb_strtolower(trim($class));
    }
}
