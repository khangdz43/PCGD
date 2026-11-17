<?php

use Illuminate\Support\Facades\Route;

Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api(?:/|$)|admin/permissions(?:/|$)|admin/organization-accounts(?:/|$)|docs/api\.json$).*$');
