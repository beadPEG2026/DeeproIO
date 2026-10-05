<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Replay the committed response before balance-sensitive FormRequest validation. */
final class WalletRequestReceipt
{
    public function handle(Request $request, Closure $next)
    {
        $key = $request->header('Idempotency-Key', $request->input('request_id'));
        if ($key === null || $key === '') { if($request->user())app(\App\Services\Deposit\DepositRisk::class)->assertClear((int)$request->user()->id); return $next($request); } // Existing clients remain compatible.
        if (!is_string($key) || !preg_match('/^[a-zA-Z0-9:_-]{8,128}$/D', $key))
            return response()->json(['message' => 'Invalid request key'], 422);
        $user = $request->user();
        if (!$user) return response()->json(['message' => 'Unauthenticated'], 401);
        $operation = (string) $request->route()->getName();
        $payload = $request->except(['_token','request_id']); ksort($payload);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($request, $next, $key, $user, $operation, $hash) {
            if (DB::getDriverName() === 'pgsql') DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ["wallet-request:{$user->id}:{$operation}:{$key}"]);
            $identity = ['user_id'=>$user->id, 'operation'=>$operation, 'request_key'=>$key];
            $old = DB::table('wallet_request_receipts')->where($identity)->first();
            if ($old) {
                if (!hash_equals($old->payload_hash, $hash)) return response()->json(['message'=>'Request key was already used for a different operation'],409);
                // Keep the JSON response type so API middleware does not encode the receipt again.
                return \Illuminate\Http\JsonResponse::fromJsonString($old->response, (int) $old->http_status)
                    ->header('Idempotency-Replayed', 'true');
            }
            app(\App\Services\Deposit\DepositRisk::class)->assertClear((int)$user->id);
            $response = $next($request);
            if ($response->isSuccessful() && str_contains((string)$response->headers->get('Content-Type'),'application/json')) {
                DB::table('wallet_request_receipts')->insert($identity + ['payload_hash'=>$hash,'http_status'=>$response->getStatusCode(),'response'=>$response->getContent(),'created_at'=>now()]);
            }
            return $response;
        }, 3);
    }
}
