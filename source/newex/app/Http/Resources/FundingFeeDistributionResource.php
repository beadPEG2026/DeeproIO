<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FundingFeeDistributionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', function() {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                ];
            }),
            'futures_contract_id' => $this->futures_contract_id,
            'market_id' => $this->market_id,
            'market' => $this->whenLoaded('market', function() {
                return [
                    'id' => $this->market->id,
                    'name' => $this->market->name,
                    'base_currency' => $this->market->base_currency,
                    'quote_currency' => $this->market->quote_currency,
                    'quote_precision' => $this->market->quote_precision,
                    'quote_currency_type' => $this->market->quote_currency_type,
                    'quote_currency_id' => $this->market->quote_currency_id,
                    'quote_currency_symbol' => $this->market->quoteCurrency ? $this->market->quoteCurrency->symbol : null,
                ];
            }),
            'funding_fee_amount' => $this->funding_fee_amount,
            'position_size' => $this->position_size,
            'funding_rate' => $this->funding_rate,
            'is_long' => $this->is_long,
            'distributed_at' => $this->distributed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
