<?php

namespace App\Http\Requests\Web\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Auth;

class SettingsFormRequest extends FormRequest
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
    private const GROUPS = ['general','trade','mail','coinpayments','ethereum','bnb','bitcoin','polygon','xlayer','solana','ripple','ton','tron','customtoken','stripe','unlimit','recaptcha','notification','social'];

    protected function prepareForValidation(): void
    {
        $data=$this->except(['_token','_method']);
        foreach ($data as $group=>&$fields) if (is_array($fields)) foreach ($fields as $field=>$value) {
            if (\App\Services\Settings\SettingsService::unchangedSecret((string)$field,$value)) unset($fields[$field]);
        }
        unset($fields);
        foreach (['mail_mailer', 'mail_encryption'] as $field) if (isset($data['mail'][$field])) $data['mail'][$field] = strtolower(trim($data['mail'][$field]));
        $this->replace($data);
    }

    public function withValidator($validator): void
    {
        $validator->after(function($validator) {
            if (count($this->all())!==1 || array_diff(array_keys($this->all()),self::GROUPS)) $validator->errors()->add('settings',__('设置分组无效。'));
            $limits = array_merge((new \App\Services\Settings\SettingsService())->getSettings('general'), (array) $this->input('general', []));
            if ($this->has('general') && is_numeric($limits['withdrawal_limit'] ?? null) && is_numeric($limits['withdrawal_limit_kyc'] ?? null) && $limits['withdrawal_limit'] > 0 && $limits['withdrawal_limit_kyc'] > $limits['withdrawal_limit']) $validator->errors()->add('general.withdrawal_limit_kyc', __('The unverified limit cannot exceed the verified limit.'));
        });
    }

    public function rules(): array
    {
        $rules=[];
        $bools=['kyc_status','maintenance_status','language_status','default_dark_mode_status','dark_mode_status','registration_referral_required','disable_trades','futures_timeframe_enabled','mailgun_enabled','pay_deposit_fee','status','crypto_deposits','crypto_withdrawals','fiat_deposits','fiat_withdrawals','kyc_received','new_user_registered'];
        foreach (self::GROUPS as $group) {
            $fields=array_keys(get_settings_by_name($group)??[]);
            if ($group==='unlimit') $fields=array_merge($fields,['exchange_rate','processing_fee']);
            $rules[$group]=['sometimes','array:'.implode(',',$fields)];
            foreach ($fields as $field) {
                $rule=['sometimes','nullable','string','max:1200','not_regex:/[\r\n\x00]/'];
                if (in_array($field,$bools,true)) $rule=['sometimes','required','boolean'];
                elseif ($group==='trade' && !in_array($field,['options_result_mode','futures_funding_fee_interval_hours'],true)) $rule=['sometimes','required','numeric','min:0','max:100'];
                elseif (in_array($field,['withdrawal_limit','withdrawal_limit_kyc'],true)) $rule=['sometimes','required','numeric','min:0','max:1000000000'];
                elseif ($field==='exchange_rate') $rule=['sometimes','required','numeric','gt:0','max:1000000000'];
                elseif ($field==='processing_fee') $rule=['sometimes','required','numeric','min:0','max:100'];
                elseif ($field==='futures_funding_fee_interval_hours') $rule=['sometimes','required','integer','min:1','max:168'];
                elseif ($field==='mail_port') $rule=['sometimes','nullable','integer','min:1','max:65535'];
                elseif (in_array($field,['admin_email','mail_from_address'],true)) $rule=['sometimes','nullable','email','max:255'];
                elseif ($group==='social' || in_array($field,['android_download_url','ios_download_url','base_url'],true)) $rule=['sometimes','nullable','url:http,https','max:1200'];
                if ($field === 'options_result_mode') $rule = ['sometimes', 'required', 'in:default,win,lose'];
                if ($field === 'mail_mailer') $rule = ['sometimes', 'required', \Illuminate\Validation\Rule::in(array_keys(config('mail.mailers', [])))];
                if ($field === 'mail_encryption') $rule = ['sometimes', 'nullable', 'in:ssl,tls'];
                if ($field === 'mail_host') $rule = ['sometimes', 'required', 'string', 'max:253', 'regex:/^[a-zA-Z0-9.-]+$/D'];
                if (in_array($field, ['swap_market','default_trade_pair','default_futures_pair'], true)) $rule = ['sometimes','nullable','string',\Illuminate\Validation\Rule::exists('markets','name')->whereNull('deleted_at')];
                if ($group === 'stripe' && $field === 'currency') $rule = ['sometimes','required','string','regex:/^[A-Za-z]{3}$/D'];
                if ($field === 'wallet' && in_array($group, ['ethereum','bnb','polygon','xlayer'], true)) $rule = ['sometimes','nullable','regex:/^0x[0-9a-fA-F]{40}$/D'];
                if ($field === 'wallet' && $group === 'tron') $rule = ['sometimes','nullable','regex:/^T[1-9A-HJ-NP-Za-km-z]{33}$/D'];
                if ($field === 'private_key' && in_array($group, ['ethereum','bnb','polygon','xlayer','tron'], true)) $rule = ['sometimes','nullable','regex:/^(?:0x)?[0-9a-fA-F]{64}$/D'];
                if ($group === 'general' && $field === 'logo') $rule = ['sometimes','nullable','string','max:1200','regex:~^(?:/(?!/)|https://)[^\\s<>]+$~D'];
                $rules[$group.'.'.$field]=$rule;
            }
        }
        return $rules;
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array
     */
    public function attributes()
    {
        return [
            'general.name' => 'Site name',
            'general.logo' => 'Site logo',

            'trade.maker_fee' => 'Maker Fee',
            'trade.taker_fee' => 'Taker Fee',
            'trade.futures_timeframe_enabled' => 'Futures Timeframe',
            'trade.futures_maker_fee' => 'Futures Maker Fee',
            'trade.futures_taker_fee' => 'Futures Taker Fee',

            'mail.mail_encryption' => 'Mail Encryption',
            'mail.mail_from_address' => 'Mail Address',
            'mail.mail_from_name' => 'Mail Name',
            'mail.mail_host' => 'Mail Host',
            'mail.mail_mailer' => 'Mail Type',
            'mail.mail_password' => 'Mail Password',
            'mail.mail_port' => 'Mail Port',
            'mail.mail_username' => 'Mail Username',
            'mail.mailgun_enabled' => 'Mailgun Enabled',
            'mail.mailgun_domain' => 'Mailgun Domain',
            'mail.mailgun_secret' => 'Mailgun API Key',
            'mail.mailgun_endpoint' => 'Mailgun Endpoint',

            'coinpayments.ipn_secret' => 'IPN Secret',
            'coinpayments.merchant_id' => 'Merchant ID',
            'coinpayments.private_key' => 'Private Key',
            'coinpayments.public_key' => 'Public Key',

            'ethereum.private_key' => 'Private Key',
            'ethereum.wallet' => 'Wallet',

            'bnb.private_key' => 'Private Key',
            'bnb.wallet' => 'Wallet',

            'tron.private_key' => 'Private Key',
            'tron.wallet' => 'Wallet',

            'stripe.currency' => 'Base Currency',
            'stripe.public_key' => 'Public Key',
            'stripe.secret_key' => 'Private Key',

            'recaptcha.secret_key' => 'Secret Key',
            'recaptcha.site_key' => 'Site Key',
            'recaptcha.status' => 'Status',

            'notification.admin_email' => "Admin Email",
            'notification.crypto_deposits' => "Crypto Deposits",
            'notification.crypto_withdrawals' => "Crypto Withdrawals",
            'notification.fiat_deposits' => "Fiat Deposits",
            'notification.fiat_withdrawals' => "Fiat Withdrawals",
            'notification.kyc_received' => "KYC Received",
            'notification.new_user_registered' => "New User Registered",
        ];
    }
}
