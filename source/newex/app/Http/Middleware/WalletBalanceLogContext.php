<?php

namespace App\Http\Middleware;

use App\Support\WalletBalanceLogContext as WalletBalanceLogContextSupport;
use Closure;
use Illuminate\Http\Request;

class WalletBalanceLogContext
{
    public function handle(Request $request, Closure $next)
    {
        $shouldTrack = WalletBalanceLogContextSupport::shouldTrackRequest($request);

        if ($shouldTrack) {
            WalletBalanceLogContextSupport::forRequest($request);
        }

        return $next($request);
    }
}
