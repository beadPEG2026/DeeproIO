<?php
namespace App\Http\Middleware;
use App\Services\Order\IdempotentOrderRequest;
use Closure;
class ReplayOrderRequest {
    public function handle($request, Closure $next, string $product) {
        // Authentication/maintenance middleware runs first. Replay precedes mutable balance and schedule validation.
        $user=$request->user();
        if (!$user || !$user->tokenCan('trade') || ($product !== 'options' && $user->deactivated)) return $next($request);
        $result=app(IdempotentOrderRequest::class)->replay($product);
        return $result === null ? $next($request) : response()->json(['message'=>$result]);
    }
}
