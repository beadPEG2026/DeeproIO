<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules;

use App\Models\Currency\Currency;
use App\Modules\P2P\Models\PeerTrade\PeerAd;
use App\Modules\P2P\Models\PeerTrade\PeerOrder;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

class PeerTradeOrderPaymentStatusRule implements Rule
{
    public $error = 'Invalid order status';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $status)
    {
        $id = request()->get('id');
        $reason = request()->get('reason');
        $message = request()->get('message');

        if(!$status || !is_uuid_valid($id)) return false;

        $order = PeerOrder::where('id', $id)->first();

        if(!$order) return false;

        $user = request()->user();

        if($status == "confirm_transfer") {

            if($order->status !== "payment_pending") {
                return false;
            }

            if($order->type == "buy") {

                if($order->user_id !== $user->id) {
                    return false;
                }


            } else {

                if($order->ad_user_id !== $user->id) {
                    return false;
                }

            }

            return true;


        } elseif($status == "cancel") {

            if($order->status !== "confirm_transfer" && $order->status !== "payment_pending") {
                return false;
            }

            if($order->type == "buy") {

                if($order->user_id !== $user->id) {
                    return false;
                }


            } else {

                if($order->ad_user_id !== $user->id) {
                    return false;
                }

            }

            $buyerReason = config('app.p2p.default_cancellation_reasons_buyer');
            $sellerReason = config('app.p2p.default_cancellation_reasons_seller');

            $totalBuyer = count($buyerReason);
            $totalSeller = count($sellerReason);

            if(intval($reason) > ($totalBuyer + $totalSeller) || intval($reason) < 1) {
                return false;
            }

            if(intval($reason) == $totalBuyer && (!$message || trim($message) == "" || mb_strlen($message) > 500)) {
                return false;
            }

            return true;

        } elseif($status == "completed") {

            if($order->status !== "confirm_transfer") {
                return false;
            }

            if($order->type == "buy") {

                if($order->ad_user_id !== $user->id) {
                    return false;
                }


            } else {

                if($order->user_id !== $user->id) {
                    return false;
                }

            }

            if($user->two_factor_secret) {
                $google2fa = app(Google2FA::class);

                $valid = $google2fa->verifyKey(decrypt($user->two_factor_secret), (string)request()->get('twofa'), 1);

                if (!$valid) {
                    $this->error = '2FA Code is invalid or expired.';
                    return false;
                }
            }

            return true;

        }
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
