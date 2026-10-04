<?php

namespace App\Http\Requests\Api\Order\Rules;

use App\Repositories\Market\MarketRepository;
use Illuminate\Contracts\Validation\Rule;

class OptionMarketRule implements Rule
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
        if(!$market->has_options) {
            $this->error = 'Option Trading is not allowed';
            return false;
        }

        try { app(\App\Services\Order\ProductTradingAvailability::class)->check($market, 'options', (string)request()->get('side')); }
        catch (\Illuminate\Validation\ValidationException $e) { $this->error='Trading is unavailable for this product or side.'; return false; }
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
