<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Setting;

class UnlimitAdminController extends Controller
{
    private function settings(): array
    {
        $value = Setting::get('unlimit', []);
        return is_array($value) ? $value : (json_decode((string)$value, true) ?: []);
    }

    private function baseUrl(): string
    {
        return rtrim(config('services.unlimit.base_url') ?? env('UNLIMIT_BASE_URL', 'https://api-sandbox.gatefi.com'), '/');
    }

    private function apiKey(): ?string
    {
        return config('services.unlimit.api_key') ?? env('UNLIMIT_ACCESS_KEY');
    }

    private function apiSecret(): ?string
    {
        return config('services.unlimit.api_secret') ?? env('UNLIMIT_SECRET_KEY');
    }

    private function headers(string $method, string $path): array
    {
        $secret = $this->apiSecret();
        $key = $this->apiKey();
        if (!$secret || !$key) return [];
        $signature = hash_hmac('sha256', strtoupper($method) . $path, $secret);
        return [
            'signature' => $signature,
            'api-key' => $key,
        ];
    }

    private function normalize(array $raw): array
    {
        $cryptos = [];
        $fiats = [];
        $payments = [];
        $rates = [];

        // Try to match various possible structures safely
        $assets = $raw['assets'] ?? ($raw['onramp']['cryptos'] ?? ($raw['cryptos'] ?? []));
        foreach ($assets as $a) {
            if (is_array($a)) {
                $sym = $a['symbol'] ?? $a['code'] ?? $a['crypto'] ?? null;
                if ($sym) $cryptos[] = strtoupper($sym);
            } elseif (is_string($a)) {
                $cryptos[] = strtoupper($a);
            }
        }

        $fiats = is_array($raw['fiat'] ?? null) ? $raw['fiat'] : [];
        $payments = is_array($raw['payments'] ?? null) ? $raw['payments'] : [];
        $cryptos = is_array($raw['crypto'] ?? null) ? $raw['crypto'] : $cryptos;

        sort($cryptos);
        sort($fiats);
        sort($payments);

        return [
            'cryptos' => $cryptos,
            'fiats' => $fiats,
            'payments' => $payments,
            'rates' => $rates,
        ];
    }

    private function fetchAndStore(): array
    {
        $base = $this->baseUrl();
        $path = '/onramp/v1/configuration';
        $headers = $this->headers('GET', $path);
        if (empty($headers)) {
            throw new \RuntimeException('Unlimit API credentials are not configured');
        }
        $res = Http::withHeaders($headers)->connectTimeout(5)->timeout(15)->get($base . $path);
        if ($res->failed()) {
            throw new \RuntimeException('Failed to fetch configuration: ' . $res->status());
        }
        $raw = $res->json();

        $parsed = $this->normalize($raw ?? []);

        $settings = $this->settings();

        $settings['configuration_parsed'] = $parsed;
        $settings['configuration_updated_at'] = now()->toDateTimeString();

        Setting::set('unlimit', json_encode($settings));

        return $parsed;
    }

    public function config(Request $request)
    {
        $settings = $this->settings();

        $parsed = $settings['configuration_parsed'] ?? null;
        if (!$this->apiKey() || !$this->apiSecret()) return response()->json(['success'=>false,'configured'=>false,'error'=>__('Payment provider is not configured.'),'data'=>$parsed], 422);
        if (!$parsed) {
            try {
                $parsed = $this->fetchAndStore();

            } catch (\Throwable $e) {
                Log::warning('Unlimit Admin config auto-fetch failed', ['e' => $e->getMessage()]);
                return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
            }
        }
        return response()->json(['success' => true, 'data' => $parsed]);
    }

    public function sync(Request $request)
    {
        try {
            $parsed = $this->fetchAndStore();
            return response()->json(['success' => true, 'data' => $parsed]);
        } catch (\Throwable $e) {
            Log::error('Unlimit Admin sync failed', ['e' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
