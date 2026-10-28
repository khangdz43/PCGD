<?php

use App\Http\Controllers\Admin\AccessControl\RoleController;
use App\Http\Controllers\Admin\AccessControl\UserRoleController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HouseholdController;
use App\Http\Controllers\Education\SchoolController;
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
        Route::get('/csrf-cookie', fn () => response()->noContent())->name('csrf-cookie');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login');

        Route::middleware(['auth', 'active'])->group(function (): void {
            Route::get('/session', [AuthController::class, 'show'])->name('session');
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

            Route::get('/dashboard', [ProvinceController::class, 'home'])->name('dashboard');
            Route::get('/account', [AccountController::class, 'show'])->name('account.show');
            Route::patch('/account/password', [AccountController::class, 'updatePassword'])->name('account.password');

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
                Route::post('/households', [HouseholdManagementController::class, 'store'])->name('households.store');
                Route::post('/households/{household}/persons', [HouseholdPersonController::class, 'store'])->name('households.persons.store');
            });
            Route::middleware('can:update-households')->group(function (): void {
                Route::put('/households/{household}', [HouseholdManagementController::class, 'update'])->whereNumber('household')->name('households.update');
                Route::put('/households/{household}/persons/{personId}', [HouseholdPersonController::class, 'update'])->whereNumber(['household', 'personId'])->name('households.persons.update');
            });
            Route::middleware('can:delete-households')->group(function (): void {
                Route::delete('/households', [HouseholdManagementController::class, 'bulkDestroy'])->name('households.destroy-many');
                Route::delete('/households/{household}', [HouseholdManagementController::class, 'destroy'])->whereNumber('household')->name('households.destroy');
                Route::delete('/households/persons', [HouseholdPersonController::class, 'bulkDestroy'])->name('households.persons.destroy-many');
                Route::delete('/households/{household}/persons/{personId}', [HouseholdPersonController::class, 'destroy'])->whereNumber(['household', 'personId'])->name('households.persons.destroy');

                Route::get('/trash', [HouseholdTrashController::class, 'index'])->name('trash.index');
                Route::post('/trash/batch-operations', [HouseholdTrashController::class, 'bulk'])->name('trash.batch-operations.store');
                Route::get('/trash/households/{id}', [HouseholdTrashController::class, 'showHousehold'])->whereNumber('id')->name('trash.households.show');
                Route::patch('/trash/households/{id}', [HouseholdTrashController::class, 'restoreHousehold'])->whereNumber('id')->name('trash.households.restore');
                Route::delete('/trash/households/{id}', [HouseholdTrashController::class, 'permanentlyDeleteHousehold'])->whereNumber('id')->name('trash.households.destroy');
                Route::patch('/trash/persons/{id}', [HouseholdTrashController::class, 'restorePerson'])->whereNumber('id')->name('trash.persons.restore');
                Route::delete('/trash/persons/{id}', [HouseholdTrashController::class, 'permanentlyDeletePerson'])->whereNumber('id')->name('trash.persons.destroy');
            });

            Route::middleware('can:manage-villages')->prefix('villages')->name('villages.')->group(function (): void {
                Route::get('/', [VillageController::class, 'index'])->name('index');
                Route::post('/', [VillageController::class, 'store'])->name('store');
                Route::get('/{code}', [VillageController::class, 'show'])->name('show');
                Route::put('/{code}', [VillageController::class, 'update'])->name('update');
                Route::delete('/{code}', [VillageController::class, 'destroy'])->name('destroy');
            });
            Route::middleware('can:manage-villages')->group(function (): void {
                Route::get('/village-merges', [VillageMergeController::class, 'index'])->name('village-merges.index');
                Route::post('/village-merges', [VillageMergeController::class, 'store'])->name('village-merges.store');
            });
            Route::middleware('can:manage-schools')->prefix('schools')->name('schools.')->group(function (): void {
                Route::get('/', [SchoolController::class, 'index'])->name('index');
                Route::post('/', [SchoolController::class, 'store'])->name('store');
                Route::get('/{id}', [SchoolController::class, 'show'])->whereNumber('id')->name('show');
                Route::put('/{id}', [SchoolController::class, 'update'])->whereNumber('id')->name('update');
                Route::delete('/{id}', [SchoolController::class, 'destroy'])->whereNumber('id')->name('destroy');
            });

            Route::middleware('can:export-households')->group(function (): void {
                Route::get('/household-imports/template', [HouseholdImportExportController::class, 'template'])->name('household-imports.template');
                Route::get('/household-exports', [HouseholdImportExportController::class, 'export'])->name('household-exports.index');
            });
            Route::middleware('can:import-households')->group(function (): void {
                Route::get('/household-imports', HouseholdImportHistoryController::class)->name('households.import-history');
                Route::get('/household-imports/statuses', [HouseholdImportStatusController::class, 'batch'])->name('households.import.statuses');
                Route::get('/household-imports/{importLog}', HouseholdImportStatusController::class)->name('households.import.status');
                Route::get('/household-imports/{importLog}/file', [HouseholdImportHistoryController::class, 'download'])->name('household-imports.file');
                Route::get('/household-imports/{importLog}/error-file', [HouseholdImportHistoryController::class, 'downloadErrors'])->name('household-imports.error-file');
                Route::post('/household-imports', [HouseholdImportExportController::class, 'import'])->name('household-imports.store');
                Route::delete('/household-imports/{importLog}', [HouseholdImportHistoryController::class, 'destroy'])->name('household-imports.destroy');
                Route::get('/household-import-notifications', [HouseholdImportNotificationController::class, 'index'])->name('household-import-notifications');
                Route::patch('/household-import-notifications/read', [HouseholdImportNotificationController::class, 'acknowledge'])->name('household-import-notifications.read');
            });

            Route::middleware('can:manage-access-control')->prefix('roles')->name('roles.')->group(function (): void {
                Route::get('/', [RoleController::class, 'index'])->name('index');
                Route::post('/', [RoleController::class, 'store'])->name('store');
                Route::get('/{role}/users', [RoleController::class, 'members'])->name('users.index');
                Route::get('/{role}', [RoleController::class, 'show'])->name('show');
                Route::put('/{role}', [RoleController::class, 'update'])->name('update');
                Route::delete('/{role}', [RoleController::class, 'destroy'])->name('destroy');
            });
            Route::middleware('can:manage-user-accounts')->prefix('users')->name('users.')->group(function (): void {
                Route::get('/', [UserRoleController::class, 'index'])->name('index');
                Route::post('/', [UserRoleController::class, 'store'])->name('store');
                Route::get('/{user}', [UserRoleController::class, 'show'])->name('show');
                Route::put('/{user}', [UserRoleController::class, 'update'])->name('update');
                Route::patch('/{user}/status', [UserRoleController::class, 'updateStatus'])->name('status.update');
                Route::post('/{user}/password-resets', [UserRoleController::class, 'resetPassword'])->name('password-resets.store');
            });
        });
    });
});
