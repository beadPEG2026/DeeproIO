<?php
namespace App\Console\Commands\Deepro;

use App\Models\Currency\Currency;
use App\Models\Deposit\DepositChannel;
use App\Services\Deposit\DepositChannelConfiguration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB,Validator};

final class SetUsdtDepositMinimum extends Command
{
    protected $signature = 'deepro:usdt-deposit-minimum {minimum} {--apply : Persist the authorized minimum on the asset and every configured channel}';
    protected $description = 'Set USDT deposit minimum without opening other networks or replaying receipts';

    public function handle(): int
    {
        $minimum=(string)$this->argument('minimum');
        Validator::make(['minimum'=>$minimum],['minimum'=>['required','regex:/^\d{1,18}(\.\d{1,18})?$/']])->validate();
        if(bccomp($minimum,'0',18)<=0)throw new \RuntimeException('Deposit minimum must be positive');
        if(!$this->option('apply')){$this->info('Dry run: USDT asset and configured deposit channels would use '.$minimum);return self::SUCCESS;}
        abort_if(config('app.readonly'),403);
        $count=DB::transaction(function()use($minimum){
            DB::select('SELECT pg_advisory_xact_lock(77321,99)');
            $currency=Currency::where('symbol','USDT')->lockForUpdate()->firstOrFail();
            $before=$currency->min_deposit;$count=0;
            foreach(DepositChannel::where('currency_id',$currency->id)->orderBy('id')->lockForUpdate()->get() as $channel){
                if(bccomp((string)$channel->minimum,$minimum,18)===0)continue;
                if($channel->isPilot())throw new \RuntimeException('Finish the current pilot before changing public limits');
                $data=$channel->only(['currency_id','network_id','contract','decimals','confirmations','minimum','fee_fixed','fee_percent','start_block','state','acceptance_reference']);
                $data['minimum']=$minimum;
                app(DepositChannelConfiguration::class)->save($data,null,'authorized-usdt-minimum');
                $count++;
            }
            $currency->update(['min_deposit'=>$minimum]);
            if($count || bccomp((string)$before,$minimum,18)!==0)DB::table('custody_audits')->insert(['action'=>'usdt.deposit_minimum_changed','detail'=>json_encode(['before'=>$before,'after'=>$minimum,'channels'=>$count,'existing_receipts_replayed'=>false]),'created_at'=>now()]);
            return $count;
        });
        $this->info('USDT minimum saved; updated channels: '.$count.'. Existing receipts and scan progress retained.');
        return self::SUCCESS;
    }
}
