<?php

namespace App\Http\Resources\Staking;

use Illuminate\Http\Resources\Json\JsonResource;

class StakingUser extends JsonResource
{
    public static $wrap = null;
    public $preserveKeys = true;

    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $ranges = array_combine(explode(',', $this->allowed_days), explode(',', $this->rewards_percentage));

        return [
            'id' => $this->id,
            'currency' => $this->currency->name,
            'currency_symbol' => $this->currency->symbol,
            'currency_logo' => url($this->currency->logo_path),
            'days' => $this->days,
            'apy' => $this->apy,
            'reward' => $this->reward,
            'amount' => $this->amount,
            'status' => $this->status,
            'redemption_date' => $this->redemption_date->format('d-m-Y H:i'),
            'created_at' => $this->created_at->format('d-m-Y H:i'),
        ];
    }
}
