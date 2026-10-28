<?php

namespace App\Models;

use App\Support\AccessPermission;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory;

    public const TEMPORARY_PASSWORD = 'Huukhang2k4#';

    protected $fillable = [
        'name',
        'username',
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
            'password' => 'hashed',
        ];
    }

    // ///
    public function roles(): MorphToMany
    {
        // 1 user có nhiều role , bảng trung gian modelhasroles ,
        // cột chứa id của modelhtai trong bảng trung gian
        // roleid là cột chứa role_id của model đó trong bảng trung gian
        // chuẩn bị query vào bảng role thông qua model_has_roles
        return $this->morphToMany(Role::class, 'model', 'model_has_roles', 'model_id', 'role_id')
            ->withPivot('assigned_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function villages(): BelongsToMany
    {
        return $this->belongsToMany(Village::class, 'user_villages', 'user_id', 'village_code', 'id', 'code');
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'province_code', 'code');
    }

    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class, 'commune_code', 'code');
    }
    // lấy thôn ng được phân công
    public function managedVillageCodes(): array
    {
        return $this->villages()->pluck('villages.code')->all();
    }

    // check role
    public function hasRole(string $name): bool
    {
        // lấy role của user này , load rồi thì không load lại nữa vì cái này lắm thằng query lắm
        $this->loadMissing('roles');

        return $this->roles->contains(fn(Role $role): bool => $role->name === $name
            && $role->guard_name === config('auth.permission_guard', 'web'));
    }

    // check xem đủ điều kiện hợp lệ để thực hiện quyền ko
    public function hasPermissionTo(string $name): bool
    {   
        // role scope level là global , province , .... quyền về phạm vi
        $scopeLevel = $this->roleScopeLevel();
        // bắt buộc phải đúng scope theo các từ
        if (
            ! $this->hasRole(Role::SYSTEM_ADMIN)
            && (
                ! in_array($scopeLevel, ['global', 'province', 'commune', 'village'], true)
                || ! $this->permissionIsAllowedAtScope($name, $scopeLevel)
            )
        ) {
            return false;
        }
        // tỉnh hoặc trung ương mới được quản lí trường học
        if (
            $name === AccessPermission::MANAGE_SCHOOLS
            && ! $this->hasRole(Role::SYSTEM_ADMIN)
            && ! in_array($scopeLevel, ['global', 'province'], true)
        ) {
            return false;
        }
        // pass case admin và tỉnh tức là admin trung ương và tỉnh có chức nangwqli thôn xóm trg học và xem hộ
        // quyền theo chức năng quản lí thôn xóm trường học
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
        $rolePermissions = $this->roles()
            ->where('roles.guard_name', config('auth.permission_guard', 'web'))
            ->with(['permissions', 'creator'])
            ->get()
            ->flatMap(fn(Role $role) => $role->permissions
                ->filter(fn($permission) => $this->hasPermissionTo($permission->name))
                ->pluck('name'))
            ->all();
        return AccessPermission::withPrerequisites(array_values(array_unique($rolePermissions)));
    }

    // trả về scope của user
    public function roleScopeLevel(): ?string
    {      // nếu người này có role admin
        if ($this->hasRole(Role::SYSTEM_ADMIN)) {
            return 'global';
        }
        if (in_array($this->scope_level, ['global', 'province', 'commune', 'village'], true)) {
            return $this->scope_level;
        }
        // lấy ra list scope_level của user này
        $this->loadMissing('roles');
        $levels = $this->roles
            ->where('guard_name', config('auth.permission_guard', 'web'))
            ->pluck('scope_level')
            ->unique()
            ->values();
        // nếu count != 1 trả về null , 1 user chỉ có 1 level thôi
        if ($levels->count() !== 1) {
            return $levels->isEmpty() && in_array($this->scope_level, ['global', 'province', 'commune', 'village'], true)
                ? $this->scope_level
                : null;
        }

        // return $levels->first() === 'school' ? 'village' : $levels->first();
        return $levels->first();
    }

    // check xem đủ quyền để quản lí cấp dưới không
    public function canManageSubordinateUsers(): bool
    {
        return $this->hasPermissionTo(AccessPermission::MANAGE_USER_ACCOUNTS);
    }

    // kiểm tra quyền qua chuỗi ủy quyền có chính xác k
    private function hasPermissionWithinDelegation(string $name, array $visited): bool
    {
        if ($this->hasRole(Role::SYSTEM_ADMIN)) {
            return true;
        }

        $scopeLevel = $this->roleScopeLevel();
        if (
            ! in_array($scopeLevel, ['global', 'province', 'commune', 'village'], true)
            || ! $this->permissionIsAllowedAtScope($name, $scopeLevel)
        ) {
            return false;
        }

        if (in_array($this->id, $visited, true)) {
            return false;
        }
        $visited[] = $this->id;

        $roles = $this->roles()
            ->where('roles.guard_name', config('auth.permission_guard', 'web'))
            ->where('roles.scope_level', $scopeLevel)
            ->whereHas('permissions', function ($query) use ($name): void {
                $query->where('permissions.name', $name)
                    ->where('permissions.guard_name', config('auth.permission_guard', 'web'));
            })
            ->with('creator')
            ->get();

        foreach ($roles as $role) {
            $creatorAllowed = $role->creator === null
                || $role->creator->canDelegateRolePermission($name, $role, $visited);
            $assignerId = $role->pivot->assigned_by;
            $assigner = $assignerId === null ? null : static::find($assignerId);
            $assignerAllowed = $assignerId === null
                || ($assigner !== null && $assigner->canDelegateRolePermission($name, $role, $visited));

            if ($creatorAllowed && $assignerAllowed) {
                return true;
            }
        }

        return false;
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
}
