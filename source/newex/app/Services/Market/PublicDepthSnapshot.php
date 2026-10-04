<?php
namespace App\Services\Market;

use Illuminate\Support\Facades\Http;

/** Transport/serialization only; the existing price transform remains authoritative. */
final class PublicDepthSnapshot
{
    public static function due($publishedAt, int $now): bool
    {
        return !is_numeric($publishedAt) || $now - (int)$publishedAt >= 10;
    }

    public static function fetch(string $symbol, bool $testnet = false): array
    {
        $base = $testnet ? 'https://testnet.binance.vision' : 'https://api.binance.com';
        $data = Http::connectTimeout(2)->timeout(3)->get($base.'/api/v3/depth', ['symbol'=>$symbol,'limit'=>20])->throw()->json();
        if (!is_array($data) || !is_array($data['bids'] ?? null) || !is_array($data['asks'] ?? null)
            || empty($data['lastUpdateId'])) throw new \RuntimeException('Invalid public depth snapshot');
        foreach (['bids','asks'] as $side) foreach ($data[$side] as $row) {
            if (!is_array($row) || count($row)!==2 || !is_numeric($row[0]) || !is_numeric($row[1])
                || (float)$row[0]<=0 || (float)$row[1]<=0) throw new \RuntimeException('Invalid public depth level');
        }
        return $data;
    }

    public static function plainPrices(iterable $rows, int $precision): array
    {
        $out=[];
        foreach ($rows as $row) {
            $price=(string)($row['price'] ?? '');
            // PHP may stringify a valid low price as 4.89E-5. Preserve ordinary
            // decimal bytes and express only scientific notation at market precision.
            if (preg_match('/^\d+(?:\.\d+)?[eE][+-]?\d+$/D',$price)) {
                $row['price']=number_format((float)$price,max(0,min(18,$precision)),'.','');
            }
            $out[]=$row;
        }
        return $out;
    }
}
