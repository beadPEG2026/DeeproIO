<?php

namespace App\Http\Requests\Api\Order\Rules;

use App\Repositories\Market\MarketRepository;
use Illuminate\Contracts\Validation\Rule;

class OrderFuturesMarketRule implements Rule
{
    public $error = 'Invalid market name';

    /**
     * @var MarketRepository
     */
    private $marketRepository;

    public function __construct()
    {
        $this->marketRepository = new MarketRepository();
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $market)
    {
        // Check if market is active and valid
        if(!$market = $this->marketRepository->get($market, false, false)) {
            return false;
        }

        // Check if market is tradable
        if(setting('trade.disable_trades', false)) {
            $this->error = 'Trades are not allowed';
            return false;
        }

        if(setting('general.maintenance_status', false) && !request()->user()->hasRole('admin')) {
            $this->error = 'Maintenance mode enabled';
            return false;
        }

        // Check if market is tradable
        if(!market_is_tradable($market)) {
            $this->error = 'Trades are not allowed';
            return false;
        }

        // Check if sell orders are allowed
        if(request()->get('side') == "buy" && !market_is_buy_orders_allowed($market)) {
            $this->error = 'Buy orders are not allowed';
            return false;
        }

        // Check if buy orders are allowed
        if(request()->get('side') == "sell" && !market_is_sell_orders_allowed($market)) {
            $this->error = 'Sell orders are not allowed';
            return false;
        }

        if(\App\Services\Market\HongKongPriceProduct::isMarket($market) || !$market->has_futures) {
            $this->error = 'Futures are not allowed for this market';
            return false;
        }

        return true;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return __($this->error);
    }
}
