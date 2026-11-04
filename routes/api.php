<?php

use App\Http\Controllers\Admin\AccessControl\RoleController;
use App\Http\Controllers\Admin\AccessControl\UserRoleController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\HouseholdController;
use App\Http\Controllers\Education\SchoolMergeController;
use App\Http\Controllers\Education\SchoolController;
use App\Http\Controllers\Education\MergeHistoryController;
use App\Http\Controllers\Geography\CommuneController;
use App\Http\Controllers\Geography\ProvinceController;
use App\Http\Controllers\Geography\VillageController;
use App\Http\Controllers\Geography\VillageMergeController;
use App\Http\Controllers\Households\HouseholdController as HouseholdManagementController;
use App\Http\Controllers\Households\HouseholdImportExportController;
use App\Http\Controllers\Households\HouseholdImportHistoryController;
use App\Http\Controllers\Households\HouseholdImportNotificationController;
use App\Http\Controllers\Households\HouseholdImportStatusController;
use App\Http\Controllers\Households\HouseholdPersonController;
use App\Http\Controllers\Households\HouseholdTrashController;
use App\Http\Controllers\Profile\AccountController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function (): void {
    Route::prefix('v1')->name('api.')->group(function (): void {
        Route::get('/csrf-cookie', fn() => response()->noContent())->name('csrf-cookie');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login');

        Route::middleware(['auth', 'active'])->group(function (): void {
            Route::get('/session', [AuthController::class, 'show'])->name('session');
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

            Route::get('/dashboard', [ProvinceController::class, 'home'])->name('dashboard');
            Route::get('/dashboard/overview', [DashboardController::class, 'overview'])->name('dashboard.overview');
            Route::get('/account', [AccountController::class, 'show'])->name('account.show');
            Route::patch('/account', [AccountController::class, 'update'])->name('account.update');
            Route::patch('/account/password', [AccountController::class, 'updatePassword'])->name('account.password');

            Route::get('/provinces', [ProvinceController::class, 'index'])->name('provinces.index');
            Route::get('/provinces/{provinceCode}/communes', [ProvinceController::class, 'getCommunes'])->name('provinces.communes.index');
            Route::get('/communes/{communeCode}/villages', [CommuneController::class, 'getVillages'])->name('communes.villages.index');
            Route::get('/provinces/{provinceCode}/schools', [SchoolController::class, 'getByProvince'])->name('provinces.schools.index');

            Route::middleware('can:view-households')->group(function (): void {
                Route::get('/households', [HouseholdController::class, 'index'])->name('households.index');
                Route::get('/households/{household}', [HouseholdController::class, 'show'])->whereNumber('household')->name('households.show');
                Route::get('/households/{household}/persons', [HouseholdPersonController::class, 'index'])->whereNumber('household')->name('households.persons.index');
                Route::get('/households/{household}/persons/{personId}', [HouseholdPersonController::class, 'show'])->whereNumber(['household', 'personId'])->name('households.persons.show');
            });

            Route::middleware('can:create-households')->group(function (): void {
                Route::get('/households/form', [HouseholdManagementController::class, 'create'])->name('households.create');
                Route::post('/households', [HouseholdManagementController::class, 'store'])->name('households.store');
                Route::get('/households/{household}/persons/form', [HouseholdPersonController::class, 'create'])->whereNumber('household')->name('households.persons.create');
                Route::post('/households/{household}/persons', [HouseholdPersonController::class, 'store'])->name('households.persons.store');
            });
            Route::middleware('can:update-households')->group(function (): void {
                Route::put('/households/{household}', [HouseholdManagementController::class, 'update'])->whereNumber('household')->name('households.update');
                Route::get('/households/{household}/form', [HouseholdManagementController::class, 'edit'])->whereNumber('household')->name('households.edit');
                Route::get('/households/{household}/persons/{personId}/form', [HouseholdPersonController::class, 'edit'])->whereNumber(['household', 'personId'])->name('households.persons.edit');
                Route::put('/households/{household}/persons/{personId}', [HouseholdPersonController::class, 'update'])->whereNumber(['household', 'personId'])->name('households.persons.update');
            });
            Route::middleware('can:delete-households')->group(function (): void {
                Route::delete('/households', [HouseholdManagementController::class, 'bulkDestroy'])->name('households.destroy-many');
                Route::delete('/households/{household}', [HouseholdManagementController::class, 'destroy'])->whereNumber('household')->name('households.destroy');
                Route::delete('/households/persons', [HouseholdPersonController::class, 'bulkDestroy'])->name('households.persons.destroy-many');
                Route::delete('/households/{household}/persons/{personId}', [HouseholdPersonController::class, 'destroy'])->whereNumber(['household', 'personId'])->name('households.persons.destroy');
            });
            Route::middleware('can:view-trash')->group(function (): void {
                Route::get('/trash', [HouseholdTrashController::class, 'index'])->name('trash.index');
                Route::get('/trash/households/{id}', [HouseholdTrashController::class, 'showHousehold'])->whereNumber('id')->name('trash.households.show');
            });
            Route::post('/trash/batch-operations', [HouseholdTrashController::class, 'bulk'])->name('trash.batch-operations.store');
            Route::middleware('can:update-trash')->group(function (): void {
                Route::patch('/trash/households/{id}', [HouseholdTrashController::class, 'restoreHousehold'])->whereNumber('id')->name('trash.households.restore');
                Route::patch('/trash/persons/{id}', [HouseholdTrashController::class, 'restorePerson'])->whereNumber('id')->name('trash.persons.restore');
            });
            Route::middleware('can:delete-trash')->group(function (): void {
                Route::delete('/trash/households/{id}', [HouseholdTrashController::class, 'permanentlyDeleteHousehold'])->whereNumber('id')->name('trash.households.destroy');
                Route::delete('/trash/persons/{id}', [HouseholdTrashController::class, 'permanentlyDeletePerson'])->whereNumber('id')->name('trash.persons.destroy');
            });

            Route::middleware('can:view-villages')->group(function (): void {
                Route::get('/villages', [VillageController::class, 'index'])->name('villages.index');
            });
            Route::middleware('can:create-villages')->group(function (): void {
                Route::get('/villages/form', [VillageController::class, 'create'])->name('villages.create');
                Route::post('/villages', [VillageController::class, 'store'])->name('villages.store');
            });
            Route::middleware('can:view-villages')->group(function (): void {
                Route::get('/villages/{code}', [VillageController::class, 'show'])->name('villages.show');
            });
            Route::middleware('can:update-villages')->group(function (): void {
                Route::get('/villages/{code}/form', [VillageController::class, 'edit'])->name('villages.edit');
                Route::put('/villages/{code}', [VillageController::class, 'update'])->name('villages.update');
            });
            Route::middleware('can:delete-villages')->group(function (): void {
                Route::delete('/villages/{code}', [VillageController::class, 'destroy'])->name('villages.destroy');
            });
            Route::middleware('can:view-village-merges')->group(function (): void {
                Route::get('/village-merges', [VillageMergeController::class, 'index'])->name('village-merges.index');
            });
            Route::middleware('can:create-village-merges')->group(function (): void {
                Route::post('/village-merges/pause', [VillageMergeController::class, 'pause'])->name('village-merges.pause');
                Route::post('/village-merges/resume', [VillageMergeController::class, 'resume'])->name('village-merges.resume');
                Route::post('/village-merges', [VillageMergeController::class, 'store'])->name('village-merges.store');
            });

            Route::middleware('can:view-schools')->group(function (): void {
                Route::get('/schools', [SchoolController::class, 'index'])->name('schools.index');
                Route::get('/schools/{id}', [SchoolController::class, 'show'])->whereNumber('id')->name('schools.show');
            });
            Route::middleware('can:create-schools')->group(function (): void {
                Route::get('/schools/form', [SchoolController::class, 'create'])->name('schools.create');
                Route::post('/schools', [SchoolController::class, 'store'])->name('schools.store');
            });
            Route::middleware('can:update-schools')->group(function (): void {
                Route::get('/schools/{id}/form', [SchoolController::class, 'edit'])->whereNumber('id')->name('schools.edit');
                Route::put('/schools/{id}', [SchoolController::class, 'update'])->whereNumber('id')->name('schools.update');
            });
            Route::middleware('can:delete-schools')->group(function (): void {
                Route::delete('/schools/{id}', [SchoolController::class, 'destroy'])->whereNumber('id')->name('schools.destroy');
            });
            Route::middleware('can:view-school-merges')->group(function (): void {
                Route::get('/school-merges', [SchoolMergeController::class, 'index'])->name('school-merges.index');
            });
            Route::middleware('can:create-school-merges')->group(function (): void {
                Route::post('/school-merges', [SchoolMergeController::class, 'store'])->name('school-merges.store');
            });
            Route::get('/merge-histories', [MergeHistoryController::class, 'index'])->name('merge-histories.index');

            Route::middleware('can:create-exports')->group(function (): void {
                Route::get('/household-exports', [HouseholdImportExportController::class, 'export'])->name('household-exports.index');
            });
            Route::middleware('can:view-imports')->group(function (): void {
                Route::get('/household-imports', HouseholdImportHistoryController::class)->name('households.import-history');
                Route::get('/household-imports/statuses', [HouseholdImportStatusController::class, 'batch'])->name('households.import.statuses');
            });
            Route::middleware('can:create-imports')->group(function (): void {
                Route::get('/household-imports/form', [HouseholdImportExportController::class, 'create'])->name('household-imports.create');
                Route::get('/household-imports/template', [HouseholdImportExportController::class, 'template'])->name('household-imports.template');
                Route::post('/household-imports', [HouseholdImportExportController::class, 'import'])->name('household-imports.store');
                Route::get('/household-import-notifications', [HouseholdImportNotificationController::class, 'index'])->name('household-import-notifications');
                Route::patch('/household-import-notifications/read', [HouseholdImportNotificationController::class, 'acknowledge'])->name('household-import-notifications.read');
            });
            Route::middleware('can:view-imports')->group(function (): void {
                Route::get('/household-imports/{importLog}', HouseholdImportStatusController::class)->name('households.import.status');
                Route::get('/household-imports/{importLog}/file', [HouseholdImportHistoryController::class, 'download'])->name('household-imports.file');
                Route::get('/household-imports/{importLog}/error-file', [HouseholdImportHistoryController::class, 'downloadErrors'])->name('household-imports.error-file');
            });
            Route::middleware('can:delete-imports')->group(function (): void {
                Route::delete('/household-imports/{importLog}', [HouseholdImportHistoryController::class, 'destroy'])->name('household-imports.destroy');
            });

            Route::middleware('can:create-roles')->group(function (): void {
                Route::get('/roles/create', [RoleController::class, 'create'])->name('roles.create');
                Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
            });
            Route::middleware('can:view-roles')->group(function (): void {
                Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
                Route::get('/roles/{role}/users', [RoleController::class, 'members'])->name('roles.users.index');
                Route::get('/roles/{role}', [RoleController::class, 'show'])->name('roles.show');
            });
            Route::middleware('can:update-roles')->group(function (): void {
                Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
                Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
            });
            Route::middleware('can:delete-roles')->group(function (): void {
                Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
            });
            Route::middleware('can:create-users')->group(function (): void {
                Route::get('/users/create', [UserRoleController::class, 'create'])->name('users.create');
                Route::post('/users', [UserRoleController::class, 'store'])->name('users.store');
            });
            Route::middleware('can:view-users')->group(function (): void {
                Route::get('/users', [UserRoleController::class, 'index'])->name('users.index');
                Route::get('/users/{user}', [UserRoleController::class, 'show'])->name('users.show');
            });
            Route::middleware('can:update-users')->group(function (): void {
                Route::get('/users/{user}/edit', [UserRoleController::class, 'edit'])->name('users.edit');
                Route::put('/users/{user}', [UserRoleController::class, 'update'])->name('users.update');
                Route::post('/users/{user}/password-resets', [UserRoleController::class, 'resetPassword'])->name('users.password-resets.store');
            });
            Route::patch('/users/{user}/status', [UserRoleController::class, 'updateStatus'])->name('users.status.update');
        });
    });
});
