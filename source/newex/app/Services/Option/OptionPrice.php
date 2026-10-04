<?php
namespace App\Services\Option;

use App\Models\Market\Market;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/** Real option quotes are fetched independently of editable chart/runtime caches. */
final class OptionPrice
{
    public function quote(Market $market, ?int $expiry = null): array
    {
        $now = now()->timestamp;
        if ($expiry !== null && ($now < $expiry || $now > $expiry + 30)) $this->unavailable();
        if (($market->chart_source ?: 'binance') !== 'binance') $this->unavailable();
        $symbol = strtoupper(str_replace(['-', '/'], '', $market->name));
        if (!preg_match('/^[A-Z0-9]{4,24}$/D', $symbol)) $this->unavailable();
        try {
            $row = Cache::remember('options:verified-quote:' . $symbol, 2, function () use ($symbol) {
                return Http::connectTimeout(3)->timeout(5)->get(rtrim(config('liquidity.market_data_base'), '/') . '/api/v3/ticker/24hr', ['symbol' => $symbol])->throw()->json();
            });
            $observed = (int) floor(($row['closeTime'] ?? 0) / 1000);
            $price = (string) ($row['lastPrice'] ?? '');
            if (($row['symbol'] ?? '') !== $symbol || !preg_match('/^\d+(?:\.\d{1,18})?$/D', $price)
                || bccomp($price, '0', 18) <= 0 || $observed < $now - 10 || $observed > $now + 2
                || ($expiry !== null && ($observed < $expiry || $observed > $expiry + 30))) $this->unavailable();
            return ['price' => $price, 'source' => 'binance:spot:' . $symbol . '@' . $observed];
        } catch (ValidationException $e) { throw $e; }
        catch (\Throwable $e) { $this->unavailable(); }
    }
    private function unavailable(): never
    {
        throw ValidationException::withMessages(['price' => __('A verified current price is unavailable; no fallback settlement is permitted.')]);
    }
}
