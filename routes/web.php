<?php

use App\Http\Controllers\Education\SchoolController;
use App\Http\Controllers\Geography\CommuneController;
use App\Http\Controllers\Geography\ProvinceController;
use App\Http\Controllers\Geography\VillageController;
use App\Http\Controllers\Households\HouseholdController;
use App\Http\Controllers\Households\HouseholdImportExportController;
use App\Http\Controllers\Households\HouseholdPersonController;
use App\Http\Controllers\Profile\AccountController;
use Illuminate\Support\Facades\Route;


// middle ware trước khi vào controller là chạy cái middleware
Route::middleware(['auth', 'active'])->group(function (): void {

    Route::get('/', [ProvinceController::class, 'home'])->name('index');
    Route::get('/account', [AccountController::class, 'show'])->name('account.show');
    Route::put('/account/password', [AccountController::class, 'updatePassword'])->name('account.password.update');
    Route::get('/api/provinces/{provinceCode}/communes', [ProvinceController::class, 'getCommunes']);
    Route::get('/api/communes/{communeCode}/villages', [CommuneController::class, 'getVillages']);
    Route::get('/api/provinces/{provinceCode}/schools', [SchoolController::class, 'getByProvince']);

    // laravel dùng can:permission-name để gọi Gate:before trong thằng provider
    Route::middleware('can:manage-villages')->group(function (): void {
        Route::resource('villages', VillageController::class)->except('show');
    });

    Route::middleware('can:manage-schools')->group(function (): void {
        Route::resource('schools', SchoolController::class)->except('show');
    });

    Route::middleware('can:export-households')->group(function (): void {
        Route::get('/households/template', [HouseholdImportExportController::class, 'template'])->name('households.template');
        Route::get('/households/export', [HouseholdImportExportController::class, 'export'])->name('households.export');
    });

    Route::middleware('can:import-households')->group(function (): void {
        Route::get('/households/import', [HouseholdImportExportController::class, 'create'])->name('households.import.create');
        Route::post('/households/import', [HouseholdImportExportController::class, 'import'])->name('households.import.store');
    });

    Route::middleware('can:create-households')->group(function (): void {
        Route::get('/households/create', [HouseholdController::class, 'create'])->name('households.create');
        Route::post('/households', [HouseholdController::class, 'store'])->name('households.store');
        Route::get('/households/{household}/members/create', [HouseholdPersonController::class, 'create'])->name('households.members.create');
        Route::post('/households/{household}/members', [HouseholdPersonController::class, 'store'])->name('households.members.store');
    });

    Route::middleware('can:delete-households')->group(function (): void {
        Route::delete('/households/bulk-destroy', [HouseholdController::class, 'bulkDestroy'])->name('households.bulk-destroy');
        Route::delete('/households/bulk-members-destroy', [HouseholdPersonController::class, 'bulkDestroy'])->name('households.members.bulk-destroy');
        Route::delete('/households/{household}', [HouseholdController::class, 'destroy'])->name('households.destroy');
        Route::delete('/households/{household}/members/{personId}', [HouseholdPersonController::class, 'destroy'])->name('households.members.destroy');
    });

    Route::middleware('can:view-households')->group(function (): void {
        Route::get('/households', [HouseholdController::class, 'index'])->name('households.index');
        Route::get('/households/{household}', [HouseholdController::class, 'show'])->name('households.show');
        Route::get('/households/{household}/members/{personId}', [HouseholdPersonController::class, 'show'])->name('households.members.show');
    });

    Route::middleware('can:update-households')->group(function (): void {
        Route::get('/households/{household}/edit', [HouseholdController::class, 'edit'])->name('households.edit');
        Route::match(['put', 'patch'], '/households/{household}', [HouseholdController::class, 'update'])->name('households.update');
        Route::get('/households/{household}/members/{personId}/edit', [HouseholdPersonController::class, 'edit'])->name('households.members.edit');
        Route::put('/households/{household}/members/{personId}', [HouseholdPersonController::class, 'update'])->name('households.members.update');
    });
});

require __DIR__.'/auth.php';
require __DIR__.'/admin.php';
