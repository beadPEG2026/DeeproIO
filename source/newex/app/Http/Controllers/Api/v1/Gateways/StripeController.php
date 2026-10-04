<?php

namespace App\Http\Controllers\Api\v1\Gateways;

use App\Http\Controllers\Controller;
use App\Services\PaymentGateways\Fiat\Stripe\Services\StripeService;
use Illuminate\Support\Facades\Log;

class StripeController extends Controller
{
    /**
     * @var StripeService
     */
    protected $stripeService;

    /**
     * @param StripeService $stripeService
     *
     */
    public function __construct(StripeService $stripeService)
    {
        $this->stripeService = $stripeService;
    }

    /**
     * Coinpayments IPN
     *
     * @return \Illuminate\Http\Response
     */
    public function ipn()
    {
        try {
            if(!$this->stripeService->verifyCallback()) {
                return response()->json('request_not_verified', STATUS_VALIDATION_ERROR);
            }

            return response()->json('request_processed', STATUS_OK);

        } catch (\Exception $e) {

            Log::error($e);

            return response()->json('request_not_verified', STATUS_VALIDATION_ERROR);
        }
    }
}
