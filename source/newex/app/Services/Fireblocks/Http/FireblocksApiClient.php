<?php

namespace App\Services\Fireblocks\Http;


use FireblocksSdkPhp\Exceptions\FireblocksApiException;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;

class FireblocksApiClient
{
    /**
     * @param string|array|OpenSSLAsymmetricKey|OpenSSLCertificate $private_key
     * @param string $api_key
     * @param string $api_base_url
     * @param int|null $timeout
     */
    public function __construct($private_key, string $api_key, string $api_base_url, int $timeout=null)
    {
        $paramsClient = [
            // Base URI is used with relative requests
            'base_uri' => $api_base_url,
        ];
        if ($timeout){
            // You can set any number of default request options.
            $paramsClient['timeout']  = $timeout;
        }
        $this->api_key        = $api_key;
        $this->clientHttp     = new Client($paramsClient);
        $this->tokenProvider = new SdkTokenProvider($private_key, $api_key);
    }

    /**
     * @param string $path
     * @param bool $page_mode
     * @param array|null $query_params
     * @return array|mixed|null
     * @throws FireblocksApiException
     */
    public function get_request(string $path, bool $page_mode = false, array $query_params = null)
    {
        if ($query_params){
            $path = $path . "?" .  http_build_query($query_params);
        }

        $token = $this->tokenProvider->signJwt($path);

        $headers = [
            "X-API-Key"     => $this->api_key,
            "Authorization" => "Bearer {$token}",
        ];

        $response = $this->clientHttp->get($path,['headers' =>$headers]);

        return $this->handle_response($response, $page_mode);
    }

    /**
     * @param string $path
     * @param $page_mode
     * @param array|null $query_params
     * @return array|mixed|null
     * @throws FireblocksApiException
     */
    public function delete_request(string $path)
    {
        $token = $this->tokenProvider->signJwt($path);

        $headers = [
            "X-API-Key"     => $this->api_key,
            "Authorization" => "Bearer {$token}",
        ];

        $response = $this->clientHttp->delete($path,['headers' =>$headers]);
        return $this->handle_response($response);
    }

    public function post_request(string $path, array $body=[], $idempotency_key=null){
        $token = $this->tokenProvider->signJwt($path, $body);
        if (!$idempotency_key) {
            $headers = [
                "X-API-Key"     => $this->api_key,
                "Authorization" => "Bearer {$token}",
            ];
        }else{
            $headers = [
                "X-API-Key" => $this->api_key,
                "Authorization" => "Bearer {$token}",
                "Idempotency-Key" => $idempotency_key
            ];
        }

        $response = $this->clientHttp->post($path, ['headers' =>$headers, "json" => $body]);
        return $this->handle_response($response);
    }

    public function put_request(string $path, array $body=[]){
        $token = $this->tokenProvider->signJwt($path, $body);
        $headers = [
            "X-API-Key" => $this->api_key,
            "Authorization" => "Bearer {$token}",
            "Content-Type" => "application/json",
        ];

        $response = $this->clientHttp->put($path,['headers' =>$headers, "json" => $body]);
        return $this->handle_response($response);
    }
    public function patch_request(string $path, array $body=[]){
        $token = $this->tokenProvider->signJwt($path, $body);
        $headers = [
            "X-API-Key" => $this->api_key,
            "Authorization" => "Bearer {$token}",
            "Content-Type" => "application/json",
        ];

        $response = $this->clientHttp->patch($path, ['headers' =>$headers, "json" => $body]);
        return $this->handle_response($response);
    }

    private function handle_response(ResponseInterface $response, bool $page_mode = false)
    {

        try {
            $response_data = json_decode($response->getBody()->getContents(),true);
        } catch (\Exception $exception) {
            $response_data = null;
        }

        if ($response->getStatusCode() >= 300) {
            if ($response_data && isset($response_data["code"])) {
                $error_code = $response_data["code"];
                throw  new FireblocksApiException("Got an error from fireblocks server: " . $response->getBody(), $error_code);
            } else {
                throw new FireblocksApiException("Got an error from fireblocks server: " . $response->getBody());
            }
        } else {
            if ($page_mode) {
                return [
                    'transactions' => $response_data,
                    'pageDetails'  => [
                        'prevPage' => $response->getHeader('prev-page'),
                        'nextPage' => $response->getHeader('next-page'),
                    ]
                ];
            }
            return $response_data;
        }
    }
}
