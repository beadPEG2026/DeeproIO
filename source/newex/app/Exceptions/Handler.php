<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // Browser entry to the control panel must offer the administrator login
        // when a public-site account is still signed in. Never change roles or
        // redirect denied writes/API requests; their authorization stays strict.
        $this->renderable(function (\Spatie\Permission\Exceptions\UnauthorizedException $e, $request) {
            if ($request->isMethod('GET') && $request->is('exchange-control-panel', 'exchange-control-panel/*')
                && (!$request->expectsJson() || $request->inertia())
                && !\App\Support\AdminAccess::allows($request->user())) {
                return \Inertia\Inertia::location(route('admin.login'));
            }
        });

        // Handle rate limiting (429 Too Many Requests) for Inertia requests
        $this->renderable(function (ThrottleRequestsException $e, $request) {
            if ($request->inertia()) {
                return back()->withErrors([
                    'throttle' => __('Too many requests. Please wait a moment before trying again.'),
                ])->with('error', __('Too many requests. Please wait a moment before trying again.'));
            }
        });
    }
}
