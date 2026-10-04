<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules;

use App\Models\Currency\Currency;
use App\Modules\P2P\Models\PeerTrade\PeerAd;
use App\Repositories\Wallet\WalletRepository;
use Illuminate\Contracts\Validation\Rule;

class PeerTradeEditAmountRule implements Rule
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
        if(!$amount) return false;

        if((float)$amount <= 0) return false;

        $currency = Currency::type('coin')->where('symbol', request()->get('coin'))->first();

        if(!$currency) return false;

        if($amount < $currency->p2p_min_order_amount || $amount > $currency->p2p_max_order_amount) {
            $this->error = 'Amount should be between ' . $currency->p2p_min_order_amount . ' - ' . $currency->p2p_max_order_amount;
            return false;
        }

        $type = request()->get('side');

        if($type == "sell") {

            $ad = PeerAd::find(request()->get('id'));

            if($amount > $ad->remaining_amount) {
                $amount = $amount - $ad->remaining_amount;
            }

            $user = auth()->user();

            $walletRepository = new WalletRepository();

            $wallet = $walletRepository->getWalletByCurrency($user->id, $currency->id, false);
            $fee = $walletRepository->calculatePeerFee($amount, $currency, $type, true, $ad->fee_rate);

            $total = math_sum($amount, $fee);

            if($wallet->balance_in_wallet < $total) {
                $this->error = 'Insufficient balance. Your balance must be greater or equal to Wallet Balance + Maker Fee ' . $total . ' ' . $currency->symbol;
                return false;
            }

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
