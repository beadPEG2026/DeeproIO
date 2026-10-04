<?php

namespace App\Providers;

use App\Interfaces\Currency\CurrencyRepositoryInterface;
use App\Interfaces\Deposit\DepositRepositoryInterface;
use App\Interfaces\Market\MarketRepositoryInterface;
use App\Interfaces\Network\NetworkRepositoryInterface;
use App\Interfaces\Order\OrderHistoryRepositoryInterface;
use App\Interfaces\Order\OrderRepositoryInterface;
use App\Interfaces\User\UserRepositoryInterface;
use App\Interfaces\Wallet\WalletRepositoryInterface;
use App\Interfaces\Withdrawal\WithdrawalRepositoryInterface;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Deposit\DepositRepository;
use App\Repositories\Market\MarketRepository;
use App\Repositories\Network\NetworkRepository;
use App\Repositories\Order\OrderHistoryRepository;
use App\Repositories\Order\OrderRepository;
use App\Repositories\User\UserRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Repositories\Withdrawal\WithdrawalRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        // Bind order repository
        $this->app->bind(
            OrderRepositoryInterface::class,
            OrderRepository::class
        );

        // Bind order history repository
        $this->app->bind(
            OrderHistoryRepositoryInterface::class,
            OrderHistoryRepository::class
        );

        // Bind market repository
        $this->app->bind(
            MarketRepositoryInterface::class,
            MarketRepository::class
        );

        // Bind currency repository
        $this->app->bind(
            CurrencyRepositoryInterface::class,
            CurrencyRepository::class
        );

        // Bind wallet repository
        $this->app->bind(
            WalletRepositoryInterface::class,
            WalletRepository::class
        );

        // Bind network repository
        $this->app->bind(
            NetworkRepositoryInterface::class,
            NetworkRepository::class
        );

        // Bind deposit repository
        $this->app->bind(
            DepositRepositoryInterface::class,
            DepositRepository::class
        );

        // Bind withdrawal repository
        $this->app->bind(
            WithdrawalRepositoryInterface::class,
            WithdrawalRepository::class
        );

        // Bind user repository
        $this->app->bind(
            UserRepositoryInterface::class,
            UserRepository::class
        );
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {

    }
}
