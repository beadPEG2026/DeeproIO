<?php

namespace App\Http\Requests\Api\Order\Rules;

use App\Repositories\Market\MarketRepository;
use App\Repositories\Order\OrderRepository;
use App\Repositories\Wallet\WalletRepository;
use Illuminate\Contracts\Validation\Rule;
use Auth;
use Illuminate\Support\Facades\Cache;
use Setting;

class OptionsQuantityRule implements Rule
{
    /**
     * @var MarketRepository
     */
    private $marketRepository, $error, $errorExtra;

    public function __construct()
    {
        $this->marketRepository = new MarketRepository();
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  numeric $quantity
     * @return bool
     */
    public function passes($attribute, $quantity)
    {
        // Request variables
        $market = request()->get('market');

        if(!$quantity || !is_numeric($quantity) || (mb_strpos('e', (string)$quantity) !== false) || (mb_strpos('E', (string)$quantity) !== false) || math_compare($quantity, 0) < 1) {
            $this->error = 'Invalid order quantity';
            return false;
        }

        // Get market by name
        $market = $this->marketRepository->get($market);

        if (!$market || $market->options_min_amount === null || $market->options_max_amount === null
            || !is_numeric($market->options_min_amount) || !is_numeric($market->options_max_amount)
            || (float)$market->options_min_amount < 0 || (float)$market->options_max_amount < 0
            || ((float)$market->options_max_amount > 0 && (float)$market->options_max_amount < (float)$market->options_min_amount)) {
            $this->error = "Position limits are not configured. Orders are temporarily unavailable.";
            return false;
        }

        // Check if order quantity follows min trade size rule
        if((float)$market->options_min_amount > 0 && math_compare($quantity, $market->options_min_amount) < 0) {
            $this->errorExtra = ' ' . $market->options_min_amount;
            $this->error = "Minimum allowed trade size is";
            return false;
        }

        // Check if order quantity follows max trade size rule
        if((float)$market->options_max_amount > 0 && math_compare($market->options_max_amount, $quantity) < 0) {
            $this->errorExtra = ' ' . $market->options_max_amount;
            $this->error = "Maximum allowed trade size is";
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
        return __($this->error) . $this->errorExtra;
    }
}
