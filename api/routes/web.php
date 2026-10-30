<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'application' => 'School ERP API',
        'status' => 'ok',
    ]);
});
