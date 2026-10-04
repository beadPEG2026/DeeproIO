<?php
namespace App\Console\Commands\Staking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Database\Schema\Blueprint;
use App\Services\Operations\History;
/** Separate audited additive schema action after verified code publication. Never drops data on rollback. */
final class InstallFundedStakingStorage extends Command
{
    protected $signature='staking:install-funded-storage {--apply}';
    protected $description='Add nullable staking metadata for funded reward reservations; preserve all legacy positions';
    public function handle(): int
    {
        if(Schema::hasColumn('staking_users','meta')){$this->info('Funded metadata already installed');return self::SUCCESS;}
        if(!$this->option('apply')){$this->info('Pending additive column: staking_users.meta (nullable JSON)');return self::SUCCESS;}
        if(config('app.readonly') || !Schema::hasTable('staking_reward_receipts')) return self::FAILURE;
        DB::transaction(function(){
            DB::statement("SET LOCAL lock_timeout = '5s'");
            if(!Schema::hasColumn('staking_users','meta'))Schema::table('staking_users',fn(Blueprint $t)=>$t->json('meta')->nullable());
            History::append('staking_storage',0,'add_metadata',['column'=>'staking_users.meta','type'=>'json','nullable'=>true,'rollback'=>'retain_column'],null,'Approved funded term product metadata; no existing balances changed');
        });
        $this->info('Funded metadata installed; legacy balances preserved');return self::SUCCESS;
    }
}
