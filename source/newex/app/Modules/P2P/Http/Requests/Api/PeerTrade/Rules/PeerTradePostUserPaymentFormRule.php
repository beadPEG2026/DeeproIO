<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules;

use App\Models\Currency\Currency;
use App\Modules\P2P\Models\PeerTrade\PeerPaymentField;
use App\Modules\P2P\Models\PeerTrade\PeerPaymentMethod;
use Illuminate\Contracts\Validation\Rule;

class PeerTradePostUserPaymentFormRule implements Rule
{
    public $error = 'Please enter all required fields';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $form)
    {
        $fields = PeerPaymentField::where('payment_method', request()->get('id'))->get();

        if(!$form || !is_array($form) || empty($form)) return false;

        foreach ($fields as $field) {

            if($field->required) {
                if(!isset($form[$field->id]) || trim($form[$field->id]) == "") {
                    $this->error = 'Please enter your ' . $field->title;
                    return false;
                }
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
