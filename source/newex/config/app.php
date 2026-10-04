<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application. This value is used when the
    | framework needs to place the application's name in a notification or
    | any other location as required by the application or its packages.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Release Version
    |--------------------------------------------------------------------------
    |
    | This value is the release version of your application.
    |
    */

    'release_version' => env('APP_RELEASE_VERSION', '1.0.0'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    'whitelisted' => [],

    'chart_port' => env('TRADINGVIEW_CHART_PORT', 8081),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | your application so that it is used when running Artisan tasks.
    |
    */

    'url' => env('APP_URL', 'https://yourdomain'),

    'ethereum_bridge' => env('APP_ETHEREUM_URL', 'http://localhost:8000'),

    'bsc_bridge' => env('APP_BSC_URL', 'http://localhost:8001'),

    'tron_bridge' => env('APP_TRON_URL', 'http://localhost:8002'),

    'xlayer_bridge' => env('APP_XLAYER_URL', 'http://127.0.0.1:18008'),

    'polygon_bridge' => env('APP_POLYGON_URL', 'http://localhost:8003'),

    'customtoken_bridge' => env('APP_CUSTOMTOKEN_URL', 'http://localhost:3000'),

    'customtoken_token_bridge' => env('APP_CUSTOMTOKEN_TOKEN_URL', 'http://localhost:8004'),

    'solana_bridge' => env('APP_SOLANA_URL', 'http://localhost:8006'),

    'ton_bridge' => env('APP_TON_URL', 'http://localhost:8007'),

    'liquidity' => env('ENABLE_LIQUIDITY', false),

    'asset_url' => env('ASSET_URL', null),

    'plan' => env('APP_PLAN', 'lite'),

    'user_assets' => env('USER_ASSETS', false),

    'user_assets_hash' => env('USER_ASSETS_HASH', false),

    'fixed_swap' => env('FIXED_SWAP', false),

    'wallet_connect_enabled' => env('WALLET_CONNECT_ENABLED', true),

    'twitter_auth_enabled' => env('TWITTER_AUTH_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. We have gone
    | ahead and set this to a sensible default for you out of the box.
    |
    */

    'timezone' => 'Europe/Madrid',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by the translation service provider. You are free to set this value
    | to any of the locales which will be supported by the application.
    |
    */

    'locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Application Fallback Locale
    |--------------------------------------------------------------------------
    |
    | The fallback locale determines the locale to use when the current one
    | is not available. You may change the value to correspond to any of
    | the language folders that are provided through your application.
    |
    */

    'fallback_locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Faker Locale
    |--------------------------------------------------------------------------
    |
    | This locale will be used by the Faker PHP library when generating fake
    | data for your database seeds. For example, this will be used to get
    | localized telephone numbers, street address information and more.
    |
    */

    'faker_locale' => 'en_US',

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is used by the Illuminate encrypter service and should be set
    | to a random, 32 character string, otherwise these encrypted strings
    | will not be safe. Please do this before deploying an application!
    |
    */

    'key' => env('APP_KEY'),

    'cipher' => 'AES-256-CBC',

    'rates' => [
        'BTC-USDT' => [
            'digits' => 5,
            'often' => ['100', '999'],
            'medium' => ['1000', '9999'],
            'rare' => ['10000', '99999']
        ],

        'BNB-USDT' => [
            'digits' => 3,
            'often' => ['50', '999'],
            'medium' => ['1000', '9999'],
            'rare' => ['1000', '9999'],
        ],

        'ETH-USDT' => [
            'digits' => 4,
            'often' => ['100', '999'],
            'medium' => ['1000', '9999'],
            'rare' => ['10000', '20000'],
        ],

        'TRX-USDT' => [
            'digits' => 1,
            'often' => ['10000', '100000'],
            'medium' => ['1000000', '10000000'],
            'rare' => ['10000000', '100000000'],
        ],
    ],

    'trade frequency' => 2,

    /*
    |--------------------------------------------------------------------------
    | Autoloaded Service Providers
    |--------------------------------------------------------------------------
    |
    | The service providers listed here will be automatically loaded on the
    | request to your application. Feel free to add your own services to
    | this array to grant expanded functionality to your applications.
    |
    */

    'providers' => [

        /*
         * Laravel Framework Service Providers...
         */
        Illuminate\Auth\AuthServiceProvider::class,
        Illuminate\Broadcasting\BroadcastServiceProvider::class,
        Illuminate\Bus\BusServiceProvider::class,
        Illuminate\Cache\CacheServiceProvider::class,
        Illuminate\Foundation\Providers\ConsoleSupportServiceProvider::class,
        Illuminate\Cookie\CookieServiceProvider::class,
        Illuminate\Database\DatabaseServiceProvider::class,
        Illuminate\Encryption\EncryptionServiceProvider::class,
        Illuminate\Filesystem\FilesystemServiceProvider::class,
        Illuminate\Foundation\Providers\FoundationServiceProvider::class,
        Illuminate\Hashing\HashServiceProvider::class,
        Illuminate\Mail\MailServiceProvider::class,
        Illuminate\Notifications\NotificationServiceProvider::class,
        Illuminate\Pagination\PaginationServiceProvider::class,
        Illuminate\Pipeline\PipelineServiceProvider::class,
        Illuminate\Queue\QueueServiceProvider::class,
        Illuminate\Redis\RedisServiceProvider::class,
        Illuminate\Auth\Passwords\PasswordResetServiceProvider::class,
        Illuminate\Session\SessionServiceProvider::class,
        Illuminate\Translation\TranslationServiceProvider::class,
        Illuminate\Validation\ValidationServiceProvider::class,
        Illuminate\View\ViewServiceProvider::class,

        /*
         * Package Service Providers...
         */

        /*
         * Application Service Providers...
         */
        App\Providers\AppServiceProvider::class,
        App\Providers\AuthServiceProvider::class,
        App\Providers\BroadcastServiceProvider::class,
        App\Providers\EventServiceProvider::class,
        App\Providers\RouteServiceProvider::class,
        App\Providers\TelescopeServiceProvider::class,
        App\Providers\RepositoryServiceProvider::class,
        App\Providers\FortifyServiceProvider::class,
        App\Providers\JetstreamServiceProvider::class,
        App\Modules\Merchant\Providers\MerchantServiceProvider::class,
        Spatie\Permission\PermissionServiceProvider::class,
        Akaunting\Setting\Provider::class,
        Anhskohbo\NoCaptcha\NoCaptchaServiceProvider::class,
        App\Providers\HorizonServiceProvider::class,
        Denpa\Bitcoin\Providers\ServiceProvider::class,
        Barryvdh\Debugbar\ServiceProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Class Aliases
    |--------------------------------------------------------------------------
    |
    | This array of class aliases will be registered when this application
    | is started. However, feel free to register as many as you wish as
    | the aliases are "lazy" loaded so they don't hinder performance.
    |
    */

    'aliases' => [

        'App' => Illuminate\Support\Facades\App::class,
        'Arr' => Illuminate\Support\Arr::class,
        'Artisan' => Illuminate\Support\Facades\Artisan::class,
        'Auth' => Illuminate\Support\Facades\Auth::class,
        'Blade' => Illuminate\Support\Facades\Blade::class,
        'Broadcast' => Illuminate\Support\Facades\Broadcast::class,
        'Bus' => Illuminate\Support\Facades\Bus::class,
        'Cache' => Illuminate\Support\Facades\Cache::class,
        'Config' => Illuminate\Support\Facades\Config::class,
        'Cookie' => Illuminate\Support\Facades\Cookie::class,
        'Crypt' => Illuminate\Support\Facades\Crypt::class,
        'DB' => Illuminate\Support\Facades\DB::class,
        'Eloquent' => Illuminate\Database\Eloquent\Model::class,
        'Event' => Illuminate\Support\Facades\Event::class,
        'File' => Illuminate\Support\Facades\File::class,
        'Gate' => Illuminate\Support\Facades\Gate::class,
        'Hash' => Illuminate\Support\Facades\Hash::class,
        'Http' => Illuminate\Support\Facades\Http::class,
        'Lang' => Illuminate\Support\Facades\Lang::class,
        'Log' => Illuminate\Support\Facades\Log::class,
        'Mail' => Illuminate\Support\Facades\Mail::class,
        'Notification' => Illuminate\Support\Facades\Notification::class,
        'Password' => Illuminate\Support\Facades\Password::class,
        'Queue' => Illuminate\Support\Facades\Queue::class,
        'Redirect' => Illuminate\Support\Facades\Redirect::class,
        // 'Redis' => Illuminate\Support\Facades\Redis::class,
        'Request' => Illuminate\Support\Facades\Request::class,
        'Response' => Illuminate\Support\Facades\Response::class,
        'Route' => Illuminate\Support\Facades\Route::class,
        'Schema' => Illuminate\Support\Facades\Schema::class,
        'Session' => Illuminate\Support\Facades\Session::class,
        'Storage' => Illuminate\Support\Facades\Storage::class,
        'Str' => Illuminate\Support\Str::class,
        'URL' => Illuminate\Support\Facades\URL::class,
        'Validator' => Illuminate\Support\Facades\Validator::class,
        'View' => Illuminate\Support\Facades\View::class,
        'Setting' => Akaunting\Setting\Facade::class,
        'NoCaptcha' => Anhskohbo\NoCaptcha\Facades\NoCaptcha::class,
        'Bitcoind' => Denpa\Bitcoin\Facades\Bitcoind::class,
        'Agent' => Jenssegers\Agent\Facades\Agent::class,
        'Debugbar' => Barryvdh\Debugbar\Facades\Debugbar::class,
    ],

    'analytics' => env('GOOGLE_SITE_TAG', false),
    'readonly' => env('READ_ONLY_MODE', false),
    'sensitive' => env('SENSITIVE_MODE', false),
    'auto_withdrawals' => env('ENABLE_AUTO_WITHDRAWALS', false),
    'fees_in_usd' => env('ENABLE_FEES_IN_USD', false),
    'lending' => env('LENDING_ENABLED', false),
    'options_pnl' => [
        1 => 59,
        2 => 59,
        3 => 59,
        4 => 59,
        5 => 59
    ],
    'options_types' => [
        1 => 60,
        2 => 120,
        3 => 180,
        4 => 300,
        5 => 600
    ],
    'p2p' => [
        'default_base_pair' => env('P2P_DEFAULT_BASE_PAIR', 'USDT'),
        'default_quote_pair' => env('P2P_DEFAULT_QUOTE_PAIR', 'USD'),
        'default_cancellation_reasons_buyer' => [
            'I do not want to trade anymore',
            "I do not meet the requirements of the advertiser's trading terms and condition",
            "There is technical or network error with the payment platform",
            "I have not paid but clicked 'Transferred'",
            "Other reasons"
        ],
        'default_cancellation_reasons_seller' => [
            'Seller is asking for extra fee',
            "Problem with seller's payment method result in unsuccessful payments",
        ],
        'block_reasons' => [
            'Harassment',
            'Bad credibility',
            'Malicious feedback',
            'Scam suspicion',
            'Other'
        ]
    ],
    'currencyapi_apikey' => env('CURRENCYAPI_KEY', null),
    'fireblocks_enabled' => env('FIREBLOCKS_ENABLED', false),
    'deposit_bonus_enabled' => env('DEPOSIT_BONUS_ENABLED', false),
];
