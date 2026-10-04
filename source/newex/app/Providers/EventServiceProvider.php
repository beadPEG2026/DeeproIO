<?php

namespace App\Providers;

use App\Models\Currency\Currency;
use App\Models\Order\FuturesContract;
use App\Models\Order\Order;
use App\Models\User\User;
use App\Models\Wallet\Wallet;
use App\Observers\Currency\CurrencyObserver;
use App\Observers\Order\FuturesContractObserver;
use App\Observers\Order\OrderObserver;
use App\Observers\User\UserObserver;
use App\Observers\Wallet\WalletObserver;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        /// Register Observers
        Order::observe(OrderObserver::class);
        FuturesContract::observe(FuturesContractObserver::class);
        Wallet::observe(WalletObserver::class);
        User::observe(UserObserver::class);
        Currency::observe(CurrencyObserver::class);
    }
}
