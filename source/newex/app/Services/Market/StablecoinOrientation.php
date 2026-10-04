<?php

namespace App\Services\Market;

use App\Models\Market\Market;

/** USDT/USDC uses USDC/USDT reference data; settlement stays in the named assets. */
final class StablecoinOrientation
{
    public static function inverse(Market $market): bool
    {
        return $market->name === 'USDT-USDC'
            && $market->baseCurrency->symbol === 'USDT'
            && $market->quoteCurrency->symbol === 'USDC';
    }

    public static function reciprocal($price): string
    {
        $price = (string) $price;
        if (!preg_match('/^\d+(?:\.\d+)?$/D', $price) || bccomp($price, '0', 18) <= 0) {
            throw new \InvalidArgumentException('INVALID_INVERSE_PRICE');
        }
        return bcdiv('1', $price, 18);
    }

    public static function depth(array $bids, array $asks): array
    {
        $convert = static function (array $rows): array {
            return array_map(static function (array $row): array {
                $price = self::reciprocal($row[0]);
                $quantity = (string) $row[1];
                if (!preg_match('/^\d+(?:\.\d+)?$/D', $quantity)) {
                    throw new \InvalidArgumentException('INVALID_INVERSE_QUANTITY');
                }
                return [$price, bcmul((string) $row[0], $quantity, 18)];
            }, $rows);
        };
        $out = ['bids' => $convert($asks), 'asks' => $convert($bids)];
        usort($out['bids'], fn($a, $b) => bccomp($b[0], $a[0], 18));
        usort($out['asks'], fn($a, $b) => bccomp($a[0], $b[0], 18));
        return $out;
    }

    public static function ticker(array $stats): array
    {
        return array_replace($stats, [
            'open' => self::reciprocal($stats['open']),
            'close' => self::reciprocal($stats['close']),
            'high' => self::reciprocal($stats['low']),
            'low' => self::reciprocal($stats['high']),
            'volume' => $stats['qVolume'], 'qVolume' => $stats['volume'],
        ]);
    }

    public static function candles(array $data): array
    {
        if (($data['s'] ?? null) !== 'ok') return $data;
        foreach ($data['t'] as $i => $_) {
            if (!isset($data['qv'][$i])) return ['s' => 'error', 'errmsg' => 'Quote volume unavailable'];
            [$open, $high, $low, $close] = [$data['o'][$i], $data['h'][$i], $data['l'][$i], $data['c'][$i]];
            $data['o'][$i] = (float) self::reciprocal($open);
            $data['h'][$i] = (float) self::reciprocal($low);
            $data['l'][$i] = (float) self::reciprocal($high);
            $data['c'][$i] = (float) self::reciprocal($close);
            $data['v'][$i] = (float) $data['qv'][$i];
        }
        unset($data['qv']);
        return $data;
    }
}
