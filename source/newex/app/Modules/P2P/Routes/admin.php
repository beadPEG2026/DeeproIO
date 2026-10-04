<?php

use Illuminate\Support\Facades\Route;
use App\Modules\P2P\Http\Controllers\Web\Admin as Admin;

Route::group(['middleware' => ['read.only']], function () {

    Route::group(['middleware' => ['language.detect', 'maintenance', 'ping', 'read.only']], function () {

        Route::group(['prefix' => 'exchange-control-panel', 'middleware' => ['auth', 'role:superadmin|perm_p2p']], function () {

            Route::group(['middleware' => ['role:superadmin|perm_p2p']], function() {

                /*
                 * Peer Trade
                 */
                Route::get('peer-merchant-documents', [Admin\PeerMerchantKycDocumentController::class, 'index'])->name('admin.peerMerchantApplications');
                Route::put('peer-merchant-documents/{document}', [Admin\PeerMerchantKycDocumentController::class, 'moderate'])->name('admin.peer-merchant-documents.moderate');

                Route::get('peer-trades-dashboard', [Admin\PeerTradeDashboardController::class, 'index'])->name('admin.peerTradeDashboard');
                Route::get('peer-trades-transactions', [Admin\PeerTradeDashboardController::class, 'trades'])->name('admin.peerTradeTransactions');
                Route::get('peer-trades-payment-methods', [Admin\PeerPaymentMethodController::class, 'index'])->name('admin.peerPaymentMethods');
                Route::get('peer-trades-payment-methods/create', [Admin\PeerPaymentMethodController::class, 'create'])->name('admin.peerPaymentMethods.create');
                Route::post('peer-trades-payment-methods', [Admin\PeerPaymentMethodController::class, 'store'])->name('admin.peerPaymentMethods.store');
                Route::get('peer-trades-payment-methods/{paymentMethod}/edit', [Admin\PeerPaymentMethodController::class, 'edit'])->name('admin.peerPaymentMethods.edit');
                Route::put('peer-trades-payment-methods/{paymentMethod}', [Admin\PeerPaymentMethodController::class, 'update'])->name('admin.peerPaymentMethods.update');
                Route::delete('peer-trades-payment-methods/{paymentMethod}', [Admin\PeerPaymentMethodController::class, 'destroy'])->name('admin.peerPaymentMethods.destroy');

                Route::get('peer-trades-payment-fields/{paymentMethod}/create', [Admin\PeerPaymentFieldController::class, 'create'])->name('admin.peerPaymentFields.create');
                Route::get('peer-trades-payment-fields/{paymentMethod}', [Admin\PeerPaymentFieldController::class, 'index'])->name('admin.peerPaymentFields');
                Route::post('peer-trades-payment-fields', [Admin\PeerPaymentFieldController::class, 'store'])->name('admin.peerPaymentFields.store');
                Route::get('peer-trades-payment-fields/{paymentMethod}/{paymentField}/edit', [Admin\PeerPaymentFieldController::class, 'edit'])->name('admin.peerPaymentFields.edit');
                Route::put('peer-trades-payment-fields/{paymentField}', [Admin\PeerPaymentFieldController::class, 'update'])->name('admin.peerPaymentFields.update');
                Route::delete('peer-trades-payment-fields/{paymentField}', [Admin\PeerPaymentFieldController::class, 'destroy'])->name('admin.peerPaymentFields.destroy');

                Route::get('peer-trades-appeals', [Admin\PeerOrdersAppealsController::class, 'index'])->name('admin.peerOrdersAppeals');
                Route::post('peer-trades-appeals/moderate', [Admin\PeerOrdersAppealsController::class, 'moderate'])->name('admin.peerOrdersAppeals.moderate');

                Route::get('peer-trades-appeals/{appeal}', [Admin\PeerOrdersAppealsController::class, 'view'])->name('admin.peerOrdersAppeals.view');

                Route::get('peer-trades-chat', [Admin\PeerOrdersAppealsController::class, 'getChat'])->name('admin.peerOrdersAppeals.getChat');
                Route::post('peer-trades-chat/post-message', [Admin\PeerOrdersAppealsController::class, 'postChat'])->name('admin.peerOrdersAppeals.postChat');


            });
        });

    });
});
