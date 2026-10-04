<?php

namespace App\Observers\User;

use App\Jobs\Wallet\CreateWalletsForUserJob;
use App\Models\User\User;
use App\Services\Wallet\WalletService;
use App\Services\User\FreshUserIdentity;

class UserObserver
{
    public $walletService;

    public function __construct() {
        $this->walletService = new WalletService();
    }

    public function creating(User $user): void
    {
        $user->id = app(FreshUserIdentity::class)->allocate($user->id);
    }

    /**
     * Listen to the User created event.
     *
     * @param  \App\Models\User\User $user
     * @return void
     */
    public function created(User $user)
    {
        $job = new CreateWalletsForUserJob($user);

        dispatch_sync($job);
    }
}
