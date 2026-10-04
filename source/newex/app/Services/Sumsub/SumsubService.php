<?php

namespace App\Services\Sumsub;

class SumsubService {

    public function getToken($userId)
    {
        $levelName = config('sumsub.kyc_level', 'basic-kyc-level');
        $sumsubObject = new SumsubClient(config('sumsub.token'), config('sumsub.secret'));
        return $sumsubObject->getAccessToken($userId, $levelName);
    }
}
