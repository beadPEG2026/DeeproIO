<?php

namespace App\Console\Commands\Market;

use App\Events\OrderBookRefreshed;
use App\Models\Market\Market;
use App\Models\Order\Order;
use App\Models\User\User;
use App\Services\Liquidity\Binance\BinanceApi;
use App\Services\Order\OrderService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

use Illuminate\Console\Command;
use Setting;

class MarketOrderProcessCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market:order-process';

    protected $market = null;

    public $maxTradeSize = null;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    protected function refreshPlatformQuote(Order $order): void
    {
        $quotes=app(\App\Services\Market\HongKongPlatformQuote::class);
        if (!$quotes->enabled($order->market) || !app(\App\Services\Market\HongKongPriceProduct::class)->sessionOpen()) return;
        // Fetch before the matching transaction, including when no browser is polling the book.
        try {$quotes->refresh($order->market);}
        catch (\Throwable $e) {Log::warning('HK pending order quote unavailable',['market'=>$order->market->name,'reason'=>$e->getMessage()]);}
    }

    public function handle()
    {
        while(true) {

            Order::limitType()->oldest()->whereNull('liquidity_id')->chunk(50, function($limitOrders) {
                foreach ($limitOrders as $order) {
                    $this->refreshPlatformQuote($order);
                    DB::transaction(function() use ($order) {
                        (new OrderService())->processOrder($order);
                    }, DB_REPEAT_AFTER_DEADLOCK);
                }
            });

            sleep(2);
        }
    }
}
