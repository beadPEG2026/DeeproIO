<?php

namespace App\Jobs\Wallet;

use App\Models\User\User;
use App\Services\Wallet\WalletService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreateWalletsForUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $walletService;

    public $user;

    /**
     * Create a new job instance.
     *
     * @param  App\Models\User\User $user
     * @return void
     */
    public function __construct(User $user)
    {
        $this->walletService = new WalletService();
        $this->user = $user;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $this->walletService->createWalletsForUser($this->user);
    }
}
