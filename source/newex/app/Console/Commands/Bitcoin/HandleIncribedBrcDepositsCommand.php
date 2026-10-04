<?php

namespace App\Console\Commands\Bitcoin;

use App\Models\Deposit\Deposit;
use App\Models\Withdrawal\Withdrawal;
use App\Services\PaymentGateways\Coin\Bitcoin\Api\OrdinalApiGateway;
use Illuminate\Console\Command;

class HandleIncribedBrcDepositsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'btc:brc-deposits-inscribed';

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
        $deposits = Deposit::where(function($query) {
            $query->where(function($query) {
                $query->where('wallet_transfer_status', DEPOSIT_INSCRIBING_ORDERED);
            });
        })->whereIn('network_id', [NETWORK_BRC20])->with('currency')->get();

        foreach ($deposits as $deposit) {

            $data = json_decode($deposit->raw, true);

            $api = new OrdinalApiGateway();
            $inscriptionId = $api->getInscriptionByOrderId($data['order_id']);

            if($inscriptionId) {
                $data['inscriptionId'] = $inscriptionId;
                $deposit->wallet_transfer_status = DEPOSIT_INSCRIBED;
                $deposit->raw = json_encode($data);
                $deposit->update();
            }

        }
    }
}
