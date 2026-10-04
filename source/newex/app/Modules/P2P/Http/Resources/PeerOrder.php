<?php

namespace App\Modules\P2P\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PeerOrder extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $paymentMethod = null;
        $userPaymentMethod = null;

        if($this->paymentMethod) {
            $paymentMethod = [
                'id' => $this->paymentMethod->id,
                'name' => $this->paymentMethod->title,
            ];
        }

        $appealAvailable = false;
        $cancelOrderAfter = false;

        if($this->timeframe && $this->paid_at) {
            $appealAvailableAfter = now()->diffInSeconds($this->paid_at->addMinutes(10), false);
            $appealAvailable = $appealAvailableAfter <= 0;
        } else {
            $appealAvailableAfter = null;
        }

        if(!$this->paid_at) {
            $cancelOrderAfter = now()->diffInSeconds($this->created_at->addMinutes((int)$this->timeframe), false);
        }

        $user = $request->user();

        $username = $this->user->peer_username;
        $username = mask_nickname($username, $this->user->email);

        $sellername = $this->seller->peer_username;
        $sellername = mask_nickname($sellername, $this->seller->email);

        $amount = math_formatter($this->amount, 8);

        if($this->type == "buy") {

            if($this->isQuoteAmount) {

                if($user && $user->id == $this->seller->id) {
                    $amount = math_formatter($this->amount, 8);
                } else {
                    $amount = math_formatter(math_sub($this->amount, $this->fee_taker), 8);
                }

            } else {

                if($user && $user->id == $this->seller->id) {
                    $amount = math_formatter(math_sum($this->amount, $this->fee_taker), 8);
                } else {
                    $amount = math_formatter($this->amount, 8);
                }

            }

        } else {

            if($this->isQuoteAmount) {

                if($user && $user->id == $this->seller->id) {
                    $amount = math_formatter(math_sub($this->amount, $this->fee_maker), 8);
                } else {
                    $amount = math_formatter(math_sum($this->amount, $this->fee_taker), 8);
                }


            } else {

                if($user && $user->id == $this->seller->id) {
                    $amount = math_formatter(math_sub(math_sub($this->amount, $this->fee_maker), $this->fee_taker), 8);
                }

            }
        }

        $fee = $this->fee_taker;

        if($user && $user->id == $this->seller->id) {
            $fee = $this->fee_maker;
        }

        return [
            'id' => $this->id,
            'short_id' => short_uuid($this->id),
            'amount' => $amount,
            'fee_taker' => math_formatter($fee, 8),
            'fee_maker' => math_formatter($fee, 8),
            'type' => $this->type,
            'quote_amount' => math_formatter($this->quote_amount, 2, true),
            'status' => $this->status,
            'payment_method' => $paymentMethod,
            'user_payment_method' => $this->user_payment_method_id,
            'price' => math_formatter($this->price, get_p2p_decimals($this->baseCurrency->symbol)),
            'baseSymbol' => $this->baseCurrency->symbol,
            'quoteSymbol' => $this->quoteCurrency->symbol,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'user' => [
                'referral_code' => $this->user->referral_code,
                'name' => $username[0],
                'is_online' => $this->user->is_online,
                'is_merchant' => $this->user->merchant_verified_at ? true : false,
            ],
            'seller' => [
                'referral_code' => $this->seller->referral_code,
                'is_online' => $this->seller->is_online,
                'name' => $sellername[0],
                'is_merchant' => $this->seller->merchant_verified_at ? true : false,
            ],
            'appeal_stage' => $this->appeal_stage,
            'appealed_at' => $this->appealed_at ? $this->appealed_at->format('Y-m-d H:i:s') : null,
            'appeal_available_after' => $appealAvailableAfter,
            'appeal_available' => $appealAvailable,
            'cancel_order_after' => $cancelOrderAfter,
            'cancelledByOwner' => $user && $user->id == $this->cancelled_by,
            'cancelledBySystem' => $this->cancelled_by_system,
        ];
    }
}
