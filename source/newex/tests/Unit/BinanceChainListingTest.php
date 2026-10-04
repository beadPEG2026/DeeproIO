<?php
namespace Tests\Unit;
use App\Services\Market\BinanceChainListing;
use PHPUnit\Framework\TestCase;

final class BinanceChainListingTest extends TestCase
{
    private function fixture():array {
        $products=$pairs=$coins=[];
        for($i=1;$i<=12;$i++) {
            $s='COIN'.$i;
            $products[]=['s'=>$s.'USDT','b'=>$s,'q'=>'USDT','st'=>'TRADING','cs'=>1000,'c'=>$i,'an'=>$s];
            $pairs[]=['symbol'=>$s.'USDT','baseAsset'=>$s,'quoteAsset'=>'USDT','status'=>'TRADING','isSpotTradingAllowed'=>true,
                'filters'=>[['filterType'=>'PRICE_FILTER','tickSize'=>'0.01000000'],['filterType'=>'LOT_SIZE','stepSize'=>'0.00100000','minQty'=>'0.00100000']]];
            $coins[]=['coin'=>$s,'free'=>'9999','networkList'=>[['network'=>'ETH','coin'=>$s,'contractAddress'=>'0x'.str_pad(dechex($i),40,'0',STR_PAD_LEFT)]]];
        }
        return [$products,$pairs,$coins,[(object)['id'=>3,'slug'=>'erc20']]];
    }
    public function test_ranks_capitalization_not_volume_and_limits_each_chain():void {
        [$p,$e,$c,$n]=$this->fixture();$p[0]['v']=1e12;$plan=(new BinanceChainListing)->rank($p,$e,$c,$n);
        $this->assertCount(10,$plan['chains']['ETH']);$this->assertSame('COIN12',$plan['chains']['ETH'][0]['symbol']);
        $this->assertSame('COIN3',$plan['chains']['ETH'][9]['symbol']);$this->assertFalse($plan['transfersEnabled']);
        $this->assertStringNotContainsString('9999',json_encode($plan));
    }
    public function test_missing_or_zero_contract_and_nontrading_symbols_cannot_be_listed():void {
        [$p,$e,$c,$n]=$this->fixture();$c[11]['networkList'][0]['contractAddress']='';$c[10]['networkList'][0]['contractAddress']='0x'.str_repeat('0',40);$e[9]['status']='BREAK';
        $plan=(new BinanceChainListing)->rank($p,$e,$c,$n);
        $this->assertCount(9,$plan['chains']['ETH']);$this->assertNotContains('COIN12',$plan['uniqueAssets']);
        $this->assertNotContains('COIN10',$plan['uniqueAssets']);$this->assertCount(3,$plan['issues']);
    }
    public function test_same_asset_on_two_chains_has_one_currency_identity():void {
        [$p,$e,$c,$n]=$this->fixture();$n[]=(object)['id'=>6,'slug'=>'bep20'];
        foreach($c as &$coin){$other=$coin['networkList'][0];$other['network']='BSC';$coin['networkList'][]=$other;}
        $plan=(new BinanceChainListing)->rank($p,$e,$c,$n);$this->assertCount(10,$plan['uniqueAssets']);$this->assertCount(10,$plan['chains']['BSC']);
    }
    public function test_network_code_must_match_parent_asset():void {
        [$p,$e,$c,$n]=$this->fixture();$c[0]['networkList'][0]['coin']='OTHER';
        $this->expectException(\RuntimeException::class);(new BinanceChainListing)->rank($p,$e,$c,$n);
    }
    public function test_bitcoin_network_does_not_inherit_unrelated_native_coins():void {
        [$p,$e,$c,$n]=$this->fixture();$n=[(object)['id'=>1,'slug'=>'btc']];
        foreach($c as &$coin)$coin['networkList'][0]['network']='BTC';
        $plan=(new BinanceChainListing)->rank($p,$e,$c,$n);$this->assertSame([],$plan['chains']['BTC']);
    }
    public function test_non_unit_denomination_is_not_mistaken_for_one_onchain_token():void {
        [$p,$e,$c,$n]=$this->fixture();$c[11]['networkList'][0]['denomination']=1000000;
        $plan=(new BinanceChainListing)->rank($p,$e,$c,$n);$this->assertNotContains('COIN12',$plan['uniqueAssets']);
        $this->assertSame('non_unit_denomination_requires_separate_adapter',$plan['issues'][0]['reason']);
    }
    public function test_stock_issuer_market_cap_is_not_ranked_as_crypto_token_market_cap():void {
        [$p,$e,$c,$n]=$this->fixture();$p[11]['tags']=['bStocks'];$p[10]['etf']=true;
        $plan=(new BinanceChainListing)->rank($p,$e,$c,$n);$this->assertNotContains('COIN12',$plan['uniqueAssets']);$this->assertNotContains('COIN11',$plan['uniqueAssets']);
    }

    private function manifest(): array
    {
        return ['schema'=>'deepro.binance-chain-identities.v1','observedAt'=>gmdate('c'),'_sha256'=>str_repeat('a',64),
            'sources'=>['issuer'=>['url'=>'https://issuer.example/contracts','retrievedAt'=>gmdate('c')],
                'list'=>['url'=>'https://list.example/tokens.json','retrievedAt'=>gmdate('c')]],
            'assets'=>[['symbol'=>'COIN12','chain'=>'ETH','native'=>false,'contract'=>'0x'.str_pad(dechex(12),40,'0',STR_PAD_LEFT),
                'decimals'=>6,'identity'=>'Verified Coin Twelve','sourceIds'=>['issuer','list']]]];
    }
    public function test_reviewed_manifest_preserves_verified_decimals_but_gets_fresh_market_data(): void
    {
        [$p,$e,,$n]=$this->fixture();$manifest=$this->manifest();$p[11]['c']='25.5';
        $plan=(new BinanceChainListing)->fromVerifiedManifest($manifest,$p,$e,$n);
        $this->assertSame('25.5',$plan['chains']['ETH'][0]['referencePrice']);
        $this->assertSame(6,$plan['chains']['ETH'][0]['chainDecimals']);
        $this->assertSame($manifest['_sha256'],$plan['manifestSha256']);
        $this->assertSame('reviewed_public_identity_manifest',$plan['metadataMode']);
        $this->assertCount(2,$plan['sources']);
    }
    public function test_manifest_cannot_override_current_binance_delisting(): void
    {
        [$p,$e,,$n]=$this->fixture();$e[11]['status']='BREAK';
        $plan=(new BinanceChainListing)->fromVerifiedManifest($this->manifest(),$p,$e,$n);
        $this->assertSame([],$plan['uniqueAssets']);
    }
    public function test_manifest_rejects_native_and_contract_identity_mismatch(): void
    {
        [$p,$e,,$n]=$this->fixture();$manifest=$this->manifest();$manifest['assets'][0]['native']=true;
        $this->expectExceptionMessage('BINANCE_MANIFEST_IDENTITY_INVALID');
        (new BinanceChainListing)->fromVerifiedManifest($manifest,$p,$e,$n);
    }
    public function test_manifest_requires_two_distinct_valid_sources(): void
    {
        [$p,$e,,$n]=$this->fixture();$manifest=$this->manifest();$manifest['assets'][0]['sourceIds']=['issuer','issuer'];
        $this->expectExceptionMessage('BINANCE_MANIFEST_IDENTITY_INVALID');
        (new BinanceChainListing)->fromVerifiedManifest($manifest,$p,$e,$n);
    }
    public function test_manifest_expiration_prevents_old_contract_reviews_being_reused(): void
    {
        [$p,$e,,$n]=$this->fixture();$manifest=$this->manifest();$manifest['observedAt']=gmdate('c',time()-8*86400);
        $this->expectExceptionMessage('BINANCE_MANIFEST_EXPIRED');
        (new BinanceChainListing)->fromVerifiedManifest($manifest,$p,$e,$n);
    }
    public function test_manifest_hash_is_checked_before_decoding_or_applying(): void
    {
        $path=tempnam(sys_get_temp_dir(),'listing-');file_put_contents($path,json_encode($this->manifest()));
        try {
            $this->assertSame(hash_file('sha256',$path),(new BinanceChainListing)->readVerifiedManifest($path,hash_file('sha256',$path))['_sha256']);
            $this->expectExceptionMessage('BINANCE_MANIFEST_HASH_MISMATCH');
            (new BinanceChainListing)->readVerifiedManifest($path,str_repeat('0',64));
        } finally {unlink($path);}
    }
}
