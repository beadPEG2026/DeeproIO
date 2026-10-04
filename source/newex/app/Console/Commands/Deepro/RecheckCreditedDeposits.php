<?php
namespace App\Console\Commands\Deepro;

use App\Services\Deposit\{DepositRisk,EvmDepositClient};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB,Cache};

final class RecheckCreditedDeposits extends Command
{
    protected $signature='deepro:recheck-credited-deposits {--limit=25}';
    protected $description='Recheck recent canonical block hashes and retain uncertain credits for review';
    public function handle(EvmDepositClient $client,DepositRisk $risk):int {
        $lock=Cache::lock('deposits:canonical-recheck',120);if(!$lock->get())return 0;
        try{
            $client->beginRound(100,microtime(true)+35);
            $rows=DB::table('chain_deposit_receipts')->whereIn('chain',array_keys(config('deposits.evm',[])))
                ->where('credited_amount','>',0)->where('created_at','>=',now()->subDays(7))
                ->orderByRaw('rechecked_at ASC NULLS FIRST')->orderBy('id')->limit(max(1,min(100,(int)$this->option('limit'))))->get();
            foreach($rows as $row){
                try{
                    $proof=$row->recheck_anchor?json_decode($row->recheck_anchor,true):(json_decode($row->evidence,true)['chain']??[]);
                    if(!isset($proof['block'],$proof['block_hash']))continue;
                    $canonical=$client->rpc($row->chain,'eth_getBlockByNumber',['0x'.dechex((int)$proof['block']),false]);
                    if(!is_array($canonical)||empty($canonical['hash']))continue; // Provider outage is not proof of a lost credit.
                    if(strtolower($canonical['hash'])!==strtolower($proof['block_hash'])){
                        DB::transaction(function () use ($row,$proof,$canonical,$risk) {
                            $latest=DB::table('chain_deposit_receipts')->where('id',$row->id)->lockForUpdate()->first();
                            $anchor=$latest->recheck_anchor?json_decode($latest->recheck_anchor,true):(json_decode($latest->evidence,true)['chain']??[]);
                            if (($anchor['block']??null)!==$proof['block'] || ($anchor['block_hash']??null)!==$proof['block_hash']) return;
                            $deposit=DB::table('deposits')->find($row->deposit_id);
                            if($deposit)$risk->record($deposit,$row->chain,['original_block'=>$proof['block'],'original_hash'=>$proof['block_hash'],'observed_hash'=>$canonical['hash'],'checked_at'=>now()->toIso8601String()]);
                        },3);
                    }
                }catch(\Throwable $e){$this->warn('Canonical verification pending for receipt '.$row->id);}
                finally{DB::table('chain_deposit_receipts')->where('id',$row->id)->update(['rechecked_at'=>now()]);}
            }
            return 0;
        }finally{$client->endRound();$lock->release();}
    }
}
