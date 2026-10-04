<?php

namespace App\Console\Commands;

use App\Models\Market\Market;
use App\Models\Order\Order;
use App\Models\Transaction\Transaction;
use App\Models\User\User;
use App\Models\Wallet\WalletAddress;
use App\Services\PaymentGateways\Coin\Coinpayments\Api\CoinpaymentsGateway;
use Carbon\Carbon;
use Database\Seeders\Roles\RolesSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ResetAdminCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reset-admin';

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
        if(!config('app.readonly')) {
            return;
        }

        $users = User::where('id', '!=', 1)->where('demo_enabled_at', '<', Carbon::now()->subMinutes(60))->get();
        $roles = array_column(RolesSeeder::ROLES, 'name');

        foreach ($users as $user) {
            foreach ($roles as $role) {
                if($role == "user")
                    continue;

                $user->removeRole($role);

                $user->demo_enabled_at = null;
                $user->update();
            }
        }

    }
}
