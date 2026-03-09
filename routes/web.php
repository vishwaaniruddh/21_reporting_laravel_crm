<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TimestampMismatchController;

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

// Timestamp Mismatch Checker
Route::get('/timestamp-mismatches', [TimestampMismatchController::class, 'index'])->name('timestamp-mismatches.index');
Route::post('/api/timestamp-mismatches/check', [TimestampMismatchController::class, 'check'])->name('timestamp-mismatches.check');
Route::post('/api/timestamp-mismatches/fix', [TimestampMismatchController::class, 'fix'])->name('timestamp-mismatches.fix');

// Serve the React SPA for all routes (except API routes and timestamp-mismatches)
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api|timestamp-mismatches).*$');
