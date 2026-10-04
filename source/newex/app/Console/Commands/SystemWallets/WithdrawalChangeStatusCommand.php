<?php

namespace App\Console\Commands\SystemWallets;

use App\Models\Withdrawal\Withdrawal;
use App\Repositories\Withdrawal\WithdrawalRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class WithdrawalChangeStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'withdrawal:revert-approval {id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $withdrawal = Withdrawal::where('withdrawal_id', $this->argument('id'))->where('status', WITHDRAWAL_WAITING_PROVIDER_APPROVAL)->first();

        if(!$withdrawal) {
            $this->info('Withdrawal not found');
            return;
        }

        $withdrawal->status = WITHDRAWAL_WAITING_APPROVAL;
        $withdrawal->save();

        $this->info('Withdrawal Updated');
    }
}
