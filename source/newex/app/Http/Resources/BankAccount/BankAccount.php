<?php

namespace App\Http\Resources\BankAccount;

use Illuminate\Http\Resources\Json\JsonResource;

class BankAccount extends JsonResource
{
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'name' => $this->name,
            'reference_number' => $this->reference_number,
            'iban' => $this->iban,
            'swift' => $this->swift,
            'ifsc' => $this->ifsc,
            'country' => $this->country->name,
            'address' => $this->address,
            'account_holder_name' => $this->account_holder_name,
            'account_holder_address' => $this->account_holder_address,
            'note' => $this->note,
            'min_deposit' => $this->min_deposit,
            'max_deposit' => $this->max_deposit,
            'min_withdraw' => $this->min_withdraw,
            'max_withdraw' => $this->max_withdraw,
            'has_payment_id' => $this->has_payment_id
        ];
    }
}
