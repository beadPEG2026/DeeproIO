<?php
namespace App\Services\Wallet;
use Illuminate\Http\Client\Response;
final class BridgeResponse
{
    public static function accepted(Response $response): bool
    {
        if (!$response->successful()) return false;
        $body=$response->json();
        if (!is_array($body) || !$body || !empty($body['error']) || ($body['success']??null)===false) return false;
        if (isset($body['message']) && preg_match('/not successful|failed|error|disabled/i',(string)$body['message'])) return false;
        return ($body['success']??false)===true || !empty($body['transactionHash']) || !empty($body['txid']) || (isset($body['message']) && preg_match('/^started\b/i',$body['message']));
    }
}
