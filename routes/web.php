<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\InstagramConnectionController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'library')->name('home');
Route::prefix('api/v1/session')->group(function (): void {
    Route::post('register', [AccountController::class, 'register'])->middleware('throttle:account');
    Route::post('login', [AccountController::class, 'login'])->middleware('throttle:account');
    Route::post('logout', [AccountController::class, 'logout'])->middleware('auth:web');
    Route::post('instagram/connect', [InstagramConnectionController::class, 'connect'])->middleware(['auth:web', 'throttle:api'])->block(60, 10);
    Route::delete('instagram', [InstagramConnectionController::class, 'disconnect'])->middleware(['auth:web', 'throttle:api'])->block(60, 10);
});
Route::get('instagram/callback', [InstagramConnectionController::class, 'callback'])->middleware('throttle:api')
    ->block(60, 10)->name('instagram.callback');
