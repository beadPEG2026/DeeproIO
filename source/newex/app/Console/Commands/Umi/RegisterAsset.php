<?php
namespace App\Console\Commands\Umi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
class RegisterAsset extends Command {
    protected $signature='umi:register-asset {--commit}';
    protected $description='登记 UMI/BSC 与待开放现货交易对；不启用交易或充提';
    public function handle():int {
        $asset=config('umi.asset');
        if(!$this->option('commit')){$this->line(json_encode(['asset'=>$asset,'market'=>'UMI-USDT','status'=>'pending'],JSON_UNESCAPED_UNICODE));return self::SUCCESS;}
        DB::transaction(function()use($asset){
            DB::statement('SELECT pg_advisory_xact_lock(8162027)');
            $c=DB::table('currencies')->where('symbol','UMI')->first();
            if($c && strtolower((string)$c->bep_contract)!==$asset['contract'])throw new \RuntimeException('已有同名币种合约不同，未覆盖。');
            $id=$c?->id??DB::table('currencies')->insertGetId(['name'=>$asset['name'],'symbol'=>'UMI','type'=>'coin','decimals'=>18,
                'is_token'=>true,'bep_contract'=>$asset['contract'],'status'=>false,'deposit_status'=>false,'withdraw_status'=>false,
                'rate'=>'0','txn_explorer'=>'https://bscscan.com/tx/','created_at'=>now(),'updated_at'=>now()]);
            $quote=DB::table('currencies')->where('symbol','USDT')->value('id');
            if(!$quote)throw new \RuntimeException('USDT 基础币种未配置。');
            $m=DB::table('markets')->where('name','UMI-USDT')->first();
            if($m && ((int)$m->base_currency_id!==(int)$id || (int)$m->quote_currency_id!==(int)$quote))throw new \RuntimeException('已有同名交易对资产不同，未覆盖。');
            if(!$m)DB::table('markets')->insert(['name'=>'UMI-USDT','base_currency_id'=>$id,'quote_currency_id'=>$quote,
                'base_precision'=>8,'quote_precision'=>8,'status'=>false,'trade_status'=>false,'buy_order_status'=>false,'sell_order_status'=>false,
                'has_futures'=>false,'has_options'=>false,'liq'=>false,'custom_liquidity'=>false,
                'chart_source'=>'pending','created_at'=>now(),'updated_at'=>now()]);
        });
        $this->info('UMI/BSC 与 UMI-USDT 已登记；交易、充提和行情仍待接续验收。');return self::SUCCESS;
    }
}
