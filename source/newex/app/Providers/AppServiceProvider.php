<?php

namespace App\Providers;

use App\Models\User\User;
use App\Support\WalletBalanceLogContext;
use App\Repositories\Market\MarketRepository;
use App\Repositories\Order\OrderHistoryRepository;
use App\Repositories\Order\OrderRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Chart\ExternalCandleService;
use App\Services\Admin\AdminGroupFilterService;
use App\Services\Market\MarketService;
use App\Services\Order\OrderService;
use App\Services\Transaction\TransactionService;
use App\Services\Wallet\WalletService;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use App\Validation\BailingValidator;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Support\Str;
use Illuminate\Console\Events\CommandStarting;
use Laravel\Jetstream\Jetstream;
use Illuminate\Routing\Route;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        Jetstream::ignoreRoutes();
        if ($this->app->environment('local') && config('mail.local_gmail_relay')) {
            $this->app->afterResolving('mail.manager', function ($manager) {
                $manager->extend('smtp', function (array $config) {
                    if (($config['host'] ?? '') !== 'smtp.gmail.com') throw new \LogicException('Local relay is only configured for Gmail');
                    $transport = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport('127.0.0.1',15465,true);
                    $transport->setUsername($config['username'] ?? '');
                    $transport->setPassword($config['password'] ?? '');
                    $transport->getStream()->setTimeout(20)->setStreamOptions(['ssl'=>['peer_name'=>'smtp.gmail.com','SNI_enabled'=>true,'verify_peer'=>true,'verify_peer_name'=>true]]);
                    return $transport;
                });
            });
        }


        if ($this->app->environment('local')) {
            //$this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
        }

        // Register repositories as singletons for better performance
        $this->app->singleton(WalletRepository::class);
        $this->app->singleton(MarketRepository::class);
        $this->app->singleton(OrderHistoryRepository::class);
        
        // Register services with dependency injection
        $this->app->singleton(WalletService::class, function ($app) {
            return new WalletService($app->make(WalletRepository::class));
        });
        
        $this->app->singleton(MarketService::class, function ($app) {
            return new MarketService($app->make(MarketRepository::class));
        });
        
        // Register ExternalCandleService as singleton for chart data caching
        $this->app->singleton(ExternalCandleService::class);
        $this->app->singleton(AdminGroupFilterService::class);
        
        $this->app->singleton(TransactionService::class, function ($app) {
            return new TransactionService($app->make(WalletService::class));
        });
        
        $this->app->bind(OrderRepository::class, function ($app) {
            return new OrderRepository(
                $app->make(WalletService::class),
                $app->make(MarketRepository::class),
                $app->make(WalletRepository::class),
                $app->make(OrderHistoryRepository::class)
            );
        });
        
        $this->app->bind(OrderService::class, function ($app) {
            return new OrderService(
                $app->make(OrderRepository::class),
                $app->make(WalletService::class)
            );
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event) {
            WalletBalanceLogContext::forConsole($event);
        });

        // Configure Mailgun mailer if enabled in settings
        $this->configureMailgun();

        $isMobileInstance =  is_mobile_instance();

        View::share('mobileApp', $isMobileInstance);

        $cookie = Cookie::get('theme');
        $mode = '';

        if($isMobileInstance) {
            View::share('themeMode', 'dark');
        } else {

            if($cookie) {
                try {
                    View::share('themeMode', CookieValuePrefix::remove(Crypt::decryptString((Cookie::get('theme')))));
                } catch (\Exception $e) {
                    View::share('themeMode', 'dark');
                }
            } else {

                $shouldLoadThemeSettings = !$this->app->runningInConsole() && !request()->is('api/*');

                if ($shouldLoadThemeSettings) {
                    try {
                        try {
                            $settingsLoaded = $this->settingsTableExistsCached();
                        } catch (\Throwable $e) {
                            $settingsLoaded = Schema::hasTable('settings');
                        }

                        if ($settingsLoaded) {
                            $darkEnabled = setting('general.dark_mode_status', false);

                            if ($darkEnabled && setting('general.default_dark_mode_status', false)) {
                                $mode = 'dark';
                            }
                        }
                    } catch (\Throwable $e) {
                        // Theme defaults remain usable while settings storage is unavailable.
                    }
                }

                View::share('themeMode', $mode);

            }

            Scramble::afterOpenApiGenerated(function (OpenApi $openApi) {
                $openApi->components->schemas = [];
                $openApi->secure(
                    SecurityScheme::http('bearer', 'JWT')
                );
            });

            Scramble::routes(function (Route $route) {

                if(env('HIDE_CMC_GECKO')) {
                    return Str::startsWith($route->uri, 'api/')
                        && !in_array('PUT', $route->methods)
                        && !in_array('DELETE', $route->methods)
                        && !Str::startsWith($route->uri, 'api/v1/spot')
                        && !Str::startsWith($route->uri, 'api/v1/gateways')
                        && !Str::startsWith($route->uri, 'api/v1/server')
                        && !Str::startsWith($route->uri, 'api/v1/currencies/rates-balance')
                        && !Str::startsWith($route->uri, 'api/v1/perfect-money')
                        && !Str::startsWith($route->uri, 'api/v1/check-auth')
                        && !Str::startsWith($route->uri, 'api/v1/qr-code-token')
                        && !Str::startsWith($route->uri, 'api/v1/wallets/payment/stripe')
                        && !Str::startsWith($route->uri, 'api/broadcasting');
                }

                return Str::startsWith($route->uri, 'api/')
                    && !in_array('PUT', $route->methods)
                    && !in_array('DELETE', $route->methods)
                    && !Str::startsWith($route->uri, 'api/v1/gateways')
                    && !Str::startsWith($route->uri, 'api/v1/server')
                    && !Str::startsWith($route->uri, 'api/v1/currencies/rates-balance')
                    && !Str::startsWith($route->uri, 'api/v1/perfect-money')
                    && !Str::startsWith($route->uri, 'api/v1/check-auth')
                    && !Str::startsWith($route->uri, 'api/v1/qr-code-token')
                    && !Str::startsWith($route->uri, 'api/v1/wallets/payment/stripe')
                    && !Str::startsWith($route->uri, 'api/broadcasting');
            });

            Gate::define('viewApiDocs', function (User $user) {
                return true;
            });
        }

        /**
         * @var \Illuminate\Validation\Factory $factory
         */
        $factory = resolve(Factory::class);

        $factory->resolver(function (Translator $translator, array $data, array $rules, array $messages, array $customAttributes) {
            return new BailingValidator($translator, $data, $rules, $messages, $customAttributes);
        });
    }

    /**
     * Configure Mailgun mailer if enabled in settings.
     *
     * @return void
     */
    protected function configureMailgun(): void
    {
        try {
            $mailgunEnabled = Cache::store(
                (string) config('performance.cache_store', 'redis')
            )->remember('settings.mail.mailgun_enabled', now()->addMinutes(10), function () {
                if (!$this->settingsTableExistsCached()) {
                    return false;
                }

                return (bool) setting('mail.mailgun_enabled', false);
            });

            if ($mailgunEnabled) {
                // Set default mailer to mailgun
                Config::set('mail.default', 'mailgun');

                // Configure mailgun settings from environment
                Config::set('services.mailgun.domain', env('MAILGUN_DOMAIN'));
                Config::set('services.mailgun.secret', env('MAILGUN_SECRET'));
                Config::set('services.mailgun.endpoint', env('MAILGUN_ENDPOINT', 'api.mailgun.net'));
            }
        } catch (\Throwable $e) {
            // Silently fail if settings table doesn't exist yet (during migrations)
        }
    }

    protected function settingsTableExistsCached(): bool
    {
        return (bool) Cache::store(
            (string) config('performance.cache_store', 'redis')
        )->remember(
            'schema:settings-table:v2:' . config('database.default'),
            max(300, (int) config('performance.schema_ttl_seconds', 86400)),
            function () {
                return Schema::hasTable('settings');
            }
        );
    }
}
