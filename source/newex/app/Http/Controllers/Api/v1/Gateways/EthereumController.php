<?php

namespace App\Http\Controllers\Api\v1\Gateways;

use App\Http\Controllers\Controller;
use App\Services\PaymentGateways\Coin\Ethereum\Services\EthereumService;
use Illuminate\Support\Facades\Log;

class EthereumController extends Controller
{
    /**
     * @var EthereumService
     */
    protected $ethereumService;

    /**
     * @param EthereumService $ethereumService
     *
     */
    public function __construct(EthereumService $ethereumService)
    {
        $this->ethereumService = $ethereumService;
    }

    /**
     * Coinpayments IPN
     *
     * @return \Illuminate\Http\Response
     */
    public function ipn()
    {
        try {

            if(!$this->ethereumService->verifyCallback()) {
                return response()->json('request_not_verified', STATUS_VALIDATION_ERROR);
            }

            $response = $this->ethereumService->handleCallback();

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
