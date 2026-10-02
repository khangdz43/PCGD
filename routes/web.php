<?php

use App\Http\Controllers\VillageController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProvinceController;
use App\Http\Controllers\CommuneController;
use App\Http\Controllers\SchoolController;
use App\Http\Controllers\HouseholdController;
use App\Http\Controllers\HouseholdPersonController;
use App\Http\Controllers\HouseholdImportExportController;
use \App\Models\Province;

Route::get('/', [ProvinceController::class, 'home'])->name('index');

//  API get tỉnh xã (pathvariable)
Route::get('/api/provinces/{provinceCode}/communes', [ProvinceController::class, 'getCommunes']); // chua dung
Route::get('/api/communes/{communeCode}/villages', [CommuneController::class, 'getVillages']);

// sinh ra 7 endpoint chuẩn restful
Route::resource('villages', VillageController::class)->except('show');
Route::resource('schools', SchoolController::class)->except('show');



// import and export
Route::get('/households/import', [HouseholdImportExportController::class, 'create'])->name('households.import.create');
Route::post('/households/import', [HouseholdImportExportController::class, 'import'])->name('households.import.store');
Route::get('/households/template', [HouseholdImportExportController::class, 'template'])->name('households.template');
Route::get('/households/export', [HouseholdImportExportController::class, 'export'])->name('households.export');
// xóa sll
Route::delete('/households/bulk-destroy', [HouseholdController::class, 'bulkDestroy'])->name('households.bulk-destroy');
Route::delete('/households/bulk-members-destroy', [HouseholdPersonController::class, 'bulkDestroy'])->name('households.members.bulk-destroy');


// member in a household
Route::prefix('households/{household}/members')->name('households.members.')->group(function () {
    Route::get('/create', [HouseholdPersonController::class, 'create'])->name('create');
    Route::post('/', [HouseholdPersonController::class, 'store'])->name('store');
    Route::get('/{personId}', [HouseholdPersonController::class, 'show'])->name('show');
    Route::get('/{personId}/edit', [HouseholdPersonController::class, 'edit'])->name('edit');
    Route::put('/{personId}', [HouseholdPersonController::class, 'update'])->name('update');
    Route::delete('/{personId}', [HouseholdPersonController::class, 'destroy'])->name('destroy');
});

// route chạy trên xuống cái này để trên imporrt export là not found ngay
Route::resource('households', HouseholdController::class);
