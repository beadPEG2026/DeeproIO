<?php
namespace App\Console\Commands\Staking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\{Currency\Currency,Staking\Staking};
use App\Services\Operations\History;

final class ConfigureFeaturedTermProducts extends Command
{
    protected $signature='staking:configure-featured {--apply}';
    protected $description='Create the approved BTC and ETH term offers without funding or opening subscriptions';
    public function handle(): int
    {
        foreach(['BTC'=>['0.3','0.0001','1','10'],'ETH'=>['1.5','0.001','10','100']] as $symbol=>$terms) {
            [$apr,$min,$max,$cap]=$terms;
            $currency=Currency::where('symbol',$symbol)->where('type','coin')->first();
            if (!$currency) {$this->error('Missing currency '.$symbol);return self::FAILURE;}
            $key='staking.featured_term.'.$symbol;
            if (!$this->option('apply')) {$this->line($symbol.' 30 days; APR '.$apr.'%; min '.$min.'; per-user max '.$max.'; cap '.$cap.'; funding required');continue;}
            if(config('app.readonly')) return self::FAILURE;
            DB::transaction(function()use($symbol,$currency,$terms,$key,$apr,$min,$max,$cap){
                // A product identity is immutable; re-running does not reset an operator's later configuration.
                DB::table('settings')->insertOrIgnore(['key'=>$key,'value'=>'pending']);
                $row=DB::table('settings')->where('key',$key)->lockForUpdate()->first();
                if($row->value!=='pending') {if(!Staking::find((int)$row->value))throw new \RuntimeException('Featured product identity missing');return;}
                $p=Staking::create(['currency_id'=>$currency->id,'staking_type'=>0,'status'=>'active','allowed_days'=>'30',
                    'rewards_percentage'=>bcdiv(bcmul($apr,'30',20),'365',18),'min_amount'=>$min,'max_amount'=>$max]);
                $controls=['funded_term'=>true,'apr_limit'=>5,'reward_user_id'=>null,'pool_limit'=>$cap];
                DB::table('settings')->updateOrInsert(['key'=>'staking.configuration.'.$p->id],['value'=>json_encode($controls,JSON_THROW_ON_ERROR)]);
                DB::table('settings')->where('key',$key)->update(['value'=>(string)$p->id]);
                History::append('staking_config',$p->id,'create',['currency'=>$symbol,'days'=>30,'apr'=>$apr,'min'=>$min,'max'=>$max,'controls'=>$controls],null,'Approved BTC/ETH showcase; subscription requires funded reserve');
            });
            $this->info($symbol.' product configured; reserve account remains operator-controlled');
        }
        return self::SUCCESS;
    }
}
