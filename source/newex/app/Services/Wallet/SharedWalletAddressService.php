<?php

namespace App\Services\Wallet;

use App\Models\Wallet\Wallet;
use App\Models\Wallet\WalletAddress;
use Illuminate\Support\Facades\DB;

class SharedWalletAddressService
{
    public function syncTronAddresses(): void
    {
        $this->syncSharedAddresses([NETWORK_TRX, NETWORK_TRC], [
            NETWORK_TRX => ['TRX'],
        ]);
    }

    public function syncEvmAddresses(): void
    {
        $this->syncSharedAddresses([NETWORK_ETH, NETWORK_ERC, NETWORK_BNB, NETWORK_BEP, NETWORK_MATIC, NETWORK_MATIC20, NETWORK_XLAYER, NETWORK_XLAYER20], [
            NETWORK_ETH => ['ETH'],
            NETWORK_BNB => ['BNB'],
            NETWORK_MATIC => ['POL', 'MATIC'],
            NETWORK_XLAYER => ['OKB'],
        ]);
    }

    private function syncSharedAddresses(array $networkIds, array $nativeSymbolsByNetwork): void
    {
        $addresses = WalletAddress::query()
            ->whereIn('network_id', $networkIds)
            ->whereNotNull('user_id')
            ->whereNotNull('address')
            ->where('address', '!=', '')
            ->orderBy('id', 'asc')
            ->get();

        $addresses
            ->groupBy(function ($walletAddress) {
                return $walletAddress->user_id . ':' . mb_strtolower(trim((string) $walletAddress->address));
            })
            ->each(function ($group) use ($networkIds, $nativeSymbolsByNetwork) {
                $source = $group->first();
                $existingNetworkIds = $group->pluck('network_id')->map(function ($networkId) {
                    return (int) $networkId;
                })->all();

                foreach ($networkIds as $networkId) {
                    if (in_array((int) $networkId, $existingNetworkIds, true)) {
                        continue;
                    }

                    $this->createMissingAddress($source, (int) $networkId, $nativeSymbolsByNetwork[$networkId] ?? []);
                }
            });
    }

    private function createMissingAddress(WalletAddress $source, int $networkId, array $nativeSymbols): void
    {
        $walletId = $this->walletIdForNetwork($source, $nativeSymbols);

        if (!$walletId) {
            return;
        }

        $attributes = [
            'user_id' => $source->user_id,
            'network_id' => $networkId,
            'address' => $source->address,
        ];

        $values = [
            'activated' => $source->activated,
            'payment_id' => $source->payment_id,
            'wallet_id' => $walletId,
            'private_key' => $source->getRawOriginal('private_key'),
            'currency_id' => $source->currency_id,
            'deleted_at' => null,
            'updated_at' => now(),
        ];

        $existing = DB::table('wallet_addresses')
            ->where($attributes)
            ->first();

        if ($existing) {
            if ($existing->deleted_at !== null) {
                DB::table('wallet_addresses')
                    ->where('id', $existing->id)
                    ->update($values);
            }

            return;
        }

        DB::table('wallet_addresses')->insert($attributes + $values + [
            'created_at' => now(),
        ]);
    }

    private function walletIdForNetwork(WalletAddress $source, array $nativeSymbols): ?int
    {
        foreach ($nativeSymbols as $symbol) {
            $currencyId = DB::table('currencies')
                ->where('symbol', $symbol)
                ->orWhere('alt_symbol', $symbol)
                ->value('id');

            if (!$currencyId) {
                continue;
            }

            $wallet = Wallet::firstOrCreate([
                'user_id' => $source->user_id,
                'currency_id' => $currencyId,
            ]);

            return $wallet->id;
        }

        return $source->wallet_id ? (int) $source->wallet_id : null;
    }
}
