<?php

namespace App\Http\Controllers\Api\v1\Gateways;
 
use App\Http\Controllers\Controller;
use App\Http\Resources\Wallet\WalletCollection;
use App\Services\PaymentGateways\Coin\Coinpayments\Services\CoinpaymentsService;
use App\Services\Wallet\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CoinpaymentsController extends Controller
{
    /**
     * @var coinpaymentService
     */
    protected $coinpaymentService;

    /**
     * @param CoinpaymentsService $coinpaymentService
     *
     */
    public function __construct(CoinpaymentsService $coinpaymentService)
    {
        $this->coinpaymentService = $coinpaymentService;
    }

    /**
     * Coinpayments IPN
     *
     * @return \Illuminate\Http\Response
     */
    public function ipn()
    {
        try {

            if(!$this->coinpaymentService->verifyCallback()) {
                return response()->json('request_not_verified', STATUS_VALIDATION_ERROR);
            }

            $this->coinpaymentService->handleCallback();

            return response()->json('request_processed', STATUS_OK);

        } catch (\Exception $e) {

            Log::error($e);

            return response()->json('request_not_verified', STATUS_VALIDATION_ERROR);
        }
    }
}
