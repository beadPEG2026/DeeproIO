<?php

use Illuminate\Support\Facades\Route;
use App\Modules\P2P\Http\Controllers\Web\Client as Client;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/
Route::group(['middleware' => ['read.only']], function () {

    Route::group(['middleware' => ['language.detect', 'maintenance', 'ping', 'read.only']], function () {

        Route::get('p2p-trading/ads', [Client\PeerTradeController::class, 'ads'])->name('p2p.ads');
        Route::get('p2p-trading/profile/{id}', [Client\PeerTradeController::class, 'profile'])->name('p2p.profile');

        Route::group(['middleware' => ['web', 'auth', 'verified']], function () {

            Route::get('p2p-trading/merchant/kyc', [Client\PeerTradeMerchantKycController::class, 'index'])->name('p2p.kyc');
            Route::post('p2p-trading/merchant/kyc', [Client\PeerTradeMerchantKycController::class, 'store'])->name('p2p.kyc.store');

            // P2P Trade
            Route::get('p2p-trading/create', [Client\PeerTradeController::class, 'create'])->name('p2p.create');
            Route::get('p2p-trading/create/success', [Client\PeerTradeController::class, 'postSuccess'])->name('p2p.create.success');
            Route::get('p2p-trading/my-ads/edit/{id}', [Client\PeerTradeController::class, 'edit'])->name('p2p.my-ads.edit');
            Route::get('p2p-trading/my-ads', [Client\PeerTradeController::class, 'myAds'])->name('p2p.my-ads');
            Route::get('p2p-trading/my-orders', [Client\PeerTradeController::class, 'myOrders'])->name('p2p.my-orders');
            Route::get('p2p-trading/my-orders/{id}', [Client\PeerTradeController::class, 'myOrder'])->name('p2p.my-order');
            Route::get('p2p-trading/appeals/{id}', [Client\PeerTradeController::class, 'myAppeal'])->name('p2p.my-appeal');

            Route::get('p2p-trading/payment_methods/{id?}', [Client\PeerTradeController::class, 'paymentMethods'])->name('p2p.paymentMethods');
        });
    });

});
