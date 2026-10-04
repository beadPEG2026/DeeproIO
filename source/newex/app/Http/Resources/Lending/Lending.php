<?php

namespace App\Http\Resources\Lending;

use Illuminate\Http\Resources\Json\JsonResource;

class Lending extends JsonResource
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
        return [
            'id' => $this->id,
            'currency' => $this->currency->name,
            'currency_id' => $this->currency->id,
            'currency_symbol' => $this->currency->symbol,
            'currency_logo' => url($this->currency->logo_path),
            'min_amount' => $this->min_amount,
            'max_amount' => $this->max_amount,
            'annual_rate_flexible' => $this->annual_rate_flexible,
            'annual_rate_weekly' => $this->annual_rate_weekly,
            'annual_rate_monthly' => $this->annual_rate_monthly,
            'hourly_rate_flexible' => math_formatter($this->annual_rate_flexible / 365 / 24, 8),
            'hourly_rate_weekly' => math_formatter($this->annual_rate_weekly / 365 / 24, 8),
            'hourly_rate_monthly' => math_formatter($this->annual_rate_monthly / 365 / 24, 8),
            'status' => $this->status,
            'created_at' => $this->created_at->format('d-m-Y H:i'),
        ];
    }
}
