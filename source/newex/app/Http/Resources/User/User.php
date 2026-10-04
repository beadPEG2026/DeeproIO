<?php

namespace App\Http\Resources\User;

use Illuminate\Http\Resources\Json\JsonResource;

class User extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $username = $this->peer_username;

        $username = mask_nickname($username, $this->email);

        return [
            'id' => $this->referral_code,
            'nickname' => $username[0],
            'orders_completed' => $this->orders_completed,
            'orders_completed_thirty' => $this->orders_completed_thirty,
            'orders_buy_completed' => $this->orders_buy_completed,
            'orders_sell_completed' => $this->orders_sell_completed,
            'orders_buy_completed_thirty' => $this->orders_buy_completed_thirty,
            'orders_sell_completed_thirty' => $this->orders_sell_completed_thirty,
            'orders_completion_rate' => math_formatter($this->orders_completion_rate ?? 0, 2),
            'orders_avg_paytime' => math_formatter($this->orders_avg_paytime / 60, 2),
            'orders_avg_releasetime' => math_formatter($this->orders_avg_releasetime / 60, 2),
            'nickname_first' => $username[1],
            'joined_at' => $this->created_at->format('Y-m-d'),
            'last_seen_at' => $this->last_seen_at ? str_replace('from now', 'ago', $this->last_seen_at->diffForHumans()) : null,
            'email_verified' => $this->email_verified_at ? true : false,
            'kyc_verified' => $this->kyc_verified_at ? true : false,
            'is_merchant' => $this->merchant_verified_at ? true : false,
        ];
    }
}
