<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Setting;

class SyncUnlimitConfiguration extends Command
{
    protected $signature = 'unlimit:sync-config';
    protected $description = 'Fetch and store Unlimit (GateFi) onramp configuration';

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

        $assets = $raw['assets'] ?? ($raw['onramp']['cryptos'] ?? ($raw['cryptos'] ?? []));
        foreach ($assets as $a) {
            if (is_array($a)) {
                $sym = $a['symbol'] ?? $a['code'] ?? $a['crypto'] ?? null;
                if ($sym) $cryptos[] = strtoupper($sym);
            } elseif (is_string($a)) {
                $cryptos[] = strtoupper($a);
            }
        }

        $fiatList = $raw['fiats'] ?? ($raw['onramp']['fiats'] ?? []);
        foreach ($fiatList as $f) {
            if (is_array($f)) {
                $sym = $f['symbol'] ?? $f['code'] ?? null;
                if ($sym) $fiats[] = strtoupper($sym);
            } elseif (is_string($f)) {
                $fiats[] = strtoupper($f);
            }
        }

        $paymentsList = $raw['payments'] ?? ($raw['onramp']['payments'] ?? []);
        foreach ($paymentsList as $p) {
            if (is_array($p)) {
                $name = $p['name'] ?? $p['code'] ?? $p['type'] ?? json_encode($p);
                $payments[] = $name;
                $fee = $p['feePercent'] ?? $p['processingFeePercent'] ?? null;
                if ($fee !== null) {
                    $rates[] = [
                        'payment' => $name,
                        'fee_percent' => $fee,
                    ];
                }
            } else {
                $payments[] = (string)$p;
            }
        }

        if (empty($rates)) {
            $fees = $raw['fees'] ?? [];
            foreach ($fees as $k => $v) {
                if (is_scalar($v)) {
                    $rates[] = ['payment' => (string)$k, 'fee_percent' => $v];
                }
            }
        }

        sort($cryptos); sort($fiats); sort($payments);
        return [
            'cryptos' => array_values(array_unique($cryptos)),
            'fiats' => array_values(array_unique($fiats)),
            'payments' => array_values(array_unique($payments)),
            'rates' => $rates,
        ];
    }

    public function handle(): int
    {
        if(!config('unlim.enabled', false)) return false;

        $base = $this->baseUrl();
        $path = '/onramp/v1/configuration';
        $headers = $this->headers('GET', $path);
        if (empty($headers)) {
            $this->error('Unlimit API credentials are not configured');
            return self::FAILURE;
        }
        try {
            $res = Http::withHeaders($headers)->get($base . $path);
            if ($res->failed()) {
                $this->error('Failed to fetch configuration: ' . $res->status());
                return self::FAILURE;
            }
            $raw = $res->json();
            $parsed = $this->normalize($raw ?? []);

            $settings = Setting::get('unlimit', []);
            $settings['configuration'] = $raw;
            $settings['configuration_parsed'] = $parsed;
            $settings['configuration_updated_at'] = now()->toDateTimeString();
            Setting::set('unlimit', $settings);

            $this->info('Unlimit configuration synced. Cryptos: ' . count($parsed['cryptos']) . ', Fiats: ' . count($parsed['fiats']) . ', Payments: ' . count($parsed['payments']));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('Unlimit sync command failed', ['e' => $e->getMessage()]);
            $this->error('Error: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
