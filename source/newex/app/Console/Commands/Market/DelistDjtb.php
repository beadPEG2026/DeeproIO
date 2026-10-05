<?php
namespace App\Console\Commands\Market;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\Market\MarketService;

/** Explicit, reversible listing change. Never deletes a wallet or financial record. */
final class DelistDjtb extends Command
{
    protected $signature='market:delist-djtb {--apply} {--release=}';
    protected $description='Retire the DJTB market, preserving all customer assets and history';
    public function handle(): int
    {
        $tag=(string)$this->option('release');
        if ($this->option('apply') && !preg_match('/^\d{8}-(?:ui-refinement|full-implementation)-r\d+$/D',$tag)) { $this->error('Immutable release tag required');return self::FAILURE; }
        try {
            $result=DB::transaction(function () use ($tag) {
                $c=DB::table('currencies')->where('symbol','DJTB')->lockForUpdate()->first();
                $m=DB::table('markets')->where('name','DJTB-USDT')->lockForUpdate()->first();
                if (!$c || !$m || (int)$m->base_currency_id!==(int)$c->id || strtolower((string)$c->bep_contract)!=='0xf2ec508422174ee564de98187db9359d318afb6b') throw new \RuntimeException('DJTB_IDENTITY_MISMATCH');
                $orders=DB::table('orders')->where('market_id',$m->id)->count();
                $held=DB::table('wallets')->where('currency_id',$c->id)->whereRaw('(balance_in_wallet + balance_in_trade + balance_in_order + balance_in_withdraw) > 0')->count();
                if (!$this->option('apply'))return ['symbol'=>'DJTB','open_orders'=>$orders,'wallets_with_assets'=>$held,'applied'=>false];
                if ($orders) throw new \RuntimeException('DJTB_OPEN_ORDERS_REQUIRE_CANCELLATION');
                $dir=storage_path('app/releases/'.$tag);if(!is_dir($dir))mkdir($dir,0700,true);
                $path=$dir.'/djtb-before-delist.json';
                if (!file_exists($path)) {
                    $handle=fopen($path,'x');if(!$handle)throw new \RuntimeException('DJTB_BACKUP_FAILED');chmod($path,0600);
                    $body=json_encode(['release'=>$tag,'currency'=>(array)$c,'market'=>(array)$m,'at'=>gmdate('c')],JSON_THROW_ON_ERROR);
                    if(fwrite($handle,$body)!==strlen($body)){fclose($handle);throw new \RuntimeException('DJTB_BACKUP_FAILED');}fclose($handle);
                }
                DB::table('currencies')->where('id',$c->id)->update(['asset_display_enabled'=>false,'deposit_status'=>false,'updated_at'=>now()]);
                DB::table('markets')->where('id',$m->id)->update(['status'=>false,'trade_status'=>false,'liq'=>false,'updated_at'=>now()]);
                return ['symbol'=>'DJTB','open_orders'=>0,'wallets_with_assets'=>$held,'applied'=>true,'withdrawal_setting_preserved'=>true,'release'=>$tag];
            },3);
            if($result['applied'])app(MarketService::class)->updateMarketsInfoCache();
            $this->line(json_encode($result,JSON_THROW_ON_ERROR));return self::SUCCESS;
        } catch (\Throwable $e) { $this->error(preg_match('/^DJTB_[A-Z_]+$/D',$e->getMessage())?$e->getMessage():'DJTB_DELIST_FAILED');return self::FAILURE; }
    }
}
