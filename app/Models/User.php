<?php

namespace App\Models;

use App\Support\AccessPermission;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;

class User extends Authenticatable
{
    use HasFactory;

    public const TEMPORARY_PASSWORD = 'Huukhang2k4#';

    protected $fillable = [
        'name',
        'username',
        'phone',
        'citizen_id',
        'date_of_birth',
        'email',
        'password',
    ];

    // ko cho hiện khi trả về json
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'date_of_birth' => 'date',
            'password' => 'hashed',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class, 'id', 'role_id');
    }

    public function assignRole(Role $role, ?int $assignedBy = null): void
    {
        $this->role()->associate($role);
        $this->role_assigned_by = $assignedBy;
        $this->save();
        $this->unsetRelation('roles');
    }

    public function removeRole(Role $role): void
    {
        if ((int) $this->role_id !== (int) $role->id) {
            return;
        }

        $this->role()->dissociate();
        $this->role_assigned_by = null;
        $this->save();
        $this->unsetRelation('roles');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function operatingAreas(): HasMany
    {
        return $this->hasMany(UserOperatingArea::class);
    }

    public function villages(): BelongsToMany
    {
        return $this->belongsToMany(
            Village::class,
            'user_operating_areas',
            'user_id',
            'village_code',
            'id',
            'code',
        );
    }

    public function province(): HasOneThrough
    {
        return $this->hasOneThrough(
            Province::class,
            UserOperatingArea::class,
            'user_id',
            'code',
            'id',
            'province_code',
        );
    }

    public function commune(): HasOneThrough
    {
        return $this->hasOneThrough(
            Commune::class,
            UserOperatingArea::class,
            'user_id',
            'code',
            'id',
            'commune_code',
        );
    }

    public function getProvinceCodeAttribute(): ?string
    {
        if (array_key_exists('province_code', $this->attributes)) {
            return $this->attributes['province_code'];
        }

        return $this->firstOperatingArea()?->province_code;
    }

    public function getCommuneCodeAttribute(): ?string
    {
        if (array_key_exists('commune_code', $this->attributes)) {
            return $this->attributes['commune_code'];
        }

        return $this->operatingAreas()
            ->whereNotNull('commune_code')
            ->orderBy('id')
            ->value('commune_code');
    }

    public function getScopeLevelAttribute(): ?string
    {
        if (isset($this->attributes['scope_level'])) {
            return $this->attributes['scope_level'];
        }

        return $this->role?->scope_level;
    }

    public function operatingProvinceCodes(): array
    {
        if (Schema::hasTable('user_operating_areas')) {
            return $this->operatingAreas()
                ->distinct()
                ->orderBy('province_code')
                ->pluck('province_code')
                ->all();
        }

        if (array_key_exists('province_code', $this->attributes)) {
            return $this->province_code === null ? [] : [(string) $this->province_code];
        }

        return $this->operatingAreas()
            ->distinct()
            ->orderBy('province_code')
            ->pluck('province_code')
            ->all();
    }

    public function operatingCommuneCodes(?string $provinceCode = null): array
    {
        if (Schema::hasTable('user_operating_areas')) {
            $query = $this->operatingAreas()->whereNotNull('commune_code');
            if ($provinceCode !== null) {
                $query->where('province_code', $provinceCode);
            }

            return $query->distinct()->orderBy('commune_code')->pluck('commune_code')->all();
        }

        if (array_key_exists('commune_code', $this->attributes)) {
            return $this->commune_code === null ? [] : [(string) $this->commune_code];
        }

        $query = $this->operatingAreas()->whereNotNull('commune_code');
        if ($provinceCode !== null) {
            $query->where('province_code', $provinceCode);
        }

        return $query->distinct()->orderBy('commune_code')->pluck('commune_code')->all();
    }

    public function operatesInArea(string $provinceCode, ?string $communeCode = null, ?string $villageCode = null): bool
    {
        if (
            ! $this->exists
            && ! $this->relationLoaded('operatingAreas')
            && (
                array_key_exists('province_code', $this->attributes)
                || array_key_exists('commune_code', $this->attributes)
            )
        ) {
            $villageCodes = $this->managedVillageCodes();
            $storedProvinceCode = $this->attributes['province_code'] ?? null;
            $storedCommuneCode = $this->attributes['commune_code'] ?? null;

            return ($storedProvinceCode === null || $storedProvinceCode === $provinceCode)
                && ($storedCommuneCode === null || $storedCommuneCode === $communeCode)
                && ($villageCodes === [] || in_array($villageCode, $villageCodes, true));
        }

        if (Schema::hasTable('user_operating_areas')) {
            $this->loadMissing('operatingAreas');

            return $this->operatingAreas->contains(
                fn (UserOperatingArea $area): bool => $area->province_code === $provinceCode
                    && ($area->commune_code === null || $area->commune_code === $communeCode)
                    && ($villageCode === null || $area->village_code === null || $area->village_code === $villageCode)
            );
        }

        if (array_key_exists('province_code', $this->attributes)) {
            return $this->province_code === $provinceCode
                && ($this->commune_code === null || $this->commune_code === $communeCode);
        }

        $this->loadMissing('operatingAreas');

        return $this->operatingAreas->contains(
            fn (UserOperatingArea $area): bool => $area->province_code === $provinceCode
                && ($area->commune_code === null || $area->commune_code === $communeCode)
                && ($area->village_code === null || $area->village_code === $villageCode)
        );
    }

    public function coversProvince(string $provinceCode): bool
    {
        if (Schema::hasTable('user_operating_areas')) {
            return $this->operatingAreas()
                ->where('province_code', $provinceCode)
                ->whereNull('commune_code')
                ->whereNull('village_code')
                ->exists();
        }

        if (array_key_exists('province_code', $this->attributes)) {
            return $this->province_code === $provinceCode && $this->commune_code === null;
        }

        return $this->operatingAreas()
            ->where('province_code', $provinceCode)
            ->whereNull('commune_code')
            ->whereNull('village_code')
            ->exists();
    }

    // lấy thôn ng được phân công
    public function managedVillageCodes(): array
    {
        if (
            $this->hasPermissionTo(AccessPermission::MANAGE_VILLAGES)
            || ! collect(AccessPermission::villageScopedPermissions())
                ->contains(fn(string $permission): bool => $this->hasPermissionTo($permission))
        ) {
            return [];
        }

        if ($this->relationLoaded('villages')) {
            return $this->villages->pluck('code')->all();
        }

        return $this->villages()->pluck('villages.code')->all();
    }

    // check role
    public function hasRole(string $name): bool
    {
        if ($this->relationLoaded('roles')) {
            return $this->roles->contains(fn (Role $role): bool => $role->name === $name
                && $role->guard_name === config('auth.permission_guard', 'web'));
        }

        $role = $this->assignedRole();

        return $role !== null
            && $role->name === $name
            && $role->guard_name === config('auth.permission_guard', 'web');
    }

    // check xem đủ điều kiện hợp lệ để thực hiện quyền ko
    public function hasPermissionTo(string $name): bool
    {
        // role scope level là global , province , .... quyền về phạm vi
        $scopeLevel = $this->roleScopeLevel();
        if (
            $scopeLevel === 'global'
            && in_array($name, AccessPermission::mergePermissions(), true)
        ) {
            return false;
        }

        // bắt buộc phải đúng scope theo các từ
        if (
            ! $this->hasRole(Role::SYSTEM_ADMIN)
            && (
                ! in_array($scopeLevel, ['global', 'province', 'commune'], true)
                || ! $this->permissionIsAllowedAtScope($name, $scopeLevel)
            )
        ) {
            return false;
        }
        if (
            $name === AccessPermission::MANAGE_SCHOOLS
            && ! $this->hasRole(Role::SYSTEM_ADMIN)
            && ! in_array($scopeLevel, ['global', 'province', 'commune'], true)
        ) {
            return false;
        }
        // Kiểm tra tiếp quyền quản lý danh mục theo quyền và địa bàn được ủy quyền.
        if ($name === AccessPermission::MANAGE_VILLAGES || $name === AccessPermission::MANAGE_SCHOOLS) {
            return $this->hasPermissionWithinDelegation($name, []);
        }

        foreach (AccessPermission::impliedPermissions($name) as $implyingPermission) {
            if ($this->hasPermissionWithinDelegation($implyingPermission, [])) {
                return true;
            }
        }

        return $this->hasPermissionWithinDelegation($name, []);
    }

    // lấy danh sách các role hợp lệ
    public function permissionNames(): array
    {
        $this->loadMissing('role.creator');
        $role = $this->role;
        $rolePermissions = $role !== null
            && $role->guard_name === config('auth.permission_guard', 'web')
                ? collect($role->permission_codes ?? [])
                    ->filter(fn (string $permission): bool => $this->hasPermissionTo($permission))
                    ->all()
                : [];

        $permissions = AccessPermission::withPrerequisites(
            AccessPermission::expandLegacyPermissions(array_values(array_unique($rolePermissions)))
        );
        // Admin trung ương không quản lý danh mục trường hoặc gộp danh mục.
        if ($this->roleScopeLevel() === 'global') {
            $permissions = array_diff($permissions, [
                AccessPermission::MANAGE_SCHOOLS,
                AccessPermission::VIEW_SCHOOLS,
                AccessPermission::CREATE_SCHOOLS,
                AccessPermission::UPDATE_SCHOOLS,
                AccessPermission::DELETE_SCHOOLS,
                ...AccessPermission::mergePermissions(),
            ]);
        }

        return array_values($permissions);
    }

    // trả về scope của user
    public function roleScopeLevel(): ?string
    {
        if ($this->hasRole(Role::SYSTEM_ADMIN)) {
            return 'global';
        }
        $scopeLevel = $this->attributes['scope_level'] ?? null;
        if (in_array($scopeLevel, ['global', 'province', 'commune'], true)) {
            return $scopeLevel;
        }
        if ($this->relationLoaded('roles')) {
            $levels = $this->roles
                ->where('guard_name', config('auth.permission_guard', 'web'))
                ->pluck('scope_level')
                ->unique()
                ->values();

            return $levels->count() === 1 ? $levels->first() : null;
        }

        $role = $this->assignedRole();

        return $role?->guard_name === config('auth.permission_guard', 'web')
            ? $role->scope_level
            : null;
    }

    public function canViewDashboard(): bool
    {
        if ($this->hasRole(Role::SYSTEM_ADMIN)) {
            return true;
        }

        $scopeLevel = $this->roleScopeLevel();
        $adminRoleNames = match ($scopeLevel) {
            'province' => Role::PROVINCE_ADMIN_NAMES,
            'commune' => Role::COMMUNE_ADMIN_NAMES,
            default => [],
        };

        return $adminRoleNames !== [] && $this->roles()
            ->where('roles.guard_name', config('auth.permission_guard', 'web'))
            ->where('roles.scope_level', $scopeLevel)
            ->whereIn('roles.name', $adminRoleNames)
            ->exists();
    }

    public function isAccountAdministrator(): bool
    {
        if ($this->hasRole(Role::SYSTEM_ADMIN)) {
            return true;
        }

        $role = $this->relationLoaded('role') ? $this->role : $this->assignedRole();

        return $role?->isSystemAccountAdministrator() ?? false;
    }

    // check xem đủ quyền để quản lí cấp dưới không
    public function canManageSubordinateUsers(): bool
    {
        return $this->hasPermissionTo(AccessPermission::MANAGE_USER_ACCOUNTS);
    }

    public function canManageAccountGroups(): bool
    {
        return $this->hasPermissionTo(AccessPermission::MANAGE_ACCESS_CONTROL);
    }

    // kiểm tra quyền qua chuỗi ủy quyền có chính xác k
    private function hasPermissionWithinDelegation(string $name, array $visited): bool
    {
        if ($this->hasRole(Role::SYSTEM_ADMIN)) {
            return true;
        }

        $scopeLevel = $this->roleScopeLevel();
        if (
            ! in_array($scopeLevel, ['global', 'province', 'commune'], true)
            || ! $this->permissionIsAllowedAtScope($name, $scopeLevel)
        ) {
            return false;
        }

        if (in_array($this->id, $visited, true)) {
            return false;
        }
        $visited[] = $this->id;

        $this->loadMissing('role.creator');
        $rolePermissions = AccessPermission::permissionSources($name);
        $role = $this->role;
        if (
            $role === null
            || $role->guard_name !== config('auth.permission_guard', 'web')
            || $role->scope_level !== $scopeLevel
            || array_intersect($rolePermissions, $role->permission_codes ?? []) === []
        ) {
            return false;
        }

        $creatorAllowed = $role->creator === null
            || $role->creator->canDelegateRolePermission($name, $role, $visited);
        $assignerId = $this->role_assigned_by;
        $assigner = $assignerId === null
            ? null
            : ($role->creator?->id === $assignerId ? $role->creator : static::find($assignerId));
        $assignerAllowed = $assignerId === null
            || ($assigner !== null && $assigner->canDelegateRolePermission($name, $role, $visited));

        return $creatorAllowed && $assignerAllowed;
    }

    // xác định ông có đủ role để tạo tk cho người khác ko
    private function canDelegateRolePermission(string $name, Role $role, array $visited): bool
    {
        if ($this->hasPermissionWithinDelegation($name, $visited)) {
            return true;
        }

        return $role->scope_level !== 'global'
            && in_array($name, AccessPermission::permissionsForScope($role->scope_level), true)
            && $this->hasPermissionWithinDelegation(AccessPermission::MANAGE_ACCESS_CONTROL, $visited);
    }

    // kiểm tra quyền có phù hợp với cấp bậc không
    private function permissionIsAllowedAtScope(string $name, string $scopeLevel): bool
    {
        return in_array($name, AccessPermission::permissionsForScope($scopeLevel), true);
    }

    private function assignedRole(): ?Role
    {
        if ($this->relationLoaded('role')) {
            return $this->getRelation('role');
        }
        if ($this->relationLoaded('roles')) {
            return $this->roles->count() === 1 ? $this->roles->first() : null;
        }

        $this->loadMissing('role');

        return $this->role;
    }

    private function firstOperatingArea(): ?UserOperatingArea
    {
        if ($this->relationLoaded('operatingAreas')) {
            return $this->operatingAreas->first();
        }

        return $this->operatingAreas()->orderBy('id')->first();
    }
}
