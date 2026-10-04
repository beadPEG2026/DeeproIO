<?php

namespace App\Http\Controllers\Api\v1\Gateways;

use App\Http\Controllers\Controller;
use App\Services\PaymentGateways\Coin\Bnb\Services\BnbService;
use Illuminate\Support\Facades\Log;

class BscController extends Controller
{
    /**
     * @var BnbService
     */
    protected $bnbService;

    /**
     * @param BnbService $bnbService
     *
     */
    public function __construct(BnbService $bnbService)
    {
        $this->bnbService = $bnbService;
    }

    /**
     * Coinpayments IPN
     *
     * @return \Illuminate\Http\Response
     */
    public function ipn()
    {
        try {

            if(!$this->bnbService->verifyCallback()) {
                return response()->json('request_not_verified', STATUS_VALIDATION_ERROR);
            }

            $response = $this->bnbService->handleCallback();

            if($response) {
                return response()->json('request_processed', STATUS_OK);
            } else {
                return response()->json('request_not_processed', STATUS_VALIDATION_ERROR);
            }

        } catch (\Exception $e) {

            Log::error($e);

            return response()->json('request_not_verified', STATUS_VALIDATION_ERROR);
        }
    }
}
