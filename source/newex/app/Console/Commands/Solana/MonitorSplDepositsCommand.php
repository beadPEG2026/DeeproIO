<?php

namespace App\Console\Commands\Solana;

use App\Models\Network\Network;
use App\Models\Wallet\WalletAddress;
use App\Repositories\Deposit\DepositRepository;
use App\Services\PaymentGateways\Coin\Solana\Services\SolanaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Setting;

class MonitorSplDepositsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'solana:monitor-spl-deposits';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    public $depositRepository;

    public $solanaService;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->depositRepository = new DepositRepository();
        $this->solanaService = new SolanaService();

    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if(Network::where('id', NETWORK_SOL_SPL)->where('deposit_status', false)->count()) {
            return false;
        }

        $wallets = WalletAddress::with(['wallet.currency', 'user'])->whereIn('network_id', [NETWORK_SOL_SPL])->where('token_account', '!=', null)->has('user')->orderByDesc('created_at')->get();

        foreach ($wallets as $wallet) {

            if(config('app.readonly') && !$wallet->user->hasRole('admin')) {
                continue;
            }

            try {

                $this->check($wallet);

                usleep(500000);

            } catch (\Exception $e) {
                Log::error($e);
            }
        }
    }

    public function check($wallet) {

        $txns = $this->solanaService->getSplTransfers($wallet->address, $wallet->wallet->currency->sol_contract, $wallet->token_account, 20);

        foreach ($txns as $transaction) {

            DB::transaction(function () use ($wallet, $transaction) {

                $this->solanaService->storeSplDeposit($wallet, $wallet->wallet->currency, $transaction);

                return true;

            }, DB_REPEAT_AFTER_DEADLOCK);
        }
    }
}
