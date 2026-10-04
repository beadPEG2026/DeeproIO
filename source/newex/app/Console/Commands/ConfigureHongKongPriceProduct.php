<?php

namespace App\Console\Commands;

use App\Models\User\User;
use App\Services\Market\HongKongProductListing;
use App\Services\Operations\History;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConfigureHongKongPriceProduct extends Command
{
    protected $signature = 'deepro:hk-price-product {symbol=HK08379} {--apply} {--enable-trading} {--pause-trading} {--actor=} {--reason=} {--request-key=}';
    protected $description = 'Preview or configure a chainless HK price product; never issues inventory';

    public function handle(): int
    {
        $symbol=(string)$this->argument('symbol');
        if (!config('hk-price-products.assets.'.$symbol) || ($this->option('enable-trading') && $this->option('pause-trading'))) {
            $this->error('Invalid product or conflicting state options.'); return self::FAILURE;
        }
        if (!$this->option('apply')) {
            $this->line(json_encode(['symbol'=>$symbol,'market'=>$symbol.'-USDT','operation'=>'preview',
                'reference_currency'=>'HKD','settlement_currency'=>'USDT','execution'=>'existing_spot_policy',
                'creates_inventory'=>false,'chain_transfers'=>false,'trading_requested'=>(bool)$this->option('enable-trading')]));
            return self::SUCCESS;
        }
        $actor=User::find((int)$this->option('actor')); $key=(string)$this->option('request-key'); $reason=trim((string)$this->option('reason'));
        if (!$actor || $actor->deleted || $actor->deactivated || !$actor->hasRole('superadmin') || !Str::isUuid($key) || strlen($reason)<8) {
            $this->error('An active superadmin, audit reason and UUID request key are required.'); return self::FAILURE;
        }
        $trading=$this->option('enable-trading') ? true : ($this->option('pause-trading') ? false : null);
        try {
            $id=DB::transaction(function () use ($actor,$key,$reason,$symbol,$trading) {
                DB::statement('SELECT pg_advisory_xact_lock(8192030)');
                $prior=DB::table('operations_events')->where('request_key',$key)->first();
                if ($prior) {
                    $change=json_decode($prior->changes,true);
                    if ($prior->object_type!=='hk_price_product' || (int)$prior->actor_id!==$actor->id || $prior->reason!==$reason || ($change['symbol']??null)!==$symbol || ($change['trading_requested']??null)!==$trading)
                        throw new \RuntimeException('Request key belongs to another operation.');
                    return (int)$prior->object_id;
                }
                $market=app(HongKongProductListing::class)->apply($symbol,$trading);
                History::append('hk_price_product',$market->id,'configured',[
                    'symbol'=>$symbol,'trading_requested'=>$trading,'trade_status'=>(bool)$market->trade_status,
                    'execution'=>'existing_spot_policy','inventory_issued'=>'0','network_created'=>false,
                ],$actor->id,$reason,$key);
                return $market->id;
            });
            $this->line(json_encode(['market_id'=>$id,'symbol'=>$symbol,'status'=>'configured','inventory_issued'=>'0']));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e instanceof \Illuminate\Database\QueryException ? 'Database configuration failed; rolled back.' : $e->getMessage());
            return self::FAILURE;
        }
    }
}
