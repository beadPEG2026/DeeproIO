<?php

namespace App\Jobs\Wallet;

use App\Models\Currency\Currency;
use App\Services\Wallet\WalletService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreateWalletsForCurrencyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $walletService;

    public $currency;

    /**
     * Create a new job instance.
     *
     * @param  App\Models\Currency\Currency $currency
     * @return void
     */
    public function __construct(Currency $currency)
    {
        $this->walletService = new WalletService();
        $this->currency = $currency;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $this->walletService->createWalletsForCurrency($this->currency);
    }
}
