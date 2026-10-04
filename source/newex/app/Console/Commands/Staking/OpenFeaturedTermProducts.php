<?php
namespace App\Console\Commands\Staking;

use App\Models\Staking\Staking;
use App\Services\Operations\{History,StakingConfiguration};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB,Schema};

/** An explicit, audited switch for future BTC/ETH positions; never edits existing stakes. */
final class OpenFeaturedTermProducts extends Command
{
    protected $signature='staking:open-featured {--apply}';
    protected $description='Open existing BTC and ETH offers with platform-paid rewards and no reserve prerequisite';
    public function handle(): int
    {
        if (!$this->option('apply')) {$this->info('BTC/ETH: active, platform-paid term rewards, no operating UID or reserve prerequisite. No change applied.');return self::SUCCESS;}
        if (config('app.readonly') || !Schema::hasColumn('staking_users','meta')) return self::FAILURE;
        DB::transaction(function(){
            foreach (['BTC','ETH'] as $symbol) {
                $id=(int)DB::table('settings')->where('key','staking.featured_term.'.$symbol)->value('value');
                $p=Staking::whereKey($id)->lockForUpdate()->firstOrFail();
                $state=app(StakingConfiguration::class)->controls($id);
                if ((int)$p->staking_type!==0 || empty($state['funded_term']) || DB::table('currencies')->where('id',$p->currency_id)->value('symbol')!==$symbol) throw new \RuntimeException('Unexpected featured product identity');
                if (($state['reward_funding']??'reserved')==='platform' && $p->status==='active') continue;
                $before=['status'=>$p->status,'controls'=>$state];
                $state['reward_funding']='platform';
                DB::table('settings')->where('key','staking.configuration.'.$id)->update(['value'=>json_encode($state,JSON_THROW_ON_ERROR)]);
                $p->status='active';$p->save();
                History::append('staking_config',$id,'open_platform_rewards',['before'=>$before,'after'=>['status'=>'active','controls'=>$state]],null,'User approved subscriptions without reward reserve; Deepro owes contractual term rewards. Existing position snapshots unchanged.');
            }
        },3);
        $this->info('BTC and ETH subscriptions opened. Rewards are platform obligations; no principal or reward balance moved.');
        return self::SUCCESS;
    }
}
