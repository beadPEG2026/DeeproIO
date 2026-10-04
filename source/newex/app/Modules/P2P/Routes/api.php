<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use App\Modules\P2P\Http\Controllers\Api\v1\PeerPaymentApiController;

/*
 *
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Broadcast::routes(['middleware' => ['auth:sanctum']]);

Route::group(['prefix' => 'v1', 'middle', 'middleware' => ['throttle:api', 'read.only']], function () {

    Route::get('p2p/assets', [PeerPaymentApiController::class, 'assets'])->name('p2p.api.assets');
    Route::get('p2p/payment-methods', [PeerPaymentApiController::class, 'paymentMethods'])->name('p2p.api.paymentMethods');
    Route::get('p2p/payment-methods/fields', [PeerPaymentApiController::class, 'paymentMethodFields'])->name('p2p.api.paymentMethodFields');
    Route::get('p2p/feedbacks', [PeerPaymentApiController::class, 'getFeedbacks'])->name('p2p.api.getFeedbacks');
    Route::get('p2p/ads', [PeerPaymentApiController::class, 'getAds'])->name('p2p.api.getAds');

    Route::get('p2p/feedback/stats', [PeerPaymentApiController::class, 'getFeedbackStats'])->name('p2p.api.getFeedbackStats');
    Route::get('p2p/pair-best-rates', [PeerPaymentApiController::class, 'getPairBestRate'])->name('p2p.api.getPairBestRate');
    Route::get('p2p/get-ad-info', [PeerPaymentApiController::class, 'getAdInfo'])->name('p2p.api.getAdInfo');
    Route::get('p2p/processing-fee', [PeerPaymentApiController::class, 'processingFee'])->name('p2p.api.processingFee');

    Route::group(['middleware' => ['auth:sanctum', 'maintenance']], function () {

        Route::get('p2p/getActiveOrdersQuantity', [PeerPaymentApiController::class, 'getActiveOrdersQuantity'])->name('p2p.api.getActiveOrdersQuantity');
        Route::post('p2p/postAd', [PeerPaymentApiController::class, 'postAd'])->name('p2p.api.postAd');
        Route::post('p2p/editAd', [PeerPaymentApiController::class, 'editAd'])->name('p2p.api.editAd');
        Route::post('p2p/setStatus', [PeerPaymentApiController::class, 'setStatus'])->name('p2p.api.setStatus');
        Route::post('p2p/blockUser', [PeerPaymentApiController::class, 'blockUser'])->name('p2p.api.blockUser');
        Route::post('p2p/unblockUser', [PeerPaymentApiController::class, 'unblockUser'])->name('p2p.api.unblockUser');

        Route::get('p2p/getOrderStatus', [PeerPaymentApiController::class, 'getOrderStatus'])->name('p2p.api.getOrderStatus');

        Route::post('p2p/deleteAd', [PeerPaymentApiController::class, 'deleteAd'])->name('p2p.api.deleteAd');

        Route::post('p2p/setOrder', [PeerPaymentApiController::class, 'setOrder'])->name('p2p.api.setOrder');
        Route::post('p2p/setOrderStatus', [PeerPaymentApiController::class, 'setOrderStatus'])->name('p2p.api.setOrderStatus');
        Route::post('p2p/changePaymentMethod', [PeerPaymentApiController::class, 'changePaymentMethod'])->name('p2p.api.changePaymentMethod');

        Route::group(['middleware' => ['throttle:20,1']], function () {
            Route::post('p2p/chatMessage', [PeerPaymentApiController::class, 'chatMessage'])->name('p2p.api.chatMessage');
        });

        Route::post('p2p/postOrderMessage', [PeerPaymentApiController::class, 'postOrderMessage'])->name('p2p.api.postOrderMessage');
        Route::get('p2p/getOrderMessage', [PeerPaymentApiController::class, 'getOrderMessage'])->name('p2p.api.getOrderMessage');
        Route::post('p2p/postOrderFeedback', [PeerPaymentApiController::class, 'postOrderFeedback'])->name('p2p.api.postOrderFeedback');
        Route::get('p2p/getOrderFeedback', [PeerPaymentApiController::class, 'getOrderFeedback'])->name('p2p.api.getOrderFeedback');
        Route::post('p2p/deleteFeedback', [PeerPaymentApiController::class, 'deleteFeedback'])->name('p2p.api.deleteFeedback');
        Route::post('p2p/editFeedback', [PeerPaymentApiController::class, 'editFeedback'])->name('p2p.api.editFeedback');
        Route::post('p2p/replyFeedback', [PeerPaymentApiController::class, 'replyFeedback'])->name('p2p.api.replyFeedback');
        Route::post('p2p/deleteFeedbackReply', [PeerPaymentApiController::class, 'deleteFeedbackReply'])->name('p2p.api.deleteFeedbackReply');

        Route::post('p2p/submitAppeal', [PeerPaymentApiController::class, 'submitAppeal'])->name('p2p.api.submitAppeal');

        Route::post('p2p/respondAppeal', [PeerPaymentApiController::class, 'respondAppeal'])->name('p2p.api.respondAppeal');
        Route::post('p2p/cancelAppeal', [PeerPaymentApiController::class, 'cancelAppeal'])->name('p2p.api.cancelAppeal');
        Route::post('p2p/submitAppealReview', [PeerPaymentApiController::class, 'submitAppealReview'])->name('p2p.api.submitAppealReview');

        Route::get('p2p/getUserPaymentMethods', [PeerPaymentApiController::class, 'getUserPaymentMethods'])->name('p2p.api.getUserPaymentMethods');
        Route::post('p2p/postUserPaymentMethod', [PeerPaymentApiController::class, 'postUserPaymentMethod'])->name('p2p.api.postUserPaymentMethod');
        Route::post('p2p/deleteUserPaymentMethod', [PeerPaymentApiController::class, 'deleteUserPaymentMethod'])->name('p2p.api.deleteUserPaymentMethod');

        Route::post('p2p/setUsername', [PeerPaymentApiController::class, 'setUsername'])->name('p2p.api.setUsername');

    });
});
