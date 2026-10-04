<?php

namespace App\Modules\P2P\Http\Resources;

use App\Http\Resources\Country\CountryCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class PeerAd extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $sellername = $this->user->peer_username;
        $sellername = mask_nickname($sellername, $this->user->email);


        return [
            'id' => $this->id,
            'short_id' => short_uuid($this->id),
            'amount' => math_formatter($this->amount, 8),
            'remaining_amount' => math_formatter($this->remaining_amount, 8),
            'completed_quantity' => math_formatter(math_sub($this->amount, $this->remaining_amount), 8),
            'min_amount' => $this->min_amount,
            'max_amount' => $this->max_amount,
            'timeframe' => $this->timeframe,
            'price_type' => $this->price_type,
            'fixed_price' => $this->price,
            'price_percentage' => $this->price_percentage,
            'offered_price' => math_formatter($this->offered_price, get_p2p_decimals($this->baseCurrency->symbol)),
            'auto_reply' => $this->auto_reply,
            'remarks' => $this->remarks,
            'type' => $this->type,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'status' => $this->status,
            'hidden_reason' => $this->hidden_reason,
            'payment_methods' => new PaymentMethodCollection($this->paymentMethods),
            'regions' => new CountryCollection($this->regions),
            'coin' => [
                'symbol' => $this->baseCurrency->symbol,
                'logo' => url($this->baseCurrency->logo_path),
            ],
            'fiat' => [
                'symbol' => $this->quoteCurrency->symbol,
                'logo' => url($this->quoteCurrency->logo_path),
            ],
            'user' => [
                'id' => $this->user->referral_code,
                'is_online' => $this->user->is_online,
                'username' => $sellername[0],
                'username_short' => $sellername[1],
                'total_orders' => $this->user->orders_completed_thirty,
                'orders_completion_rate' => math_formatter($this->user->orders_completion_rate ?? 0, 2),
                'feedback_percentage' => $this->user->feedback_percentage,
                'orders_avg_paytime' => math_formatter($this->user->orders_avg_paytime / 60, 2),
                'orders_avg_releasetime' => math_formatter($this->user->orders_avg_releasetime / 60, 2),
                'is_merchant' => $this->user->merchant_verified_at ? true : false,
            ],
        ];
    }
}
