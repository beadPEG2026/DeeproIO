<?php

namespace App\Http\Controllers\Api\v1\Gateways;

use App\Http\Controllers\Controller;
use App\Services\PaymentGateways\Coin\Bitcoin\Services\BitcoinService;
use Illuminate\Support\Facades\Log;

class BitcoinController extends Controller
{
    /**
     * @var BitcoinService
     */
    protected $bitcoinService;

    /**
     * @param BitcoinService $bitcoinService
     *
     */
    public function __construct(BitcoinService $bitcoinService)
    {
        $this->bitcoinService = $bitcoinService;
    }

    /**
     * Coinpayments IPN
     *
     * @return \Illuminate\Http\Response
     */
    public function ipn($symbol)
    {
        Log::info('IPN Bitcoin Called');
        // Note: Sensitive payment data not logged in production for security
        try {

            if(!$this->bitcoinService->verifyCallback()) {
                return response()->json('request_not_verified', STATUS_VALIDATION_ERROR);
            }

            $response = $this->bitcoinService->handleCallback($symbol);

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
