<?php

use App\Modules\Merchant\Http\Controllers\Web\Client\MerchantDashboardController;
use App\Modules\Merchant\Http\Controllers\Web\Client\MerchantEarningsController;
use App\Modules\Merchant\Http\Controllers\Web\Client\PaymentWidgetController;
use App\Modules\Merchant\Http\Controllers\Web\Admin\MerchantAdminController;
use App\Modules\Merchant\Http\Controllers\Web\Admin\InvoiceAdminController;
use App\Modules\Merchant\Http\Controllers\Web\Admin\PayoutAdminController;
use App\Modules\Merchant\Http\Controllers\Web\Admin\RefundAdminController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Merchant Acquiring Web Routes
|--------------------------------------------------------------------------
*/

Route::group(['middleware' => ['read.only']], function () {

    Route::group(['middleware' => ['language.detect', 'maintenance', 'ping', 'read.only', 'web']], function () {

        // Public Payment Widget/Checkout
        Route::prefix('pay')->group(function () {
            // Hosted checkout page
            Route::get('/i/{invoiceId}', [PaymentWidgetController::class, 'checkout'])
                ->name('merchant.checkout');

            // QR code generation
            Route::get('/qr/{data}', [PaymentWidgetController::class, 'generateQr'])
                ->name('merchant.qr');
        });

        Route::group(['middleware' => ['language.detect', 'maintenance', 'ping', 'read.only']], function () {

        // Merchant Dashboard (authenticated)
        Route::prefix('merchant')->middleware(['web', 'auth', 'verified'])->group(function () {
            // Onboarding / Application
            Route::get('/apply', [MerchantDashboardController::class, 'showApplication'])
                ->name('merchant.apply.show');
            Route::post('/apply', [MerchantDashboardController::class, 'submitApplication'])
                ->name('merchant.apply');

            // Dashboard
            Route::get('/dashboard', [MerchantDashboardController::class, 'index'])
                ->name('merchant.dashboard');

            // Invoices
            Route::get('/invoices', [MerchantDashboardController::class, 'invoices'])
                ->name('merchant.invoices');
            Route::get('/invoices/create', [MerchantDashboardController::class, 'createInvoice'])
                ->name('merchant.invoices.create');
            Route::get('/invoices/{id}', [MerchantDashboardController::class, 'showInvoice'])
                ->name('merchant.invoices.show');

            // Webhooks
            Route::get('/webhooks', [MerchantDashboardController::class, 'webhooks'])
                ->name('merchant.webhooks');

            // Settings
            Route::get('/settings', [MerchantDashboardController::class, 'settings'])
                ->name('merchant.settings');
            Route::get('/settings/api-keys', [MerchantDashboardController::class, 'apiKeys'])
                ->name('merchant.settings.api-keys');

            // Documentation
            Route::get('/docs', [MerchantDashboardController::class, 'documentation'])
                ->name('merchant.docs');

            // Earnings & Payouts
            Route::get('/earnings', [MerchantEarningsController::class, 'earnings'])
                ->name('merchant.earnings');
            Route::get('/deposits', [MerchantEarningsController::class, 'deposits'])
                ->name('merchant.deposits');
            Route::get('/payouts', [MerchantEarningsController::class, 'payouts'])
                ->name('merchant.payouts');
            Route::post('/payouts/request', [MerchantEarningsController::class, 'requestPayout'])
                ->name('merchant.payouts.request');
            Route::post('/payouts/{id}/cancel', [MerchantEarningsController::class, 'cancelPayout'])
                ->name('merchant.payouts.cancel');
            Route::put('/settings/payout', [MerchantEarningsController::class, 'updatePayoutSettings'])
                ->name('merchant.settings.payout');
        });

        });

        // Admin Routes
        Route::prefix('exchange-control-panel/merchant-acquiring')->middleware(['web', 'auth', 'role:superadmin|admin'])->group(function () {
            // Dashboard
            Route::get('/dashboard', [MerchantAdminController::class, 'dashboard'])
                ->name('admin.merchant.dashboard');

            // Merchants
            Route::get('/merchants', [MerchantAdminController::class, 'merchants'])
                ->name('admin.merchant.merchants');
            Route::get('/merchants/{id}', [MerchantAdminController::class, 'showMerchant'])
                ->name('admin.merchant.merchants.show');
            Route::post('/merchants/{id}/verify', [MerchantAdminController::class, 'verifyMerchant'])
                ->name('admin.merchant.merchants.verify');
            Route::post('/merchants/{id}/suspend', [MerchantAdminController::class, 'suspendMerchant'])
                ->name('admin.merchant.merchants.suspend');
            Route::post('/merchants/{id}/reactivate', [MerchantAdminController::class, 'reactivateMerchant'])
                ->name('admin.merchant.merchants.reactivate');
            Route::post('/merchants/{id}/update-limits', [MerchantAdminController::class, 'updateLimits'])
                ->name('admin.merchant.merchants.update-limits');

            // InvoicesAdd
            Route::get('/invoices', [InvoiceAdminController::class, 'index'])
                ->name('admin.merchant.invoices');
            Route::get('/invoices/{id}', [InvoiceAdminController::class, 'show'])
                ->name('admin.merchant.invoices.show');

            // Webhooks
            Route::get('/webhooks', [MerchantAdminController::class, 'webhooks'])
                ->name('admin.merchant.webhooks');
            Route::post('/webhooks/{id}/retry', [MerchantAdminController::class, 'retryWebhook'])
                ->name('admin.merchant.webhooks.retry');
            Route::get('/webhooks/dead-letters', [MerchantAdminController::class, 'deadLetters'])
                ->name('admin.merchant.webhooks.dead-letters');
            Route::post('/webhooks/dead-letters/{id}/resolve', [MerchantAdminController::class, 'resolveDeadLetter'])
                ->name('admin.merchant.webhooks.resolve');

            // Assets
            Route::get('/assets', [MerchantAdminController::class, 'assets'])
                ->name('admin.merchant.assets');
            Route::post('/assets/{id}/toggle', [MerchantAdminController::class, 'toggleAsset'])
                ->name('admin.merchant.assets.toggle');

            // Orphan Payments
            Route::get('/orphan-payments', [MerchantAdminController::class, 'orphanPayments'])
                ->name('admin.merchant.orphan-payments');
            Route::post('/orphan-payments/{id}/resolve', [MerchantAdminController::class, 'resolveOrphanPayment'])
                ->name('admin.merchant.orphan-payments.resolve');

            // Reports
            Route::get('/reports', [MerchantAdminController::class, 'reports'])
                ->name('admin.merchant.reports');

            // Earnings Overview
            Route::get('/earnings', [PayoutAdminController::class, 'earningsOverview'])
                ->name('admin.merchant.earnings');
            Route::get('/earnings/deposits', [PayoutAdminController::class, 'deposits'])
                ->name('admin.merchant.earnings.deposits');
            Route::post('/earnings/deposits/{depositAddressId}/retry-sweep', [PayoutAdminController::class, 'retrySweep'])
                ->name('admin.merchant.earnings.deposits.retry-sweep');
            Route::get('/earnings/merchants/{merchantId}', [PayoutAdminController::class, 'merchantEarnings'])
                ->name('admin.merchant.earnings.merchant');

            // Payouts
            Route::get('/payouts', [PayoutAdminController::class, 'payouts'])
                ->name('admin.merchant.payouts');
            Route::get('/payouts/{id}', [PayoutAdminController::class, 'showPayout'])
                ->name('admin.merchant.payouts.show');
            Route::post('/payouts/{id}/approve', [PayoutAdminController::class, 'approvePayout'])
                ->name('admin.merchant.payouts.approve');
            Route::post('/payouts/{id}/reject', [PayoutAdminController::class, 'rejectPayout'])
                ->name('admin.merchant.payouts.reject');
            Route::post('/payouts/{id}/process', [PayoutAdminController::class, 'processPayout'])
                ->name('admin.merchant.payouts.process');
            Route::post('/payouts/{id}/retry', [PayoutAdminController::class, 'retryPayout'])
                ->name('admin.merchant.payouts.retry');
            Route::post('/payouts/{id}/complete', [PayoutAdminController::class, 'completePayout'])
                ->name('admin.merchant.payouts.complete');

            // Refunds
            Route::get('/refunds', [RefundAdminController::class, 'index'])
                ->name('admin.merchant.refunds');
            Route::get('/refunds/{id}', [RefundAdminController::class, 'show'])
                ->name('admin.merchant.refunds.show');
            Route::post('/refunds/{id}/approve', [RefundAdminController::class, 'approve'])
                ->name('admin.merchant.refunds.approve');
            Route::post('/refunds/{id}/process', [RefundAdminController::class, 'process'])
                ->name('admin.merchant.refunds.process');
            Route::post('/refunds/{id}/retry', [RefundAdminController::class, 'retry'])
                ->name('admin.merchant.refunds.retry');
            Route::post('/refunds/{id}/cancel', [RefundAdminController::class, 'cancel'])
                ->name('admin.merchant.refunds.cancel');
            Route::post('/refunds/{id}/complete', [RefundAdminController::class, 'complete'])
                ->name('admin.merchant.refunds.complete');
            Route::post('/orphan-payments/{id}/refund', [RefundAdminController::class, 'refundOrphanPayment'])
                ->name('admin.merchant.orphan-payments.refund');
        });

    });
});
