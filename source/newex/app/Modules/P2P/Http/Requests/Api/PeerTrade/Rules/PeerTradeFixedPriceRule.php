<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules;

use App\Models\Currency\Currency;
use App\Modules\P2P\Repositories\PeerTrade\PeerAdRepository;
use Illuminate\Contracts\Validation\Rule;

class PeerTradeFixedPriceRule implements Rule
{
    public $error = 'Invalid amount';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $amount)
    {
        if(request()->get('type') == "float") return true;

        if(!$amount) return false;

        if((float)$amount <= 0) return false;

        $base = request()->get('coin');
        $quote = request()->get('fiat');

        $baseCurrency = Currency::where('symbol', $base)->first();
        $usdtCurrency = Currency::where('symbol', 'USDT')->first();
        $quoteCurrency = Currency::where('symbol', $quote)->first();

        if(!$usdtCurrency || !$baseCurrency || !$quoteCurrency) {
            return false;
        }

        $adRepository = new PeerAdRepository();
        $usdRate = $adRepository->getUsdRate($baseCurrency->id, $usdtCurrency->id);

        $rate = $adRepository->getFiatRate($quoteCurrency->id, $usdRate);

        $minAmount = $rate * 0.80;
        $maxAmount = $rate * 1.20;

        if($rate < $minAmount || $rate > $maxAmount) {
            $this->error = 'Fixed Price should be between ' . $minAmount . ' - ' . $maxAmount;
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
