<?php

namespace App\Http\Controllers\Api\v1\Gateways;

use App\Http\Controllers\Controller;
use App\Services\Fireblocks\FireblocksService;
use Illuminate\Support\Facades\Log;

class FireblocksController extends Controller
{
    /**
     * @var FireblocksService
     */
    protected $service;

    /**
     * @param FireblocksService $service
     *
     */
    public function __construct(FireblocksService $service)
    {
        $this->service = $service;
    }

    /**
     * Fireblocks IPN
     *
     * @return \Illuminate\Http\Response
     */
    public function ipn()
    {
        Log::info('IPN Fireblocks Called');
        // Note: Sensitive payment data not logged in production for security
        try {

            if(!$this->service->verifyCallback()) {
                return response()->json('request_not_verified', STATUS_VALIDATION_ERROR);
            }

            $response = $this->service->handleCallback();

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
