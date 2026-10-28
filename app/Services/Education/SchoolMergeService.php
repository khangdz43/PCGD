<?php

namespace App\Services\Education;

use App\Models\Role;
use App\Models\School;
use App\Models\SchoolMergeHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SchoolMergeService
{
    public function availableForMerge(User $user): Collection
    {
        $query = School::query()->withCount('educations');
        $this->constrainToUserArea($query, $user);

        return $query->orderBy('province_code')->orderBy('commune_code')->orderBy('name')->get();
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

            foreach ($sources as $source) {
                $source->delete();
            }

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
            $normalized = $this->normalizeClass($class);
            if (isset($occupiedClasses[$normalized])) {
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
                $normalized = $this->normalizeClass($renamed);
            }

            $occupiedClasses[$normalized] = true;
        }
    }

    private function assertCompatible(User $user, School $target, Collection $sources): void
    {
        $schools = collect([$target])->concat($sources);
        foreach ($schools as $school) {
            $this->assertScope($user, $school);
            if (
                $school->province_code !== $target->province_code
                || $school->level !== $target->level
            ) {
                throw ValidationException::withMessages([
                    'source_truong_ids' => 'Chỉ được gộp trường cùng tỉnh và cùng cấp học.',
                ]);
            }

            if (
                $user->roleScopeLevel() === 'commune'
                && $school->commune_code !== $target->commune_code
            ) {
                throw ValidationException::withMessages([
                    'source_truong_ids' => 'Các trường phải thuộc cùng xã trong phạm vi quản lý.',
                ]);
            }
        }

        $allowedLevels = match ($user->roleScopeLevel()) {
            'commune' => ['MN', 'TH', 'THCS'],
            'province' => ['THPT', 'GDTX'],
            default => ['MN', 'TH', 'THCS', 'THPT', 'GDTX'],
        };
        if (! in_array($target->level, $allowedLevels, true)) {
            throw ValidationException::withMessages([
                'source_truong_ids' => 'Cấp quản lý hiện tại không được phép gộp loại trường này.',
            ]);
        }
    }

    private function assertScope(User $user, School $school): void
    {
        if ($user->hasRole(Role::SYSTEM_ADMIN)) {
            return;
        }

        $allowed = match ($user->roleScopeLevel()) {
            'province' => $user->province_code !== null
                && $school->province_code === $user->province_code,
            'commune' => $user->province_code !== null
                && $user->commune_code !== null
                && $school->province_code === $user->province_code
                && $school->commune_code === $user->commune_code,
            default => false,
        };

        abort_unless($allowed, 403);
    }

    private function constrainToUserArea(Builder $query, User $user): void
    {
        if ($user->hasRole(Role::SYSTEM_ADMIN)) {
            return;
        }

        match ($user->roleScopeLevel()) {
            'province' => $query->where('province_code', $user->province_code),
            'commune' => $query->where('commune_code', $user->commune_code),
            default => $query->whereRaw('1 = 0'),
        };
    }

    private function normalizeClass(string $class): string
    {
        return mb_strtolower(trim($class));
    }
}
