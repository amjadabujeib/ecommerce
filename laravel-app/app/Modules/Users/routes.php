<?php

use App\Modules\Users\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Users module (served under /api/v1)
|--------------------------------------------------------------------------
|
| Registration, sign-in and the JWT token lifecycle.
|
*/

Route::post('/register', [AuthController::class, 'register'])
    ->middleware('throttle:auth')
    ->name('register');

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:auth')
    ->name('login');

// Outside the auth group so an expired token can still be refreshed
// within the refresh window.
Route::post('/refresh', [AuthController::class, 'refresh'])
    ->middleware('throttle:auth')
    ->name('refresh');

Route::middleware('auth:api')->group(function (): void {
    Route::get('/me', [AuthController::class, 'me'])->name('me');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});
