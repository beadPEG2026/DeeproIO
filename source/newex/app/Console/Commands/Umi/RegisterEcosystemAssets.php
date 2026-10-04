<?php
namespace App\Console\Commands\Umi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB,Cache};
final class RegisterEcosystemAssets extends Command {
 protected $signature='umi:register-ecosystem-assets {--commit}';
 protected $description='Add UMI and the stock catalog through the existing currency, BEP-20 and market configuration';
 public function handle():int {
  $assets=array_merge([config('umi.asset')],config('stock-tokens.assets'));
  if(!$this->option('commit')){$this->line(json_encode(array_column($assets,'symbol')));return 0;}
  $added=DB::transaction(function()use($assets){
   DB::statement('SELECT pg_advisory_xact_lock(8162027)');
   foreach(['currencies','markets','currency_networks','wallets'] as $table){
    DB::statement("LOCK TABLE {$table} IN SHARE ROW EXCLUSIVE MODE");
    $seq=DB::selectOne("SELECT COALESCE(pg_get_serial_sequence(?, 'id'), to_regclass(?)::text) AS name",[$table,$table.'_id_seq'])->name;
    if($seq){$next=DB::selectOne("SELECT nextval(?::regclass) AS n",[$seq])->n;DB::select("SELECT setval(?::regclass, ?, true)",[$seq,max((int)$next,(int)DB::table($table)->max('id'))]);}
   }
   $quote=DB::table('currencies')->where('symbol','USDT')->sole();$network=DB::table('networks')->where('slug','bep20')->sole();$added=[];
   foreach($assets as $a){
    $currency=DB::table('currencies')->where('symbol',$a['symbol'])->first();
    if($currency){if(strtolower((string)$currency->bep_contract)!==strtolower($a['contract']))throw new \RuntimeException('Existing contract conflict for '.$a['symbol']);}
    else{
     $id=DB::table('currencies')->insertGetId(['name'=>$a['name'],'symbol'=>$a['symbol'],'type'=>'coin','is_token'=>true,'decimals'=>$a['decimals'],'bep_contract'=>$a['contract'],'status'=>true,'deposit_status'=>true,'withdraw_status'=>true,'rate'=>'0','txn_explorer'=>'https://bscscan.com/tx/%txid%','created_at'=>now(),'updated_at'=>now()]);
     DB::table('currency_networks')->insert(['currency_id'=>$id,'network_id'=>$network->id]);$currency=DB::table('currencies')->find($id);$added[]=$a['symbol'];
    }
    foreach(DB::table('users')->pluck('id') as $userId){
     if(!DB::table('wallets')->where('user_id',$userId)->where('currency_id',$currency->id)->exists()){
      DB::table('wallets')->insert(['user_id'=>$userId,'currency_id'=>$currency->id,'created_at'=>now(),'updated_at'=>now()]);
      DB::afterCommit(fn()=>app(\App\Services\Performance\ReadModelCacheService::class)->invalidateWallets((int)$userId));
     }
    }
    $name=$a['symbol'].'-USDT';$market=DB::table('markets')->where('name',$name)->first();
    if($market){if((int)$market->base_currency_id!==(int)$currency->id||(int)$market->quote_currency_id!==(int)$quote->id)throw new \RuntimeException('Market identity conflict');continue;}
    DB::table('markets')->insert(['name'=>$name,'base_currency_id'=>$currency->id,'quote_currency_id'=>$quote->id,'base_precision'=>8,'quote_precision'=>8,'status'=>true,'trade_status'=>true,'buy_order_status'=>true,'sell_order_status'=>true,'cancel_order_status'=>true,'chart_source'=>$a['symbol']==='UMI'?'internal':'ondo-reference','chart_default_resolution'=>'5','switch_chart'=>false,'has_futures'=>false,'has_options'=>false,'liq'=>false,'custom_liquidity'=>false,'created_at'=>now(),'updated_at'=>now()]);
   }return $added;
  });
  $this->line(json_encode(['added'=>$added,'existing_configuration_overwritten'=>false,'balances_credited'=>false]));return 0;
 }
}
