<?php

namespace App\Services\Wallet;

final class NetworkName
{
    public static function display(int $id, string $fallback = ''): string
    {
        return [2 => 'Ethereum (ETH)', 3 => 'Ethereum (ERC20)', 5 => 'BNB Smart Chain (BSC)', 6 => 'BNB Smart Chain (BEP20)', 7 => 'TRON (TRX)', 8 => 'TRON (TRC20)', 9 => 'Bitcoin (BTC)', 15 => 'Polygon (POL)', 16 => 'Polygon (ERC20)', 20 => 'Solana (SOL)', 21 => 'Solana (SPL)', 22 => 'XRP Ledger', 24 => 'X Layer (OKB)', 25 => 'X Layer (ERC20)',
            23 => 'TON Network'][$id] ?? $fallback;
    }
}
