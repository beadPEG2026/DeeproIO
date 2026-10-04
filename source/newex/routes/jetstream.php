<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Fortify\PendingTwoFactorAuthenticationController;
use App\Http\Controllers\Fortify\TwoFactorQrCodeController;
use Laravel\Jetstream\Http\Controllers\Inertia\ApiTokenController;
use Laravel\Jetstream\Http\Controllers\Inertia\CurrentUserController;
use Laravel\Jetstream\Http\Controllers\Inertia\OtherBrowserSessionsController;
use Laravel\Jetstream\Http\Controllers\Inertia\UserProfileController;
use Laravel\Jetstream\Jetstream;

Route::group(['middleware' => config('jetstream.middleware', ['web'])], function () {

    Route::group(['middleware' => ['trust.proxy'] ], function () {
        Route::group(['middleware' => ['verified']], function () {
            // User & Profile...
            Route::get('/user/profile/{slug?}', [UserProfileController::class, 'show'])
                ->name('profile.show');

            Route::delete('/user/other-browser-sessions', [OtherBrowserSessionsController::class, 'destroy'])
                ->name('other-browser-sessions.destroy');

            if (Jetstream::hasAccountDeletionFeatures()) {
                Route::delete('/user', [CurrentUserController::class, 'destroy'])
                    ->name('current-user.destroy');
            }

            Route::get('/user/pending-two-factor-qr-code', [TwoFactorQrCodeController::class, 'show'])
                ->name('two-factor.pending-qr-code');

            Route::post('/user/pending-two-factor-authentication', [PendingTwoFactorAuthenticationController::class, 'store'])
                ->name('two-factor.pending-enable');

            Route::post('/user/pending-two-factor-authentication/confirm', [PendingTwoFactorAuthenticationController::class, 'confirm'])
                ->name('two-factor.pending-confirm');

            Route::delete('/user/pending-two-factor-authentication', [PendingTwoFactorAuthenticationController::class, 'destroy'])
                ->name('two-factor.pending-disable');

            // API...
            if (Jetstream::hasApiFeatures()) {
                Route::get('/user/api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
                Route::post('/user/api-tokens', [ApiTokenController::class, 'store'])->name('api-tokens.store');
                Route::put('/user/api-tokens/{token}', [ApiTokenController::class, 'update'])->name('api-tokens.update');
                Route::delete('/user/api-tokens/{token}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');
            }
        });

    });
});
