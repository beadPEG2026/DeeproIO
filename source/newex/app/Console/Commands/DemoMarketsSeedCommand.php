<?php

namespace App\Console\Commands;

use App\Models\Currency\Currency;
use App\Models\FileUpload\FileUpload;
use App\Models\Market\Market;
use App\Models\User\User;
use App\Services\Currency\CurrencyService;
use App\Services\Market\MarketService;
use App\Services\PaymentGateways\Coin\Ripple\Services\RippleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class DemoMarketsSeedCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market:demo-seed';

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
    public function handle()
    {
        Market::truncate();
        Currency::truncate();

        $currencies = [
            [
                'name' => 'Bitcoin',
                'symbol' => 'BTC',
                'type' => 'coin',
                'decimals' => 8,
                'status' => true,
                'deposit_status' => true,
                'withdraw_status' => true,
                'min_deposit_confirmation' => 1,
                'img' => 'btc.png',
            ],
            [
                'name' => 'Ethereum',
                'symbol' => 'ETH',
                'type' => 'coin',
                'decimals' => 18,
                'status' => true,
                'deposit_status' => true,
                'withdraw_status' => true,
                'min_deposit_confirmation' => 1,
                'img' => 'eth.png',
            ],
            [
                'name' => 'BNB',
                'symbol' => 'BNB',
                'type' => 'coin',
                'decimals' => 18,
                'status' => true,
                'deposit_status' => true,
                'withdraw_status' => true,
                'min_deposit_confirmation' => 1,
                'img' => 'bnb.png',
            ],
            [
                'name' => 'Tether',
                'symbol' => 'USDT',
                'type' => 'coin',
                'decimals' => 6,
                'status' => true,
                'deposit_status' => true,
                'withdraw_status' => true,
                'min_deposit_confirmation' => 1,
                'img' => 'usdt.png',
            ],
            [
                'name' => 'Ripple',
                'symbol' => 'XRP',
                'type' => 'coin',
                'decimals' => 18,
                'status' => true,
                'deposit_status' => true,
                'withdraw_status' => true,
                'min_deposit_confirmation' => 1,
                'img' => 'xrp.png',
            ],
            [
                'name' => 'Tron',
                'symbol' => 'TRX',
                'type' => 'coin',
                'decimals' => 18,
                'status' => true,
                'deposit_status' => true,
                'withdraw_status' => true,
                'min_deposit_confirmation' => 1,
                'img' => 'trx.png',
            ],
            [
                'name' => 'Dogecoin',
                'symbol' => 'DOGE',
                'type' => 'coin',
                'decimals' => 18,
                'status' => true,
                'deposit_status' => true,
                'withdraw_status' => true,
                'min_deposit_confirmation' => 1,
                'img' => 'doge.png',
            ],
            [
                'name' => 'Cardano',
                'symbol' => 'ADA',
                'type' => 'coin',
                'decimals' => 18,
                'status' => true,
                'deposit_status' => true,
                'withdraw_status' => true,
                'min_deposit_confirmation' => 1,
                'img' => 'ada.png',
            ],
        ];

        foreach ($currencies as $currency) {

            $full_path = '/storage/uploads/' . $currency['img'];
            $fileUpload = new FileUpload();
            $fileUpload->name = $currency['img'];
            $fileUpload->path = $full_path;
            $fileUpload->save();

            $currency['file_id'] = $fileUpload->id;
            $currency['is_p2p'] = false;

            $model = new Currency();
            $model->create($currency);
        }

        $markets = [
            [
                'name' => 'BTC-USDT',
                'base_currency_id' => Currency::where('symbol', 'BTC')->value('id'),
                'quote_currency_id' => Currency::where('symbol', 'USDT')->value('id'),
                'base_precision' => '5',
                'quote_precision' => '2',
                'min_trade_size' => '0.00001',
                'max_trade_size' => '10000000',
                'min_trade_value' => '5',
                'max_trade_value' => '100000000',
                'base_ticker_size' => '0.00001',
                'quote_ticker_size' => '0.01',
                'status' => true,
                'trade_status' => true,
                'buy_order_status' => true,
                'sell_order_status' => true,
                'cancel_order_status' => true,
                'is_tradingview' => 'custom',
                'custom_market_path' => 'BTCUSDT',
                'has_futures' => false,
            ],
            [
                'name' => 'ETH-USDT',
                'base_currency_id' => Currency::where('symbol', 'ETH')->value('id'),
                'quote_currency_id' => Currency::where('symbol', 'USDT')->value('id'),
                'base_precision' => '4',
                'quote_precision' => '2',
                'min_trade_size' => '0.0001',
                'max_trade_size' => '10000000',
                'min_trade_value' => '5',
                'max_trade_value' => '100000000',
                'base_ticker_size' => '0.0001',
                'quote_ticker_size' => '0.01',
                'status' => true,
                'trade_status' => true,
                'buy_order_status' => true,
                'sell_order_status' => true,
                'cancel_order_status' => true,
                'is_tradingview' => 'custom',
                'custom_market_path' => 'ETHUSDT',
                'has_futures' => false,
            ],
            [
                'name' => 'BNB-USDT',
                'base_currency_id' => Currency::where('symbol', 'BNB')->value('id'),
                'quote_currency_id' => Currency::where('symbol', 'USDT')->value('id'),
                'base_precision' => '3',
                'quote_precision' => '1',
                'min_trade_size' => '0.001',
                'max_trade_size' => '10000000',
                'min_trade_value' => '5',
                'max_trade_value' => '100000000',
                'base_ticker_size' => '0.001',
                'quote_ticker_size' => '0.1',
                'status' => true,
                'trade_status' => true,
                'buy_order_status' => true,
                'sell_order_status' => true,
                'cancel_order_status' => true,
                'is_tradingview' => 'custom',
                'custom_market_path' => 'BNBUSDT',
                'has_futures' => false,
            ],
            [
                'name' => 'XRP-USDT',
                'base_currency_id' => Currency::where('symbol', 'XRP')->value('id'),
                'quote_currency_id' => Currency::where('symbol', 'USDT')->value('id'),
                'base_precision' => '0',
                'quote_precision' => '4',
                'min_trade_size' => '1',
                'max_trade_size' => '10000000',
                'min_trade_value' => '5',
                'max_trade_value' => '100000000',
                'base_ticker_size' => '1',
                'quote_ticker_size' => '0.0001',
                'status' => true,
                'trade_status' => true,
                'buy_order_status' => true,
                'sell_order_status' => true,
                'cancel_order_status' => true,
                'is_tradingview' => 'custom',
                'custom_market_path' => 'XRPUSDT',
                'has_futures' => false,
            ],
            [
                'name' => 'TRX-USDT',
                'base_currency_id' => Currency::where('symbol', 'TRX')->value('id'),
                'quote_currency_id' => Currency::where('symbol', 'USDT')->value('id'),
                'base_precision' => '1',
                'quote_precision' => '4',
                'min_trade_size' => '1',
                'max_trade_size' => '10000000',
                'min_trade_value' => '5',
                'max_trade_value' => '100000000',
                'base_ticker_size' => '1',
                'quote_ticker_size' => '0.0001',
                'status' => true,
                'trade_status' => true,
                'buy_order_status' => true,
                'sell_order_status' => true,
                'cancel_order_status' => true,
                'is_tradingview' => 'custom',
                'custom_market_path' => 'TRXUSDT',
                'has_futures' => false,
            ],
            [
                'name' => 'DOGE-USDT',
                'base_currency_id' => Currency::where('symbol', 'DOGE')->value('id'),
                'quote_currency_id' => Currency::where('symbol', 'USDT')->value('id'),
                'base_precision' => '0',
                'quote_precision' => '5',
                'min_trade_size' => '1',
                'max_trade_size' => '10000000',
                'min_trade_value' => '5',
                'max_trade_value' => '100000000',
                'base_ticker_size' => '1',
                'quote_ticker_size' => '0.00001',
                'status' => true,
                'trade_status' => true,
                'buy_order_status' => true,
                'sell_order_status' => true,
                'cancel_order_status' => true,
                'is_tradingview' => 'custom',
                'custom_market_path' => 'DOGEUSDT',
                'has_futures' => false,
            ]
        ];

        foreach ($markets as $market) {
            $model = new Market();
            $model->create($market);
        }


        (new MarketService())->updateMarketsInfoCache();
        (new CurrencyService())->updateCurrenciesInfoCache();
    }
}
