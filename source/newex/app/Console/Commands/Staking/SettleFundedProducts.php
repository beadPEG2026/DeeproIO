<?php
namespace App\Console\Commands\Staking;
use Illuminate\Console\Command;
use App\Models\Staking\StakingUser;
use App\Services\Staking\FundedTermProduct;
final class SettleFundedProducts extends Command
{
    protected $signature='staking:funded-settle';
    protected $description='Accrue and settle term positions with persisted failure tracking';
    public function handle(): int
    {
        if(config('app.readonly'))return self::FAILURE;
        if(!\Illuminate\Support\Facades\Schema::hasColumn('staking_users','meta'))return self::SUCCESS;
        $errors=0;StakingUser::active()->where('meta->funded_term',1)->orderBy('id')->chunkById(100,function($rows)use(&$errors){foreach($rows as $r)try{app(\App\Services\Staking\TermOperations::class)->settle($r->id);}catch(\Throwable $e){$errors++;report($e);}});
        $this->info('Funded staking settlement errors: '.$errors);return $errors?self::FAILURE:self::SUCCESS;
    }
}
