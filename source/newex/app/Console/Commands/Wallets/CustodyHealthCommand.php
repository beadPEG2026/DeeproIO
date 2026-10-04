<?php
namespace App\Console\Commands\Wallets;
use Illuminate\Console\Command;
use App\Services\Custody\{CustodyBridge,CustodyNetwork};
use Illuminate\Support\Facades\{Http,DB};
class CustodyHealthCommand extends Command {
 protected $signature='wallets:custody-health';
 protected $description='Read-only chain, signing-address and custody configuration checks';
 public function handle(CustodyBridge $bridge):int {
  $rows=[];
  foreach(['ethereum','bnb','polygon','xlayer','tron','solana','ton'] as $chain) {
   $row=['chain'=>$chain,'rpc'=>false,'signer_valid'=>false,'broadcast_enabled'=>false];
   try {$j=Http::timeout(5)->get(CustodyNetwork::bridge($chain).'/health')->json();$row['rpc']=($j['rpc']??false)&&($j['database']??false);$row['broadcast_enabled']=$j['broadcast_enabled']??false;
     $bridge->call($chain,'validate',['sender'=>trim((string)setting($chain.'.wallet')),'private_key'=>setting($chain.'.private_key')]);$row['signer_valid']=true;
   }catch(\Throwable $e){$row['error']=preg_match('/^CUSTODY_[A-Z_]+$/D',$e->getMessage())?$e->getMessage():'CUSTODY_HEALTH_UNAVAILABLE';}
   $n=DB::table('custody_networks')->where('chain',$chain)->first();$row['enabled']=(bool)$n->enabled;$row['auto_sweep']=(bool)$n->auto_sweep;$rows[]=$row;
  }
  $this->line(json_encode($rows,JSON_UNESCAPED_SLASHES));return 0;
 }
}
