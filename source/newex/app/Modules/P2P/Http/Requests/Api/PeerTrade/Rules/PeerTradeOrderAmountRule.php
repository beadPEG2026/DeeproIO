<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules;

use App\Models\Currency\Currency;
use App\Models\Wallet\Wallet;
use App\Modules\P2P\Repositories\PeerTrade\PeerAdRepository;
use App\Repositories\Wallet\WalletRepository;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\DB;

class PeerTradeOrderAmountRule implements Rule
{
    public $error = '';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $amount)
    {
        $repository = new PeerAdRepository();
        $user = auth()->user();
        $id = request()->get('ad_id');
        $isQuoteAmount = request()->get('isQuoteAmount', false);

        $ad = $repository->getPeerAdById($id, false, ['baseCurrency', 'quoteCurrency']);

        $usdt = DB::table('currencies')->where('symbol', 'USDT')->value('id');
        $peerAdRepository = new PeerAdRepository();

        $usdRate = $peerAdRepository->getUsdRate($ad->baseCurrency->id, $usdt);
        $rate = $peerAdRepository->getFiatRate($ad->quoteCurrency->id, $usdRate);

        $price = $ad->price;

        if($ad->price_type == "float") {
            $rate = $ad->market_id ? market_get_stats($ad->market_id, 'last') : 1;
            $price = ($ad->baseCurrency->rate * $rate * $ad->price_percentage) / 100;
        }

        if($isQuoteAmount) {

            $ratedAmount = $amount;
            $amountInCrypto = $price > 0 ? math_divide($ratedAmount, $price) : 0;

        } else {

            $amountWithoutFee = $amount;

            $takerFee = (new WalletRepository())->calculatePeerFee($amountWithoutFee, $ad->baseCurrency, $ad->type == "sell" ? 'buy' : 'sell', false);

            $amountWithoutFee += $takerFee;

            $ratedAmount = $amountWithoutFee * $price;

            $amountInCrypto = $amount;
        }


        if($ad->type == "sell") {

            if($ratedAmount < $ad->min_amount || $ratedAmount > $ad->max_amount) {
                $this->error = 'Order Limits:' . ' ' . $ad->min_amount . ' - ' . $ad->max_amount . ' ' . $ad->quoteCurrency->symbol;
                return false;
            }

            if($amountInCrypto > $ad->remaining_amount) {
                $this->error = 'The amount exceeded remaining quantity of the ad';
                return false;
            }

        }

        if($ad->type == "buy") {

            $walletRepository = new WalletRepository();

            $wallet = $walletRepository->getWalletByCurrency($user->id, $ad->baseCurrency->id, false);

            if(math_compare($amountInCrypto, $wallet->balance_in_wallet) > 0) {

                $this->error = 'Insufficient funds';

                return false;
            }

            if($ratedAmount < $ad->min_amount || $ratedAmount > $ad->max_amount) {
                $this->error = 'Order Limits:' . ' ' . $ad->min_amount . ' - ' . $ad->max_amount . ' ' . $ad->quoteCurrency->symbol;
                return false;
            }

            if($amountInCrypto > $ad->remaining_amount) {
                $this->error = 'The amount exceeded remaining quantity of the ad';
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
