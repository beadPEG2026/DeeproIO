<?php

namespace App\Http\Requests\Web\ColdStorage;

use App\Http\Requests\Web\ColdStorage\Rules\ColdStorageCreateRule;
use App\Http\Requests\Web\Currency\Rules\CurrencyNetworkRule;
use App\Http\Requests\Web\Currency\Rules\CurrencyStatusRule;
use App\Http\Requests\Web\Currency\Rules\CurrencySymbolRule;
use App\Http\Requests\Web\Currency\Rules\CurrencyTypeRule;
use Illuminate\Foundation\Http\FormRequest;
use Auth;
use Illuminate\Validation\Rule;

class ColdStorageFormRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'id' => ['sometimes', 'integer', 'numeric', 'required', 'exists:cold_storage'],
            'status' => ['required', 'boolean'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'network_id' => ['required', 'integer', 'exists:networks,id'],
            'address' => ['nullable', 'string', 'max:128'],
            'cold_min_balance_amount' => ['required', 'numeric','gt:0','regex:/^\d{1,18}(\.\d{1,18})?$/D'],
            'cold_transfer_amount' => ['required', 'numeric','gt:0','regex:/^\d{1,18}(\.\d{1,18})?$/D', 'lte:cold_min_balance_amount'],
            'hot_reserve' => ['required','numeric','gte:0','regex:/^\d{1,18}(\.\d{1,18})?$/D'],
            'daily_limit' => ['required','numeric','gt:0','regex:/^\d{1,18}(\.\d{1,18})?$/D','gte:cold_transfer_amount'],
        ];
    }

    public function withValidator($validator): void {
        $validator->after(function($v){
            if($v->errors()->count())return;
            try {
                $a=\App\Services\Custody\CustodyNetwork::asset((int)$this->currency_id,(int)$this->network_id);
                if($a['chain']==='bitcoin')foreach(['cold_min_balance_amount','cold_transfer_amount','hot_reserve','daily_limit'] as $field){
                    try{\App\Services\Wallet\BitcoinWalletRpc::amount($this->input($field));}
                    catch(\RuntimeException $e){$v->errors()->add($field,__('BTC_INVALID_AMOUNT'));}
                }
                $address=trim((string)$this->address);
                if($address)\App\Services\Custody\CustodyNetwork::address($a['chain'],$address);
                if($this->boolean('status')&&!$address)$v->errors()->add('address',__('CUSTODY_ADDRESS_REQUIRED'));
                if($address&&strcasecmp($address,app(\App\Services\Custody\CustodyService::class)->hotSender($a['chain']))===0)$v->errors()->add('address',__('CUSTODY_SELF_TRANSFER'));
            } catch(\Throwable $e) {$code=preg_match('/^BTC_[A-Z_0-9]+$/D',$e->getMessage())?$e->getMessage():'CUSTODY_UNSUPPORTED_ASSET';$v->errors()->add($code==='BTC_INVALID_ADDRESS'?'address':'network_id',__($code));}
        });
    }
}
