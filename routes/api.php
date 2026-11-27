<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\DeployWebhookController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\InstagramConnectionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('catalog', [BillingController::class, 'catalog'])->middleware('throttle:api')->name('catalog');
    Route::post('webhooks/github', [DeployWebhookController::class, 'receive'])->middleware('throttle:api');
    Route::post('tokens', [AccountController::class, 'token'])->middleware('throttle:account');
    Route::post('tokens/register', [AccountController::class, 'registerToken'])->middleware('throttle:account');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
        Route::get('me', [AccountController::class, 'me']);
        Route::get('instagram/connection', [InstagramConnectionController::class, 'show']);
        Route::delete('tokens/current', [AccountController::class, 'revoke']);
        Route::get('imports', [ImportController::class, 'index']);
        Route::post('imports', [ImportController::class, 'store'])->middleware('throttle:imports');
        Route::get('imports/{import}', [ImportController::class, 'show'])->whereNumber('import');
        Route::get('imports/{import}/items', [ImportController::class, 'items'])->whereNumber('import');
        Route::post('media/{item}/download', [DownloadController::class, 'file'])->whereNumber('item')->middleware('throttle:transfers');
        Route::post('exports', [ExportController::class, 'store'])->middleware('throttle:transfers');
        Route::get('exports/{export}', [ExportController::class, 'show'])->whereNumber('export');
        Route::get('exports/{export}/download', [ExportController::class, 'file'])->whereNumber('export')->middleware('throttle:transfers');
        Route::post('checkout', [BillingController::class, 'checkout'])->middleware('throttle:checkout');
    });
});
