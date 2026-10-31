<?php

use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Auth\ApiTokenController;
use App\Http\Middleware\EnsureUserManagementApiAccess;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('/auth/token', [ApiTokenController::class, 'store'])
        ->middleware('throttle:user-api-token')
        ->name('auth.token.store');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::delete('/auth/token', [ApiTokenController::class, 'destroy'])->name('auth.token.destroy');

        Route::middleware(EnsureUserManagementApiAccess::class)->group(function (): void {
            Route::get('/users', [UserController::class, 'index'])->name('users.index');
            Route::post('/users', [UserController::class, 'store'])->name('users.store');
            Route::get('/users/{account}', [UserController::class, 'show'])->whereNumber('account')->name('users.show');
            Route::patch('/users/{account}', [UserController::class, 'update'])->whereNumber('account')->name('users.update');
            Route::patch('/users/{account}/status', [UserController::class, 'setStatus'])->whereNumber('account')->name('users.status');
            Route::delete('/users/{account}', [UserController::class, 'archive'])->whereNumber('account')->name('users.archive');
            Route::post('/users/{account}/restore', [UserController::class, 'restore'])->whereNumber('account')->name('users.restore');
        });
    });
});
