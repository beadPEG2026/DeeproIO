<?php

namespace Tests\Unit;

use App\Models\Currency\Currency;
use App\Models\Network\Network;
use App\Services\Wallet\{AssetNetworkOptions, NetworkName};
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

final class AssetNetworkOptionsTest extends TestCase
{
    private function asset(string $symbol, array $fields = [], array $ids = [2,3,5,6,15,16,24,25]): Currency
    {
        $slugs = [2=>'eth',3=>'erc20',5=>'bnb',6=>'bep20',15=>'matic',16=>'matic20',24=>'xlayer',25=>'xlayer20'];
        $asset = new Currency();
        $asset->forceFill(['symbol'=>$symbol] + $fields);
        $asset->setRelation('networks', new Collection(array_map(function ($id) use ($slugs) {
            $network = new Network();
            $network->forceFill(['id'=>$id,'slug'=>$slugs[$id],'name'=>'Legacy label']);
            return $network;
        }, $ids)));
        return $asset;
    }

    public function test_stablecoin_has_one_token_route_per_chain_and_keeps_real_ids(): void
    {
        $options = AssetNetworkOptions::forCurrency($this->asset('USDT', ['contract'=>'0x1','bep_contract'=>'0x2','matic_contract'=>'0x3','xlayer_contract'=>'0x4']));
        $this->assertSame([3,6,16,25], array_column($options, 'id'));
        $this->assertSame(['token'], array_values(array_unique(array_column($options, 'kind'))));
        $this->assertCount(4, array_unique(array_column($options, 'chain')));
    }

    public function test_native_asset_prefers_native_route_but_keeps_cross_chain_tokens(): void
    {
        $this->assertSame([2,6], array_column(AssetNetworkOptions::forCurrency($this->asset('ETH', ['contract'=>'stale','bep_contract'=>'0x2'])), 'id'));
        $this->assertSame([15], array_column(AssetNetworkOptions::forCurrency($this->asset('POL', ['matic_contract'=>'stale'])), 'id'));
        $this->assertSame([24], array_column(AssetNetworkOptions::forCurrency($this->asset('OKB', ['xlayer_contract'=>'stale'])), 'id'));
    }

    public function test_options_require_existing_association_contract_and_supported_scope(): void
    {
        $this->assertSame([], AssetNetworkOptions::forCurrency($this->asset('USDT')));
        $asset = $this->asset('USDT', ['contract'=>'0x1','bep_contract'=>'0x2'], [3]);
        $this->assertSame([3], array_column(AssetNetworkOptions::forCurrency($asset), 'id'));
        $this->assertSame([], AssetNetworkOptions::forCurrency($asset, [6]));
    }

    public function test_stock_restriction_and_distinct_polygon_labels_are_preserved(): void
    {
        $asset = $this->asset('QQQon', ['asset_category'=>'etf','contract'=>'0x1','bep_contract'=>'0x2']);
        $this->assertSame([6], array_column(AssetNetworkOptions::forCurrency($asset), 'id'));
        $this->assertNotSame(NetworkName::display(15), NetworkName::display(16));
        $this->assertSame('X Layer (ERC20)', NetworkName::display(25));
    }

    public function test_public_guard_preserves_every_legal_native_and_token_route(): void
    {
        foreach (['eth'=>'ETH','bnb'=>'BNB','matic'=>'POL','xlayer'=>'OKB','sol'=>'SOL','trx'=>'TRX','btc'=>'BTC','xrp'=>'XRP','ton'=>'TON'] as $slug=>$symbol) {
            $this->assertFalse(AssetNetworkOptions::nativeMismatch((object)['symbol'=>$symbol],$slug));
            $this->assertTrue(AssetNetworkOptions::nativeMismatch((object)['symbol'=>'USDT'],$slug));
        }
        $this->assertFalse(AssetNetworkOptions::nativeMismatch((object)['symbol'=>'MATIC'],'matic'));
        foreach (['USDT','USDC','UMI','BTC'] as $symbol) {
            foreach (['erc20','bep20','trc20','matic20','xlayer20','solspl','brc20','coinpayments','internal','unknown'] as $slug) {
                $this->assertFalse(AssetNetworkOptions::nativeMismatch((object)['symbol'=>$symbol],$slug));
            }
        }
        $this->assertFalse(AssetNetworkOptions::nativeMismatch((object)[],'eth'));
        $this->assertFalse(AssetNetworkOptions::nativeMismatch((object)['symbol'=>'USDT'],null));
    }
}
