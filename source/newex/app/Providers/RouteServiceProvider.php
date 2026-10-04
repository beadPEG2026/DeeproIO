<?php

namespace App\Providers;

use App\Models\Market\Market;
use App\Repositories\Market\MarketRepository;
use App\Services\Market\MarketService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * This is used by Laravel authentication to redirect users after login.
     *
     * @var string
     */
    public const HOME = '/';

    /**
     * The controller namespace for the application.
     *
     * When present, controller route declarations will automatically be prefixed with this namespace.
     *
     * @var string|null
     */
    // protected $namespace = 'App\\Http\\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::prefix('api')
                ->middleware('api')
                ->namespace($this->namespace)
                ->group(base_path('routes/api.php'));

            if(config('app.user_assets')) {
                Route::middleware('web')
                    ->namespace($this->namespace)
                    ->group(base_path('routes/web.php'));
            } else {
                Route::middleware('web')
                    ->namespace($this->namespace)
                    ->group(base_path('routes/admin.php'));
            }
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            if($request->user() && in_array($request->user()->id, config('app.whitelisted'))) {
                return Limit::perMinute(1000000)->by(optional($request->user())->id ?: $request->ip());
            }
            return Limit::perMinute(200)->by(optional($request->user())->id ?: $request->ip());
        });

        RateLimiter::for('chart', function (Request $request) {
            return Limit::perMinute(80)->by(optional($request->user())->id ?: $request->ip());
        });

        RateLimiter::for('time', function (Request $request) {
            return Limit::perMinute(40)->by(optional($request->user())->id ?: $request->ip());
        });

        RateLimiter::for('upload', function (Request $request) {
            return Limit::perMinute(8)->by(optional($request->user())->id ?: $request->ip());
        });

        // Merchant Acquiring rate limiters
        RateLimiter::for('widget', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        RateLimiter::for('merchant_api', function (Request $request) {
            $apiKey = $request->header('X-API-Key');
            return Limit::perMinute(120)->by($apiKey ?: $request->ip());
        });

        RateLimiter::for('webhook_verify', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });
    }
}
