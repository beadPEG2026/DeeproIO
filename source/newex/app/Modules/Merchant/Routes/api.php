<?php

use App\Modules\Merchant\Http\Controllers\Api\v1\InvoiceApiController;
use App\Modules\Merchant\Http\Controllers\Api\v1\CurrencyApiController;
use App\Modules\Merchant\Http\Controllers\Api\v1\WebhookApiController;
use App\Modules\Merchant\Http\Controllers\Api\v1\MerchantApiController;
use App\Modules\Merchant\Http\Controllers\Api\v1\WidgetApiController;
use App\Modules\Merchant\Http\Controllers\Web\Client\MerchantDashboardApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Merchant Acquiring API Routes
|--------------------------------------------------------------------------
*/

// Web Dashboard API (session auth) - for merchant dashboard Vue components
Route::prefix('api/v1/merchant-web')->middleware(['web', 'auth', 'verified'])->group(function () {
    Route::put('/settings', [MerchantDashboardApiController::class, 'updateSettings'])
        ->name('merchant.api.settings.update');
    Route::post('/webhook-secret/rotate', [MerchantDashboardApiController::class, 'rotateWebhookSecret'])
        ->name('merchant.api.webhook-secret.rotate');
    Route::post('/api-keys', [MerchantDashboardApiController::class, 'createApiKey'])
        ->name('merchant.api.api-keys.create');
    Route::delete('/api-keys/{id}', [MerchantDashboardApiController::class, 'deleteApiKey'])
        ->name('merchant.api.api-keys.revoke');
    Route::post('/invoices', [MerchantDashboardApiController::class, 'createInvoice'])
        ->name('merchant.api.invoices.create');
    Route::post('/invoices/{id}/cancel', [MerchantDashboardApiController::class, 'cancelInvoice'])
        ->name('merchant.api.invoices.cancel');
});

// Public Widget API (Bearer token auth)
Route::prefix('widget/v1')->group(function () {
    Route::middleware(['throttle:widget'])->group(function () {
        Route::get('/invoice', [WidgetApiController::class, 'getInvoice']);
        Route::post('/invoice/select-currency', [WidgetApiController::class, 'selectCurrency']);
        Route::get('/invoice/status', [WidgetApiController::class, 'getStatus']);
        Route::post('/invoice/extend-rate', [WidgetApiController::class, 'extendRate']);
        Route::get('/currencies', [WidgetApiController::class, 'getCurrencies']);
    });
});

// Merchant API (API Key + HMAC auth)
Route::prefix('api/v1/merchant')->group(function () {
    Route::middleware(['merchant.auth', 'throttle:merchant_api'])->group(function () {

        // Invoices
        Route::prefix('invoices')->group(function () {
            Route::post('/', [InvoiceApiController::class, 'create']);
            Route::get('/', [InvoiceApiController::class, 'index']);
            Route::get('/{id}', [InvoiceApiController::class, 'show']);
            Route::get('/{id}/status', [InvoiceApiController::class, 'status']);
            Route::post('/{id}/cancel', [InvoiceApiController::class, 'cancel']);
            Route::post('/{id}/refund', [InvoiceApiController::class, 'refund']);
        });

        // Payments
        Route::prefix('payments')->group(function () {
            Route::get('/', [InvoiceApiController::class, 'listPayments']);
            Route::get('/{id}', [InvoiceApiController::class, 'showPayment']);
        });

        // Currencies
        Route::prefix('currencies')->group(function () {
            Route::get('/', [CurrencyApiController::class, 'index']);
            Route::get('/{code}/rate', [CurrencyApiController::class, 'getRate']);
        });

        // Webhooks
        Route::prefix('webhooks')->group(function () {
            Route::get('/', [WebhookApiController::class, 'index'])->name('merchant.api.webhooks.index');
            Route::get('/event-types', [WebhookApiController::class, 'eventTypes'])->name('merchant.api.webhooks.event-types');
            Route::get('/statistics', [WebhookApiController::class, 'statistics'])->name('merchant.api.webhooks.statistics');
            Route::get('/{id}', [WebhookApiController::class, 'show'])->name('merchant.api.webhooks.show');
            Route::post('/{id}/retry', [WebhookApiController::class, 'retry'])->name('merchant.api.webhooks.retry');
            Route::post('/test', [WebhookApiController::class, 'sendTest'])->name('merchant.api.webhooks.test');
        });

        // Merchant account
        Route::get('/account', [MerchantApiController::class, 'show']);
        Route::get('/balance', [MerchantApiController::class, 'getBalance']);
        Route::get('/api-keys', [MerchantApiController::class, 'listApiKeys']);
        Route::post('/api-keys', [MerchantApiController::class, 'createApiKey']);
        Route::delete('/api-keys/{id}', [MerchantApiController::class, 'revokeApiKey']);
        Route::post('/webhook-secret/rotate', [MerchantApiController::class, 'rotateWebhookSecret']);
    });
});

// Webhook verification endpoint (public)
Route::post('/api/v1/merchant/webhooks/verify', [WebhookApiController::class, 'verify'])
    ->middleware(['throttle:webhook_verify']);
