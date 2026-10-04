<?php

namespace App\Console\Commands\Transaction;

use App\Models\Currency\Currency;
use App\Models\User\User;
use App\Models\Wallet\Wallet;
use Illuminate\Console\Command;

class UserBalanceCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user-balance:update {email} {currency} {amount}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Credit Referral Earnings to their wallets';

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
        $email = $this->argument('email');
        $currencyName = $this->argument('currency');
        $amount = $this->argument('amount');

        $user = User::where('email', $email)->first();
        $currency = Currency::where('symbol', $currencyName)->first();

        if(!$user) {
            $this->info("User with email $email not found");
            return;
        }

        if(!$currency) {
            $this->info("Currency with name $currencyName not found");
            return;
        }

        $wallet = Wallet::where('currency_id', $currency->id)->where('user_id', $user->id)->first();

        if(!$wallet) {
            $this->info("Wallet not found");
            return;
        }
        
        if($amount == 0) {
        	$newBalance = 0;
        } elseif($amount < 0) {
        	$newBalance = $wallet->balance_in_wallet - abs($amount);
        } else {
        	$newBalance = $wallet->balance_in_wallet + $amount;
        }

        $wallet->balance_in_wallet = $newBalance;
        $wallet->update();

        $this->info("Wallet balance updated. Now it has ".$wallet->balance_in_wallet." ".$currency->symbol);

    }
}
