<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group.
|
*/

// Serve the React SPA for all routes (except API routes)
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api).*$');
