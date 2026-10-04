<?php

namespace App\Services\PaymentGateways\Fiat\SilaMoney\Services;

use Silamoney\Client\Api\SilaApi;

class SilaMoneyService {

    public $client;

    public function __construct() {

        // Load your credentials
        $appHandle = env('SILAMONEY_APP_HANDLE');
        $privateKey = env('SILAMONEY_PRIVATE_KEY');

        $this->client = SilaApi::fromDefault($appHandle, $privateKey);
    }
}
