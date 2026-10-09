<?php

namespace App\Providers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\AccessPermission;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    // đăng kí class , dependency như bên java
    public function register(): void
    {
        //
    }

    // chạy bean
    public function boot(): void
    {
        // Gate::before tự động chạy khi gặp @can , can: , authorize ....
        Gate::before(function (User $user, string $ability): ?bool {
            if ($user->hasRole(Role::SYSTEM_ADMIN)) {
                return true;
            }

            if (array_key_exists($ability, AccessPermission::definitions())) {
                return $user->hasPermissionTo($ability);
            }

            if (Permission::where('name', $ability)->where('guard_name', config('auth.defaults.guard', 'web'))->exists()) {
                return $user->hasPermissionTo($ability);
            }

            return null;
        });
    }
}
