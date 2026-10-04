<?php

namespace App\Http\Resources\Market\Cmc;

use Illuminate\Http\Resources\Json\JsonResource;
use Setting;

class AssetCmc extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        self::withoutWrapping();

        return [
            'name' => $this->name,
            "unified_cryptoasset_id" => $this->id,
            "can_withdraw" => $this->withdraw_status,
            "can_deposit" => $this->deposit_status,
            "min_withdraw" => $this->min_withdraw,
            "max_withdraw" => $this->max_withdraw,
            "maker_fee" => Setting::get('trade.maker_fee', INITIAL_TRADE_MAKER_FEE),
            "taker_fee" => Setting::get('trade.taker_fee', INITIAL_TRADE_TAKER_FEE)
        ];
    }
}
