<?php
namespace App\Services\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
final class IdempotentOrderRequest {
    public function run(string $product, callable $operation) {
        $header=request()->header('Idempotency-Key'); $body=request()->input('client_order_id');
        if (($header === null || $header === '') && ($body === null || $body === '')) return $operation();
        if ($header && $body && $header !== $body) throw ValidationException::withMessages(['client_order_id'=>__('Idempotency keys do not match.')]);
        $key=(string)($header ?: $body);
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D',$key)) throw ValidationException::withMessages(['client_order_id'=>__('Invalid client order identifier.')]);
        $hash=$this->requestHash(); $user=(int)auth()->id();
        return DB::transaction(function() use($product,$operation,$key,$hash,$user) {
            if (DB::getDriverName()==='pgsql') DB::select('SELECT pg_advisory_xact_lock(8192027, ?)', [hexdec(substr(hash('sha256',$user.'|'.$product.'|'.$key),0,7))]);
            $query=DB::table('trading_request_receipts')->where(['user_id'=>$user,'product'=>$product,'client_key'=>$key]);
            $receipt=(clone $query)->lockForUpdate()->first();
            if ($receipt) {
                $this->assertHash($receipt->request_hash, $hash);
                if ($receipt->result_id !== null) return $receipt->result_id;
            } else {
                DB::table('trading_request_receipts')->insert(['user_id'=>$user,'product'=>$product,'client_key'=>$key,'request_hash'=>$hash,'created_at'=>now(),'updated_at'=>now()]);
            }
            $result=$operation();
            if ($result===false || $result===null) throw ValidationException::withMessages(['order'=>__('Order request was not processed.')]);
            $query->update(['result_id'=>(string)$result,'updated_at'=>now()]);
            return $result;
        },3);
    }
    public function replay(string $product): ?string {
        $header=request()->header('Idempotency-Key'); $body=request()->input('client_order_id');
        if (!$header && !$body) return null;
        if ($header && $body && $header !== $body) throw ValidationException::withMessages(['client_order_id'=>__('Idempotency keys do not match.')]);
        $receipt=DB::table('trading_request_receipts')->where(['user_id'=>(int)auth()->id(),'product'=>$product,'client_key'=>(string)($header ?: $body)])->first();
        if (!$receipt) return null;
        $this->assertHash($receipt->request_hash,$this->requestHash());
        return $receipt->result_id;
    }
    private function requestHash(): string {
        $input=request()->except(['client_order_id','_token']); ksort($input);
        return hash('sha256',json_encode($input,JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));
    }
    private function assertHash(string $stored,string $requested): void {
        if (!hash_equals($stored,$requested)) throw ValidationException::withMessages(['client_order_id'=>__('Client order identifier was already used for a different request.')])->status(409);
    }
}
