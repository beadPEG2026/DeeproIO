<?php

namespace App\Services\Deposit;

use App\Services\PaymentGateways\Coin\Bitcoin\Services\BitcoinService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB, Http};
use RuntimeException;

/** Discover wallet receipts; the existing Bitcoin service still owns crediting. */
final class BitcoinWalletScanner
{
    public const STATE = 'deposits:bitcoin:wallet-scan:v1';

    public function run(bool $dryRun = false): array
    {
        $lock = Cache::lock(self::STATE . ':lock', 3600);
        if (!$lock->get()) return ['status' => 'busy'];
        try {
            $currency = DB::table('currencies')->where('symbol', 'BTC')->whereNull('deleted_at')->first();
            $network = DB::table('networks')->where('slug', 'btc')->first();
            if (config('app.readonly') || !$currency || !$network || !$currency->status || !$currency->deposit_status
                || !$network->status || !$network->deposit_status) return ['status' => 'disabled'];
            $disabled = json_decode($currency->disabled_deposit_networks ?: '[]', true);
            if (!is_array($disabled)) $disabled = preg_split('/[,;\s]+/', trim($currency->disabled_deposit_networks), -1, PREG_SPLIT_NO_EMPTY);
            if (in_array($network->id, array_map('intval', $disabled), true)
                || !DB::table('currency_networks')->where('currency_id', $currency->id)->where('network_id', $network->id)->exists()) return ['status' => 'disabled'];

            if(app(\App\Services\Wallet\BitcoinWalletManager::class)->active())return $this->managed($dryRun,$currency,$network);

            $chain = $this->rpc('getblockchaininfo');
            $wallet = $this->rpc('getwalletinfo');
            if (($chain['chain'] ?? null) !== 'main' || ($chain['initialblockdownload'] ?? true)
                || ($chain['verificationprogress'] ?? 0) < 0.999 || !isset($wallet['walletname'])
                || ($wallet['scanning'] ?? false) !== false) throw new RuntimeException('BTC_WALLET_NOT_READY');

            $state = Cache::get(self::STATE, []);
            // Keep an overlap and recheck pending receipts, including after downtime.
            $history = $this->rpc('listsinceblock', [$state['block'] ?? '', max(6, (int) $currency->min_deposit_confirmation + 1), false, true]);
            if (!is_array($history['transactions'] ?? null) || !preg_match('/^[a-f0-9]{64}$/', $history['lastblock'] ?? '')) throw new RuntimeException('BTC_INVALID_WALLET_HISTORY');
            $addresses = DB::table('wallet_addresses')->where('network_id', $network->id)->whereNull('deleted_at')
                ->whereIn('address', array_column($history['transactions'], 'address'))->pluck('address')->flip();
            $txns = [];
            foreach ($history['transactions'] as $tx) {
                if (($tx['category'] ?? '') === 'receive' && ($tx['amount'] ?? 0) > 0 && $addresses->has($tx['address'] ?? '')
                    && preg_match('/^[a-f0-9]{64}$/', $tx['txid'] ?? '')) $txns[$tx['txid']] = true;
            }
            $deposits = DB::table('deposits')->where('currency_id', $currency->id)->where('network_id', $network->id);
            foreach ((clone $deposits)->where('status', DEPOSIT_PENDING)->pluck('txn') as $txn) {
                if (preg_match('/^[a-f0-9]{64}$/', $txn)) $txns[$txn] = true;
            }
            // Removed outputs and credited overlapping receipts still require chain review.
            foreach ($history['removed'] ?? [] as $removed) {
                if (($removed['category'] ?? '') === 'receive' && preg_match('/^[a-f0-9]{64}$/D', $removed['txid'] ?? '')
                    && (clone $deposits)->where('txn', $removed['txid'])->where('status', DEPOSIT_CONFIRMED)->exists()) $txns[$removed['txid']] = true;
            }
            foreach ((clone $deposits)->whereIn('txn', array_keys($txns))->where('status', DEPOSIT_IGNORED)->pluck('txn') as $txn) unset($txns[$txn]);
            $result = ['status' => $dryRun ? 'dry_run' : 'ok', 'height' => $chain['blocks'], 'candidates' => count($txns), 'processed' => 0];
            if ($dryRun) return $result;
            $offset = count($txns) > 25 ? ((int) ($state['offset'] ?? 0) % count($txns)) : 0;
            $ordered = array_keys($txns);
            $ordered = array_merge(array_slice($ordered, $offset), array_slice($ordered, 0, $offset));
            foreach (array_slice($ordered, 0, 25) as $txn) {
                $credited=(clone $deposits)->where('txn',$txn)->where('status',DEPOSIT_CONFIRMED)->get();
                if ($credited->isNotEmpty()) {
                    $proof=$this->rpc('gettransaction',[$txn]);
                    if (($proof['txid']??null)!==$txn) throw new RuntimeException('BTC_INVALID_TRANSACTION');
                    if (!empty($proof['walletconflicts']) || (int)($proof['confirmations']??0)<max(6,(int)$currency->min_deposit_confirmation)) {
                        foreach ($credited as $deposit) app(DepositRisk::class)->record($deposit,'bitcoin',['confirmations'=>$proof['confirmations']??0,'wallet_conflicts'=>$proof['walletconflicts']??[],'legacy_wallet'=>true]);
                    }
                    $result['processed']++;
                    continue; // Never replay a credited callback or fabricate a compensating balance.
                }
                $original = app('request');
                try {
                    app()->instance('request', Request::create('/api/gateways/bitcoin/BTC', 'GET', ['txn' => $txn]));
                    if (!app(BitcoinService::class)->handleCallback('BTC')) throw new RuntimeException('BTC_DEPOSIT_CALLBACK_FAILED');
                } finally { app()->instance('request', $original); }
                $result['processed']++;
            }
            $remaining = count($txns) > 25;
            Cache::forever(self::STATE, ['block' => $remaining ? ($state['block'] ?? '') : $history['lastblock'], 'offset' => $remaining ? $offset + 25 : 0, 'checked_at' => now()->toIso8601String(), 'height' => $chain['blocks'], 'processed' => $result['processed'], 'backlog' => $remaining]);
            return $result + ['backlog' => $remaining];
        } finally { $lock->release(); }
    }

    private function managed(bool $dryRun,object $currency,object $network): array
    {
        $rpc=app(\App\Services\Wallet\BitcoinWalletRpc::class);$results=[];$processed=0;
        foreach(DB::table('bitcoin_wallets')->where('scan_enabled',true)->orderBy('id')->get() as $wallet){
            $key=self::STATE.':wallet:'.$wallet->id;
            try {
                $rpc->ready($wallet->name);$state=Cache::get($key,[]);
                $history=$rpc->call('listsinceblock',[$state['block']??'',max(6,(int)$currency->min_deposit_confirmation+1),false,true],$wallet->name);
                if(!is_array($history['transactions']??null)||!preg_match('/^[a-f0-9]{64}$/D',$history['lastblock']??''))throw new RuntimeException('BTC_INVALID_WALLET_HISTORY');
                $txns=[];foreach($history['removed']??[] as $removed)if(($removed['category']??'')==='receive' && isset($removed['txid']))$txns[$removed['txid']]=true;
                foreach($history['transactions'] as $t)if(($t['category']??'')==='receive')$txns[$t['txid']]=true;
                foreach(DB::table('deposits')->where('network_id',$network->id)->where('status',DEPOSIT_PENDING)->pluck('txn') as $txn)$txns[$txn]=true;
                $ids=array_keys($txns);$offset=count($ids)>25?((int)($state['offset']??0)%count($ids)):0;
                $done=0;
                foreach(array_slice($ids,$offset,25) as $txn){if(!$dryRun)app(BitcoinManagedDeposits::class)->ingest($wallet,$txn);$done++;}
                // When a batch is larger than one run, retain the block and rotate fairly.
                if(!$dryRun)Cache::forever($key,['block'=>$offset+25<count($ids)?($state['block']??''):$history['lastblock'],'offset'=>$offset+25<count($ids)?$offset+25:0,'checked_at'=>now()->toIso8601String(),'processed'=>$done]);
                $processed+=$done;$results[]=['wallet_id'=>$wallet->id,'status'=>'ok','processed'=>$done];
            }catch(\Throwable $e){$error=preg_match('/^BTC_[A-Z_0-9]+$/D',$e->getMessage())?$e->getMessage():'BTC_SCAN_REVIEW';if(!$dryRun)Cache::forever($key,array_merge(Cache::get($key,[]),['error'=>$error,'checked_at'=>now()->toIso8601String()]));$results[]=['wallet_id'=>$wallet->id,'status'=>'review','error'=>$error];}
        }
        $result=['status'=>$dryRun?'dry_run':(collect($results)->contains('status','review')||!$results?'review':'ok'),'processed'=>$processed,'wallets'=>$results];
        if(!$dryRun){
            $height=null;try{$height=$rpc->call('getblockchaininfo')['blocks']??null;}catch(\Throwable $e){$result['status']='review';}
            Cache::forever(self::STATE,['mode'=>'managed','checked_at'=>now()->toIso8601String(),'height'=>$height,'status'=>$result['status'],'wallets'=>$results]);
        }
        return $result;
    }

    private function rpc(string $method, array $params = []): array
    {
        // Wallet credentials and wallet RPC methods must never go to the public query provider.
        try {
            $url = config('bitcoind.default.scheme') . '://' . config('bitcoind.default.host') . ':' . config('bitcoind.default.port');
            $legacy=\Illuminate\Support\Facades\DB::table('bitcoin_wallet_control')->where('id',1)->value('legacy_wallet_name');
            if($legacy!==null && $method!=='getblockchaininfo')$url.='/wallet/'.rawurlencode($legacy);
            $response = Http::connectTimeout(3)->timeout(15)->withBasicAuth((string) config('bitcoind.default.user'), (string) config('bitcoind.default.password'))
                ->post($url, ['jsonrpc' => '2.0', 'id' => 'wallet-deposit-scan', 'method' => $method, 'params' => $params]);
            $body = $response->json();
            if (!$response->successful() || !empty($body['error']) || !is_array($body['result'] ?? null)) throw new RuntimeException();
            return $body['result'];
        } catch (\Throwable $e) { throw new RuntimeException('BTC_WALLET_RPC_UNAVAILABLE'); }
    }
}
