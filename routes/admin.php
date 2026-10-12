<?php

use App\Http\Controllers\Admin\AccessControl\RoleController;
use App\Http\Controllers\Admin\AccessControl\UserRoleController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('admin', function (Request $request) {
    return $request->user()->can('manage-access-control')
        ? to_route('admin.roles.index')
        : to_route('admin.users.index');
})
    ->middleware(['auth', 'active'])
    ->name('admin.index');

Route::prefix('admin')
    ->name('admin.')
    // chỉ cho đã đăng nhập và active
    ->middleware(['auth', 'active', 'can:manage-access-control'])
    ->group(function (): void {
        Route::get('roles/{role}/members', [RoleController::class, 'members'])->name('roles.members');
        Route::resource('roles', RoleController::class)->except('show');
    });

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'active', 'can:manage-user-accounts'])
    ->group(function (): void {
        Route::patch('users/{user}/status', [UserRoleController::class, 'updateStatus'])->name('users.status');
        Route::resource('users', UserRoleController::class)->except('show', 'destroy');
    });
