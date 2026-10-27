<?php

use App\Http\Controllers\Admin\AccessControl\RoleController;
use App\Http\Controllers\Admin\AccessControl\UserRoleController;
use App\Http\Controllers\Api\HouseholdController;
use App\Http\Controllers\Api\SessionController;
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

Route::middleware('web')->prefix('v1')->group(function (): void {
    Route::get('/csrf-cookie', [SessionController::class, 'csrfCookie'])->name('api.csrf-cookie');
    Route::middleware('guest')->post('/login', [SessionController::class, 'login'])->name('api.login');

    Route::middleware(['auth', 'active'])->group(function (): void {
        Route::get('/session', [SessionController::class, 'show'])->name('api.session');
        Route::post('/logout', [SessionController::class, 'logout'])->name('api.logout');
        Route::get('/dashboard', [ProvinceController::class, 'home'])->name('api.dashboard');
        Route::get('/account', [AccountController::class, 'show'])->name('api.account.show');
        Route::put('/account/password', [AccountController::class, 'updatePassword'])->name('api.account.password');
        Route::get('/provinces/{provinceCode}/communes', [ProvinceController::class, 'getCommunes'])->name('api.provinces.communes');
        Route::get('/communes/{communeCode}/villages', [CommuneController::class, 'getVillages'])->name('api.communes.villages');
        Route::get('/provinces/{provinceCode}/schools', [SchoolController::class, 'getByProvince'])->name('api.provinces.schools');

        Route::middleware('can:view-households')->group(function (): void {
            Route::get('/households', [HouseholdController::class, 'index'])->name('api.households.index');
            Route::get('/households/{household}', [HouseholdController::class, 'show'])->whereNumber('household')->name('api.households.show');
            Route::get('/households/{household}/members/{personId}', [HouseholdPersonController::class, 'show'])->whereNumber(['household', 'personId'])->name('api.households.members.show');
        });
        Route::middleware('can:create-households')->group(function (): void {
            Route::get('/households/form', [HouseholdManagementController::class, 'create'])->name('api.households.create');
            Route::post('/households', [HouseholdManagementController::class, 'store'])->name('api.households.store');
            Route::get('/households/{household}/members/form', [HouseholdPersonController::class, 'create'])->name('api.households.members.create');
            Route::post('/households/{household}/members', [HouseholdPersonController::class, 'store'])->name('api.households.members.store');
        });
        Route::middleware('can:update-households')->group(function (): void {
            Route::get('/households/{household}/form', [HouseholdManagementController::class, 'edit'])->whereNumber('household')->name('api.households.edit');
            Route::match(['put', 'patch'], '/households/{household}', [HouseholdManagementController::class, 'update'])->whereNumber('household')->name('api.households.update');
            Route::get('/households/{household}/members/{personId}/form', [HouseholdPersonController::class, 'edit'])->whereNumber(['household', 'personId'])->name('api.households.members.edit');
            Route::put('/households/{household}/members/{personId}', [HouseholdPersonController::class, 'update'])->whereNumber(['household', 'personId'])->name('api.households.members.update');
        });
        Route::middleware('can:delete-households')->group(function (): void {
            Route::delete('/households/bulk', [HouseholdManagementController::class, 'bulkDestroy'])->name('api.households.bulk-destroy');
            Route::delete('/households/{household}', [HouseholdManagementController::class, 'destroy'])->whereNumber('household')->name('api.households.destroy');
            Route::delete('/households/members/bulk', [HouseholdPersonController::class, 'bulkDestroy'])->name('households.members.bulk-destroy');
            Route::delete('/households/{household}/members/{personId}', [HouseholdPersonController::class, 'destroy'])->whereNumber(['household', 'personId'])->name('households.members.destroy');
            Route::get('/trash', [HouseholdTrashController::class, 'index'])->name('api.trash.index');
            Route::post('/trash/bulk', [HouseholdTrashController::class, 'bulk'])->name('api.trash.bulk');
            Route::get('/trash/households/{id}', [HouseholdTrashController::class, 'showHousehold'])->name('api.trash.households.show');
            Route::patch('/trash/households/{id}/restore', [HouseholdTrashController::class, 'restoreHousehold'])->name('api.trash.households.restore');
            Route::delete('/trash/households/{id}', [HouseholdTrashController::class, 'permanentlyDeleteHousehold'])->name('api.trash.households.destroy');
            Route::patch('/trash/persons/{id}/restore', [HouseholdTrashController::class, 'restorePerson'])->name('api.trash.persons.restore');
            Route::delete('/trash/persons/{id}', [HouseholdTrashController::class, 'permanentlyDeletePerson'])->name('api.trash.persons.destroy');
        });

        Route::middleware('can:manage-villages')->group(function (): void {
            Route::get('/villages', [VillageController::class, 'index'])->name('api.villages.index');
            Route::get('/villages/form', [VillageController::class, 'create'])->name('api.villages.create');
            Route::post('/villages', [VillageController::class, 'store'])->name('api.villages.store');
            Route::get('/villages/{code}/form', [VillageController::class, 'edit'])->name('api.villages.edit');
            Route::put('/villages/{code}', [VillageController::class, 'update'])->name('api.villages.update');
            Route::delete('/villages/{code}', [VillageController::class, 'destroy'])->name('api.villages.destroy');
            Route::get('/villages/merge', [VillageMergeController::class, 'create'])->name('api.villages.merge');
            Route::post('/villages/merge', [VillageMergeController::class, 'store'])->name('api.villages.merge.store');
        });
        Route::middleware('can:manage-schools')->group(function (): void {
            Route::get('/schools', [SchoolController::class, 'index'])->name('api.schools.index');
            Route::get('/schools/form', [SchoolController::class, 'create'])->name('api.schools.create');
            Route::post('/schools', [SchoolController::class, 'store'])->name('api.schools.store');
            Route::get('/schools/{id}/form', [SchoolController::class, 'edit'])->name('api.schools.edit');
            Route::put('/schools/{id}', [SchoolController::class, 'update'])->name('api.schools.update');
            Route::delete('/schools/{id}', [SchoolController::class, 'destroy'])->name('api.schools.destroy');
        });

        Route::middleware('can:export-households')->group(function (): void {
            Route::get('/households/template', [HouseholdImportExportController::class, 'template'])->name('api.households.template');
            Route::get('/households/export', [HouseholdImportExportController::class, 'export'])->name('api.households.export');
        });
        Route::middleware('can:import-households')->group(function (): void {
            Route::get('/households/import/form', [HouseholdImportExportController::class, 'create'])->name('api.households.import.create');
            Route::post('/households/import', [HouseholdImportExportController::class, 'import'])->name('api.households.import.store');
            Route::get('/households/imports', HouseholdImportHistoryController::class)->name('households.import-history');
            Route::get('/households/imports/statuses', [HouseholdImportStatusController::class, 'batch'])->name('api.households.import.statuses');
            Route::get('/households/imports/{importLog}/status', HouseholdImportStatusController::class)->name('households.import.status');
            Route::get('/households/imports/{importLog}/download', [HouseholdImportHistoryController::class, 'download'])->name('api.households.import.download');
            Route::get('/households/imports/{importLog}/download-errors', [HouseholdImportHistoryController::class, 'downloadErrors'])->name('api.households.import.download-errors');
            Route::delete('/households/imports/{importLog}', [HouseholdImportHistoryController::class, 'destroy'])->name('api.households.import.destroy');
            Route::get('/households/import-notifications', [HouseholdImportNotificationController::class, 'index'])->name('api.households.import-notifications');
            Route::post('/households/import-notifications/acknowledge', [HouseholdImportNotificationController::class, 'acknowledge'])->name('api.households.import-notifications.acknowledge');
        });

        Route::middleware('can:manage-access-control')->prefix('roles')->name('api.roles.')->group(function (): void {
            Route::get('/', [RoleController::class, 'index'])->name('index');
            Route::get('/create', [RoleController::class, 'create'])->name('create');
            Route::post('/', [RoleController::class, 'store'])->name('store');
            Route::get('/{role}/members', [RoleController::class, 'members'])->name('members');
            Route::get('/{role}/edit', [RoleController::class, 'edit'])->name('edit');
            Route::put('/{role}', [RoleController::class, 'update'])->name('update');
            Route::delete('/{role}', [RoleController::class, 'destroy'])->name('destroy');
        });
        Route::middleware('can:manage-user-accounts')->prefix('users')->name('api.users.')->group(function (): void {
            Route::get('/', [UserRoleController::class, 'index'])->name('index');
            Route::get('/create', [UserRoleController::class, 'create'])->name('create');
            Route::post('/', [UserRoleController::class, 'store'])->name('store');
            Route::get('/{user}/edit', [UserRoleController::class, 'edit'])->name('edit');
            Route::put('/{user}', [UserRoleController::class, 'update'])->name('update');
            Route::patch('/{user}/status', [UserRoleController::class, 'updateStatus'])->name('status');
            Route::post('/{user}/reset-password', [UserRoleController::class, 'resetPassword'])->name('reset-password');
        });
    });
});
