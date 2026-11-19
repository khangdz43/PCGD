<?php

use Illuminate\Support\Facades\Route;

Route::get('/{any?}', function () {
    return view('app');
    // vì mình làm web spa  khi url /households hay bất cứ cái gì thì return app.php 
})->where('any', '^(?!api(?:/|$)|admin/permissions(?:/|$)|admin/organization-accounts(?:/|$)|docs/api\.json$).*$');
