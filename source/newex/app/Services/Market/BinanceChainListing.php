<?php

namespace App\Services\Market;

use App\Models\{Currency\Currency, Market\Market, Wallet\Wallet};
use Illuminate\Support\Facades\{DB, Http};

/** Ranked listing metadata. Does not sign trades, enable transfers, mint funds or issue inventory. */
final class BinanceChainListing
{
    public const NETWORKS = [
        'BTC'=>['btc',null,null,'BTC'], 'ETH'=>['eth','erc20','contract','ETH'],
        'BSC'=>['bnb','bep20','bep_contract','BNB'], 'MATIC'=>['matic','matic20','matic_contract','POL'],
        'TRX'=>['trx','trc20','trc_contract','TRX'], 'SOL'=>['sol','solspl','sol_contract','SOL'],
        'XRP'=>['xrp',null,null,'XRP'], 'TON'=>['ton',null,null,'GRAM'], 'XLAYER'=>['xlayer','xlayer20','xlayer_contract','OKB'],
    ];

    public function fetch(?array $manifest = null): array
    {
        $products=Http::timeout(20)->get('https://www.binance.com/bapi/asset/v2/public/asset-service/product/get-products')->throw()->json('data');
        $exchangeUrl=rtrim(config('liquidity.market_data_base'),'/').'/api/v3/exchangeInfo';
        $exchange=[];
        if ($manifest !== null) {
            // Limit this large endpoint to reviewed symbols; a full exchange response is wasteful on release hosts.
            $symbols=array_values(array_unique(array_map(fn($asset)=>($asset['symbol']??'').'USDT',$manifest['assets']??[])));
            if (!$symbols || count($symbols)>500) throw new \RuntimeException('BINANCE_MANIFEST_INVALID');
            foreach(array_chunk($symbols,100) as $batch) {
                $part=Http::timeout(20)->get($exchangeUrl,['symbols'=>json_encode($batch)])->throw()->json('symbols');
                if (!is_array($part)) throw new \RuntimeException('BINANCE_METADATA_SCHEMA_INVALID');
                $exchange=array_merge($exchange,$part);
            }
        } else $exchange=Http::timeout(20)->get($exchangeUrl)->throw()->json('symbols');
        if (!is_array($products) || !is_array($exchange)) throw new \RuntimeException('BINANCE_METADATA_SCHEMA_INVALID');
        if ($manifest !== null) return $this->fromVerifiedManifest($manifest, $products, $exchange, DB::table('networks')->where('status',true)->get(['id','slug'])->all());
        $key=(string)config('liquidity.api_key');$secret=(string)config('liquidity.api_secret');
        if (!$key || !$secret) throw new \RuntimeException('BINANCE_NETWORK_METADATA_CREDENTIALS_MISSING');
        // Read-only wallet metadata endpoint; never retain or output its account balance fields.
        $query=http_build_query(['timestamp'=>(int)round(microtime(true)*1000),'recvWindow'=>10000]);
        try {
            $read=fn()=>Http::timeout(20)->withHeaders(['X-MBX-APIKEY'=>$key])->get('https://api.binance.com/sapi/v1/capital/config/getall?'.$query.'&signature='.hash_hmac('sha256',$query,$secret))->throw()->json();
            $coins=class_exists(\Laravel\Telescope\Telescope::class)?\Laravel\Telescope\Telescope::withoutRecording($read):$read();
        } catch (\Throwable $e) { throw new \RuntimeException('BINANCE_NETWORK_METADATA_UNAVAILABLE'); }
        if (!is_array($products) || !is_array($exchange) || !is_array($coins) || !array_is_list($coins))
            throw new \RuntimeException('BINANCE_METADATA_SCHEMA_INVALID');
        return $this->rank($products,$exchange,$coins,DB::table('networks')->where('status',true)->get(['id','slug'])->all());
    }

    public function rank(array $products,array $exchange,array $coins,array $enabledNetworks): array
    {
        $pairs=[];foreach($exchange as $pair) if (($pair['status']??null)==='TRADING' && ($pair['quoteAsset']??null)==='USDT' && ($pair['isSpotTradingAllowed']??false)===true) $pairs[$pair['baseAsset']]=$pair;
        $caps=[];foreach($products as $p) {
            if (($p['q']??null)!=='USDT' || ($p['st']??null)!=='TRADING' || !isset($pairs[$p['b']??'']) || ($p['s']??null)!==$pairs[$p['b']]['symbol']) continue;
            // Equity-token capitalization may represent the underlying issuer. Keep the existing stock listing path separate.
            if (($p['etf']??false) || in_array('bstocks',array_map('strtolower',$p['tags']??[]),true) || stripos((string)($p['an']??''),'bstocks')!==false) continue;
            if (!is_numeric($p['cs']??null)||!is_numeric($p['c']??null)||$p['cs']<=0||$p['c']<=0)continue;
            $cap=(float)$p['cs']*(float)$p['c'];if(!is_finite($cap))continue;
            $caps[$p['b']]=['marketCapUsdt'=>$cap,'circulatingSupply'=>(string)$p['cs'],'referencePrice'=>(string)$p['c'],'name'=>(string)($p['an']??$p['b'])];
        }
        $networkIds=[];foreach($enabledNetworks as $network)$networkIds[$network->slug]=(int)$network->id;
        $rows=[];$issues=[];
        foreach(self::NETWORKS as $networkCode=>[$nativeSlug,$tokenSlug,$field,$nativeSymbol]) {
            if (!isset($networkIds[$nativeSlug]) && (!$tokenSlug||!isset($networkIds[$tokenSlug]))) continue;
            $candidates=[];
            foreach($coins as $coin) {
                $symbol=$coin['coin']??'';
                if (!preg_match('/^[A-Z0-9]{2,20}$/D',$symbol) || !isset($caps[$symbol],$pairs[$symbol])) continue;
                foreach($coin['networkList']??[] as $network) {
                    if (($network['network']??null)!==$networkCode)continue;
                    if (isset($network['coin']) && $network['coin']!==$symbol)throw new \RuntimeException('BINANCE_NETWORK_IDENTITY_MISMATCH');
                    if ((string)($network['denomination']??'1')!=='1') {
                        $issues[]=['chain'=>$networkCode,'symbol'=>$symbol,'reason'=>'non_unit_denomination_requires_separate_adapter'];continue;
                    }
                    $native=$symbol===$nativeSymbol;
                    $slug=$native?$nativeSlug:$tokenSlug;
                    if(!$slug||!isset($networkIds[$slug]))continue;
                    $contract=$native?null:trim((string)($network['contractAddress']??''));
                    if (!$native && !$this->validContract($networkCode,$contract)) {
                        $issues[]=['chain'=>$networkCode,'symbol'=>$symbol,'reason'=>'verified_contract_missing'];continue;
                    }
                    $filters=collect($pairs[$symbol]['filters']??[])->keyBy('filterType');
                    $tick=$filters->get('PRICE_FILTER')['tickSize']??null;
                    $step=$filters->get('LOT_SIZE')['stepSize']??null;
                    if (!$this->validIncrement($tick)||!$this->validIncrement($step))continue;
                    if(isset($candidates[$symbol])&&$candidates[$symbol]['contract']!==$contract)throw new \RuntimeException('BINANCE_NETWORK_IDENTITY_MISMATCH');
                    $candidates[$symbol]=$caps[$symbol]+['symbol'=>$symbol,'chain'=>$networkCode,'networkSlug'=>$slug,'networkId'=>$networkIds[$slug],
                        'contract'=>$contract,'contractField'=>$native?null:$field,'native'=>$native,'market'=>$symbol.'-USDT','binanceSymbol'=>$pairs[$symbol]['symbol'],
                        'priceTick'=>$tick,'quantityStep'=>$step,'minQuantity'=>$filters->get('LOT_SIZE')['minQty']??$step,
                        'chainDecimals'=>$network['verifiedDecimals']??null,'identityEvidence'=>$network['identityEvidence']??null];
                }
            }
            usort($candidates,fn($a,$b)=>($b['marketCapUsdt']<=>$a['marketCapUsdt'])?:strcmp($a['symbol'],$b['symbol']));
            $rows[$networkCode]=array_slice($candidates,0,10);
            if(count($rows[$networkCode])<10)$issues[]=['chain'=>$networkCode,'selected'=>count($rows[$networkCode]),'reason'=>'fewer_than_ten_verified_supported_assets'];
        }
        return ['version'=>1,'observedAt'=>now()->toIso8601String(),'ranking'=>'Binance circulating supply × USDT spot price; quote currency USDT is not relisted',
            'sources'=>['https://www.binance.com/bapi/asset/v2/public/asset-service/product/get-products','https://data-api.binance.vision/api/v3/exchangeInfo','https://api.binance.com/sapi/v1/capital/config/getall'],
            'chains'=>$rows,'issues'=>$issues,'uniqueAssets'=>array_values(array_unique(array_column(array_merge(...array_values($rows?:[[]])),'symbol'))),
            'transfersEnabled'=>false,'inventoryIssued'=>'0'];
    }

    /** The operator pins the reviewed file's SHA-256; remote lists are never auto-applied. */
    public function readVerifiedManifest(string $path, string $expectedHash): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $expectedHash) || !is_file($path) || filesize($path) > 2000000)
            throw new \RuntimeException('BINANCE_MANIFEST_INVALID');
        $json = file_get_contents($path);
        if (!hash_equals($expectedHash, hash('sha256', $json))) throw new \RuntimeException('BINANCE_MANIFEST_HASH_MISMATCH');
        try { $manifest = json_decode($json, true, 64, JSON_THROW_ON_ERROR); }
        catch (\Throwable $e) { throw new \RuntimeException('BINANCE_MANIFEST_INVALID'); }
        if (!is_array($manifest)) throw new \RuntimeException('BINANCE_MANIFEST_INVALID');
        $manifest['_sha256'] = $expectedHash;
        return $manifest;
    }

    public function fromVerifiedManifest(array $manifest, array $products, array $exchange, array $enabledNetworks): array
    {
        if (($manifest['schema'] ?? null) !== 'deepro.binance-chain-identities.v1'
            || !is_array($manifest['assets'] ?? null) || !array_is_list($manifest['assets'])
            || !is_array($manifest['sources'] ?? null) || !$manifest['sources']
            || !is_string($manifest['observedAt'] ?? null) || !strtotime($manifest['observedAt'])
            || !preg_match('/^[a-f0-9]{64}$/D', $manifest['_sha256'] ?? ''))
            throw new \RuntimeException('BINANCE_MANIFEST_INVALID');
        // Contracts can outlive a source snapshot, but each application requires a recent identity review.
        $age = time() - strtotime($manifest['observedAt']);
        if ($age < -300 || $age > 7 * 86400) throw new \RuntimeException('BINANCE_MANIFEST_EXPIRED');
        foreach ($manifest['sources'] as $id => $source) {
            $url = $source['url'] ?? '';
            if (!is_string($id) || !is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)
                || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_USER) || parse_url($url, PHP_URL_PASS)
                || !is_string($source['retrievedAt'] ?? null) || !strtotime($source['retrievedAt']))
                throw new \RuntimeException('BINANCE_MANIFEST_SOURCE_INVALID');
        }
        $coins = []; $seen = [];
        foreach ($manifest['assets'] as $asset) {
            $symbol = $asset['symbol'] ?? ''; $chain = $asset['chain'] ?? '';
            if (!is_string($symbol) || !preg_match('/^[A-Z0-9]{2,20}$/D', $symbol) || !isset(self::NETWORKS[$chain])
                || !is_bool($asset['native'] ?? null) || !is_int($asset['decimals'] ?? null)
                || $asset['decimals'] < 0 || $asset['decimals'] > 30 || !is_string($asset['identity'] ?? null)
                || trim($asset['identity']) === '' || !is_array($asset['sourceIds'] ?? null) || count($asset['sourceIds']) < 2
                || count(array_unique($asset['sourceIds'])) !== count($asset['sourceIds']))
                throw new \RuntimeException('BINANCE_MANIFEST_IDENTITY_INVALID');
            foreach ($asset['sourceIds'] as $id) if (!is_string($id) || !isset($manifest['sources'][$id]))
                throw new \RuntimeException('BINANCE_MANIFEST_SOURCE_INVALID');
            $native = $symbol === self::NETWORKS[$chain][3];
            $contract = $asset['contract'] ?? null;
            if ($asset['native'] !== $native || ($native && $contract !== null)
                || (!$native && (!is_string($contract) || !$this->validContract($chain, $contract))))
                throw new \RuntimeException('BINANCE_MANIFEST_IDENTITY_INVALID');
            if (isset($seen[$chain.':'.$symbol])) throw new \RuntimeException('BINANCE_MANIFEST_DUPLICATE_IDENTITY');
            $seen[$chain.':'.$symbol] = true;
            if ($contract !== null) {
                $contractKey = $chain.':contract:'.(str_starts_with($contract,'0x') ? strtolower($contract) : $contract);
                if (isset($seen[$contractKey])) throw new \RuntimeException('BINANCE_MANIFEST_DUPLICATE_IDENTITY');
                $seen[$contractKey] = true;
            }
            $coins[$symbol]['coin'] = $symbol;
            $coins[$symbol]['networkList'][] = ['network'=>$chain,'coin'=>$symbol,'contractAddress'=>$contract,'denomination'=>'1',
                'verifiedDecimals'=>$asset['decimals'],'identityEvidence'=>['identity'=>$asset['identity'],'sourceIds'=>$asset['sourceIds']]];
        }
        // Prices, listing status and order increments always come from a fresh Binance response, not the manifest.
        $plan = $this->rank($products, $exchange, array_values($coins), $enabledNetworks);
        $plan['metadataMode'] = 'reviewed_public_identity_manifest';
        $plan['manifestSha256'] = $manifest['_sha256'];
        $plan['identityObservedAt'] = $manifest['observedAt'];
        $plan['identitySources'] = $manifest['sources'];
        $plan['sources'] = array_slice($plan['sources'], 0, 2);
        $plan['coverage'] = $manifest['coverage'] ?? [];
        $selected = array_fill_keys($plan['uniqueAssets'], true);
        foreach ($coins as $symbol=>$coin) if (!isset($selected[$symbol]))
            $plan['issues'][] = ['symbol'=>$symbol,'reason'=>'not_selected_by_current_binance_spot_capitalization_and_enabled_networks'];
        return $plan;
    }

    private function validContract(string $chain,string $contract):bool
    {
        return match($chain) {
            'ETH','BSC','MATIC','XLAYER'=>(bool)preg_match('/^0x[0-9a-fA-F]{40}$/D',$contract)&&!preg_match('/^0x0{40}$/D',$contract),
            'TRX'=>(bool)preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/D',$contract),
            'SOL'=>(bool)preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/D',$contract),default=>false,
        };
    }
    private function validIncrement($value):bool { return is_string($value)&&(bool)preg_match('/^(?:0\.0*1[0]*|1(?:\.0+)?)$/D',$value); }
    private function precision(string $value):int { return str_contains($value,'.')?strlen(rtrim(explode('.',$value)[1],'0')):0; }

    /** Archived markets retain their history; only one correctly linked live market may be reused. */
    public function resolveExistingMarket(string $symbol, ?int $currencyId, int $quoteId): ?Market
    {
        $markets=Market::whereName($symbol.'-USDT')->get();
        if($markets->count()>1)throw new \RuntimeException('EXISTING_MARKET_IDENTITY_CONFLICT:'.$symbol);
        $market=$markets->first();
        if(!$market && Market::withTrashed()->whereName($symbol.'-USDT')->exists())
            throw new \RuntimeException('EXISTING_MARKET_IDENTITY_CONFLICT:'.$symbol);
        if($market&&($currencyId===null||(int)$market->base_currency_id!==$currencyId||(int)$market->quote_currency_id!==$quoteId))
            throw new \RuntimeException('EXISTING_MARKET_IDENTITY_CONFLICT:'.$symbol);
        return $market;
    }

    /** Shared read-only preflight; apply repeats it under its transaction lock before writing. */
    public function validateExistingIdentities(array $plan): void
    {
        $quote=Currency::whereSymbol('USDT')->sole();$nativeAssets=[];
        foreach($plan['chains'] as $rows)foreach($rows as $asset)if($asset['native'])$nativeAssets[$asset['symbol']]=true;
        if (isset($nativeAssets['GRAM'])) {
            // A rename must migrate one existing economic asset, never create a second wallet ledger.
            if (Currency::withTrashed()->whereSymbol('TON')->exists() || Market::withTrashed()->whereName('TON-USDT')->exists())
                throw new \RuntimeException('EXISTING_NATIVE_ALIAS_CONFLICT:TON:GRAM');
            foreach($plan['chains']['TON']??[] as $asset)if($asset['symbol']==='GRAM' && $asset['native']) {
                $other=DB::table('currency_networks')->join('currencies','currencies.id','=','currency_networks.currency_id')
                    ->where('currency_networks.network_id',$asset['networkId'])->where('currencies.symbol','<>','GRAM')->exists();
                if($other)throw new \RuntimeException('EXISTING_NATIVE_NETWORK_CONFLICT:TON');
            }
        }
        foreach($plan['chains'] as $rows)foreach($rows as $asset) {
            $symbol=$asset['symbol'];$currencies=Currency::withTrashed()->whereSymbol($symbol)->get();
            if($currencies->count()>1)throw new \RuntimeException('EXISTING_ASSET_IDENTITY_CONFLICT:'.$symbol);
            $currency=$currencies->first();
            if($currency&&($currency->trashed()||$currency->type!=='coin'||in_array($currency->asset_category,['stock','etf'],true)))
                throw new \RuntimeException('EXISTING_ASSET_IDENTITY_CONFLICT:'.$symbol);
            $field=$asset['contractField'];
            if($currency&&$field) {
                $current=trim((string)$currency->{$field});
                $same=str_starts_with($asset['contract'],'0x')?strtolower($current)===strtolower($asset['contract']):$current===$asset['contract'];
                if($current&&!$same)throw new \RuntimeException('EXISTING_CONTRACT_CONFLICT:'.$symbol.':'.$asset['chain']);
            }
            $this->resolveExistingMarket($symbol,$currency?(int)$currency->id:null,(int)$quote->id);
        }
    }

    public function apply(array $plan):array
    {
        $applied=[];$nativeAssets=[];
        foreach($plan['chains'] as $rows)foreach($rows as $asset)if($asset['native'])$nativeAssets[$asset['symbol']]=true;
        return DB::transaction(function() use($plan,$nativeAssets,&$applied) {
            DB::statement('SELECT pg_advisory_xact_lock(8192035)');
            $this->validateExistingIdentities($plan);
            $quote=Currency::whereSymbol('USDT')->sole();
            foreach($plan['chains'] as $rows)foreach($rows as $asset) {
                $symbol=$asset['symbol'];$existingCurrencies=Currency::withTrashed()->whereSymbol($symbol)->get();
                if($existingCurrencies->count()>1)throw new \RuntimeException('EXISTING_ASSET_IDENTITY_CONFLICT:'.$symbol);
                $currency=$existingCurrencies->first();
                if($currency&&($currency->trashed()||$currency->type!=='coin'||in_array($currency->asset_category,['stock','etf'],true)))throw new \RuntimeException('EXISTING_ASSET_IDENTITY_CONFLICT:'.$symbol);
                if(!$currency)$currency=Currency::withoutEvents(fn()=>Currency::forceCreate(['name'=>$asset['name'],'symbol'=>$symbol,'type'=>'coin','is_token'=>!isset($nativeAssets[$symbol]),
                    'decimals'=>8,'status'=>true,'deposit_status'=>false,'withdraw_status'=>false,'rate'=>$asset['referencePrice'],'asset_category'=>'crypto']));
                $field=$asset['contractField'];$contractAdded=false;
                if($field) {
                    $current=trim((string)$currency->{$field});$same=str_starts_with($asset['contract'],'0x')?strtolower($current)===strtolower($asset['contract']):$current===$asset['contract'];
                    if($current&&!$same)throw new \RuntimeException('EXISTING_CONTRACT_CONFLICT:'.$symbol.':'.$asset['chain']);
                    if(!$current){$currency->{$field}=$asset['contract'];$contractAdded=true;}
                }
                $networkAttached=DB::table('currency_networks')->where('currency_id',$currency->id)->where('network_id',$asset['networkId'])->exists();
                if(!$networkAttached || $contractAdded) {
                    foreach(['disabled_deposit_networks','disabled_withdrawal_networks'] as $disabledField) {
                        $disabled=$currency->{$disabledField}??[];
                        if(is_string($disabled))$disabled=json_decode($disabled,true)??[];
                        $currency->{$disabledField}=array_values(array_unique(array_merge(array_map('intval',(array)$disabled),[$asset['networkId']])));
                    }
                    if(!$networkAttached)DB::table('currency_networks')->insert(['currency_id'=>$currency->id,'network_id'=>$asset['networkId']]);
                }
                if (isset($asset['chainDecimals'], $asset['identityEvidence'])) {
                    $reference = $currency->asset_reference ?? [];
                    $reference['binanceChainListing']['networks'][$asset['chain']] = [
                        'native'=>$asset['native'],'contract'=>$asset['contract'],'decimals'=>$asset['chainDecimals'],
                        'identity'=>$asset['identityEvidence']['identity'],'sourceIds'=>$asset['identityEvidence']['sourceIds'],
                        'manifestSha256'=>$plan['manifestSha256']??null,'observedAt'=>$plan['identityObservedAt']??null,
                    ];
                    $currency->asset_reference = $reference;
                }
                $currency->save();
                $market=$this->resolveExistingMarket($symbol,(int)$currency->id,(int)$quote->id);
                if(!$market)$market=Market::forceCreate(['name'=>$asset['market'],'base_currency_id'=>$currency->id,'quote_currency_id'=>$quote->id,
                    'base_precision'=>$this->precision($asset['quantityStep']),'base_ticker_size'=>$asset['quantityStep'],'quote_precision'=>$this->precision($asset['priceTick']),'quote_ticker_size'=>$asset['priceTick'],
                    'status'=>true,'trade_status'=>true,'buy_order_status'=>true,'sell_order_status'=>true,'cancel_order_status'=>true,
                    'chart_source'=>'binance','is_tradingview'=>'liquidity','chart_default_resolution'=>'60','switch_chart'=>true,'liq'=>true,
                    'custom_liquidity'=>false,'has_futures'=>false,'has_options'=>false]);
                if(!isset($applied[$symbol])) {
                    DB::table('users')->select('id')->whereNotExists(function($q)use($currency){$q->selectRaw('1')->from('wallets')->whereColumn('wallets.user_id','users.id')->where('currency_id',$currency->id);})
                        ->orderBy('id')->chunkById(100,function($users)use($currency){foreach($users as $user)Wallet::firstOrCreate(['user_id'=>$user->id,'currency_id'=>$currency->id]);});
                    $applied[$symbol]=['symbol'=>$symbol,'currencyId'=>$currency->id,'market'=>$market->name,'marketId'=>$market->id];
                }
            }
            DB::afterCommit(fn()=>(new MarketService())->updateMarketsInfoCache());
            return array_values($applied);
        });
    }
}
