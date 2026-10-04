<?php

namespace App\Console\Commands\Bitcoin;

use App\Models\Withdrawal\Withdrawal;
use App\Services\PaymentGateways\Coin\Bitcoin\Api\OrdinalApiGateway;
use Illuminate\Console\Command;

class HandleIncribedBrcWithdrawalsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'btc:brc-withdrawals-inscribed';

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
        $withdrawals = Withdrawal::where(function($query) {
            $query->where(function($query) {
                $query->where('extra_status', WITHDRAWAL_INSCRIBING_ORDERED);
            });
        })->whereIn('network_id', [NETWORK_BRC20])->with('currency')->get();

        foreach ($withdrawals as $withdrawal) {

            $data = json_decode($withdrawal->raw, true);

            $api = new OrdinalApiGateway();
            $inscriptionId = $api->getInscriptionByOrderId($data['order_id']);

            if($inscriptionId) {
                $data['inscriptionId'] = $inscriptionId;
                $withdrawal->extra_status = WITHDRAWAL_INSCRIBED;
                $withdrawal->raw = json_encode($data);
                $withdrawal->update();
            }

        }
    }
}
