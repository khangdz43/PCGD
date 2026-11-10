<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    public const SYSTEM_ADMIN = 'system-admin';

    protected $appends = ['permissions'];

    protected $fillable = [
        'name',
        'guard_name',
        'scope_level',
        'description',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'permission_codes' => 'array',
        ];
    }

    public function getPermissionsAttribute(): \Illuminate\Database\Eloquent\Collection
    {
        if ($this->relationLoaded('permissions')) {
            return $this->getRelation('permissions');
        }

        $codes = $this->permission_codes ?? [];
        $permissions = $codes === []
            ? new \Illuminate\Database\Eloquent\Collection
            : Permission::query()
                ->where('guard_name', $this->guard_name)
                ->whereIn('name', $codes)
                ->get();

        $this->setRelation('permissions', $permissions);

        return $permissions;
    }

    public function syncPermissions(iterable $permissions): void
    {
        $items = collect($permissions);
        $codes = $items
            ->filter(fn ($permission): bool => $permission instanceof Permission)
            ->map(fn (Permission $permission): string => $permission->name);
        $ids = $items
            ->filter(fn ($permission): bool => is_int($permission) || (is_string($permission) && ctype_digit($permission)))
            ->map(fn ($permission): int => (int) $permission)
            ->unique()
            ->all();

        if ($ids !== []) {
            $codes = $codes->merge(
                Permission::query()
                    ->where('guard_name', $this->guard_name)
                    ->whereIn('id', $ids)
                    ->pluck('name')
            );
        }

        $codes = $codes
            ->merge($items->filter(fn ($permission): bool => is_string($permission) && ! ctype_digit($permission)))
            ->filter(fn ($code): bool => is_string($code) && $code !== '')
            ->unique()
            ->values()
            ->all();

        $this->forceFill(['permission_codes' => $codes])->save();
        $this->unsetRelation('permissions');
    }

    public function attachPermissions(mixed $permissions): void
    {
        $items = is_iterable($permissions) ? $permissions : [$permissions];
        $this->syncPermissions([
            ...($this->permission_codes ?? []),
            ...collect($items)->all(),
        ]);
    }

    public function detachPermissions(mixed $permissions): void
    {
        $items = is_iterable($permissions) ? collect($permissions) : collect([$permissions]);
        $permissionIds = $items
            ->filter(fn ($permission): bool => is_int($permission) || (is_string($permission) && ctype_digit($permission)))
            ->map(fn ($permission): int => (int) $permission)
            ->all();
        $permissionNames = $items
            ->filter(fn ($permission): bool => $permission instanceof Permission)
            ->map(fn (Permission $permission): string => $permission->name)
            ->merge($permissionIds === []
                ? []
                : Permission::query()
                    ->where('guard_name', $this->guard_name)
                    ->whereIn('id', $permissionIds)
                    ->pluck('name'))
            ->merge($items->filter(fn ($permission): bool => is_string($permission) && ! ctype_digit($permission)))
            ->all();

        $this->syncPermissions(array_values(array_diff($this->permission_codes ?? [], $permissionNames)));
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
