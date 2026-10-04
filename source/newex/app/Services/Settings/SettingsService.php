<?php

namespace App\Services\Settings;

use App\Services\PaymentGateways\Coin\Ethereum\Api\EthereumGateway;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Setting;

class SettingsService
{
    /**
     *
     * @param $key
     * @param $data
     * @return void
     */
    public function updateBatch($key, $data)
    {
        if ($key === 'trade') unset($data['referral_fee']); // The versioned 8-level exchange policy replaces this obsolete single-level input.
        $source = get_settings_by_name($key);

        if (!is_array($source)) {
            $source = [];
        }

        $fileValues = [];
        foreach ($data as $field => $value) {
            if (self::unchangedSecret($field, $value)) continue;
            if (($source[$field]['location'] ?? null) === 'file') {
                $fileValues[$source[$field]['key'] ?? $field] = $value;
                unset($data[$field]);
            }
        }
        app(AtomicEnvWriter::class)->update($fileValues);
        foreach ($data as $field => $value) {
            // A masked/blank secret means keep the stored value, never replace a private key with stars.
            if (self::unchangedSecret($field, $value)) continue;
            /*
             * 原系统配置里存在的字段，继续走原来的保存逻辑。
             * 例如 env 文件配置、database 配置。
             */
            if (isset($source[$field]) && is_array($source[$field])) {
                $this->update($field, $value, $source);
                continue;
            }

            /*
             * 新增字段没有写进 get_settings_by_name() 配置时，
             * 自动保存到 settings 表。
             *
             * 例如：
             * unlimit.exchange_rate
             * unlimit.processing_fee
             */
            Setting::set($key . '.' . $field, $value);
        }

        Artisan::queue('horizon:terminate');
    }

    /**
     *
     * @param $key
     * @param $data
     * @return void
     */
    public function update($key, $value, $source)
    {
        if (!isset($source[$key]) || !is_array($source[$key])) {
            return;
        }

        if (self::unchangedSecret($key, $value)) return;

        $location = $source[$key]['location'] ?? 'database';
        $field_key = $source[$key]['key'] ?? $key;

        if ($location == "file") {
            update_settings_from_env($field_key, $value);
        }

        if ($location == "database") {
            Setting::set($field_key, $value);
        }
    }

    public static function unchangedSecret(string $field, mixed $value): bool
    {
        $sensitive = preg_match('/(?:secret|password|private_key|access_key|token|api_key)/i',$field)
            || in_array($field,['mail_username','public_key','merchant_id','client_id'],true);
        return $sensitive && ($value === null || (is_string($value) && (trim($value)==='' || preg_match('/^[*•]+$/u',trim($value)))));
    }

    public function getSettings($key)
    {
        if ($key == "trade") {
            $trade = Setting::get('trade');
            $futures = Setting::get('futures');

            return [
                'taker_fee' => $trade['taker_fee'] ?? INITIAL_TRADE_TAKER_FEE,
                'maker_fee' => $trade['maker_fee'] ?? INITIAL_TRADE_MAKER_FEE,
                'referral_fee' => $trade['referral_fee'] ?? INITIAL_REFERRAL_FEE,
                'options_result_mode' => $trade['options_result_mode'] ?? 'default',
                'options_pnl' => $trade['options_pnl'] ?? 59,
                'disable_trades' => isset($trade['disable_trades']) && $trade['disable_trades'] == 1,
                'futures_timeframe_enabled' => Setting::get('futures.timeframe_enabled', false) ? true : false,
                'futures_funding_fee_rate' => Setting::get('futures.funding_fee_rate', '0.01'),
                'futures_funding_fee_interval_hours' => Setting::get('futures.funding_fee_interval_hours', '8'),
                'transfer_commission_percent' => $trade['transfer_commission_percent'] ?? 0,
                'lc30' => $trade['lc30'] ?? 0,
                'lc90' => $trade['lc90'] ?? 0,
                'lc180' => $trade['lc180'] ?? 0,
                'lc_dq30' => $trade['lc_dq30'] ?? 0,
                'lc365' => $trade['lc365'] ?? 0,
                'lc_dq365' => $trade['lc_dq365'] ?? 0,
                'lc_dq90' => $trade['lc_dq90'] ?? 0,
                'lc_dq180' => $trade['lc_dq180'] ?? 0,
                'lc_vip_1_boost_percent' => $trade['lc_vip_1_boost_percent'] ?? 0,
                'lc_vip_2_boost_percent' => $trade['lc_vip_2_boost_percent'] ?? 0,
                'lc_vip_3_boost_percent' => $trade['lc_vip_3_boost_percent'] ?? 0,
                'lc_vip_4_boost_percent' => $trade['lc_vip_4_boost_percent'] ?? 0,
                'lc_vip_5_boost_percent' => $trade['lc_vip_5_boost_percent'] ?? 0,
                'lc_vip_6_boost_percent' => $trade['lc_vip_6_boost_percent'] ?? 0,
                'lc_vip_7_boost_percent' => $trade['lc_vip_7_boost_percent'] ?? 0,
                'lc_vip_8_boost_percent' => $trade['lc_vip_8_boost_percent'] ?? 0,
                'futures_maker_fee' => $futures['maker_fee'] ?? INITIAL_FUTURES_MAKER_FEE,
                'futures_taker_fee' => $futures['taker_fee'] ?? INITIAL_FUTURES_TAKER_FEE,
            ];
        }

        if ($key == "mail") {
            $mailSettings = Setting::get('mail');

            return [
                'mail_from_name' => env('MAIL_FROM_NAME'),
                'mail_from_address' => env('MAIL_FROM_ADDRESS'),
                'mail_mailer' => env('MAIL_MAILER'),
                'mail_host' => env('MAIL_HOST'),
                'mail_port' => env('MAIL_PORT'),
                'mail_username' => config('app.sensitive') ? str_repeat('*', 20) : env('MAIL_USERNAME'),
                'mail_password' => config('app.sensitive') ? str_repeat('*', 20) : env('MAIL_PASSWORD'),
                'mail_encryption' => env('MAIL_ENCRYPTION'),
                'mailgun_enabled' => isset($mailSettings['mailgun_enabled']) && $mailSettings['mailgun_enabled'] == 1,
                'mailgun_domain' => env('MAILGUN_DOMAIN', ''),
                'mailgun_secret' => config('app.sensitive') ? str_repeat('*', 20) : env('MAILGUN_SECRET', ''),
                'mailgun_endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
            ];
        }

        if ($key == "coinpayments") {
            $settings = Setting::get('coinpayments');

            return [
                'public_key' => config('app.sensitive') ? str_repeat('*', 20) : ($settings['public_key'] ?? ''),
                'private_key' => config('app.sensitive') ? str_repeat('*', 20) : ($settings['private_key'] ?? ''),
                'merchant_id' => config('app.sensitive') ? str_repeat('*', 20) : ($settings['merchant_id'] ?? ''),
                'ipn_secret' => config('app.sensitive') ? str_repeat('*', 20) : ($settings['ipn_secret'] ?? ''),
                'pay_deposit_fee' => isset($settings['pay_deposit_fee']) && $settings['pay_deposit_fee'] == 1,
            ];
        }

        if ($key == "general") {
            $settings = Setting::get('general');

            return [
                'name' => env('APP_NAME'),
                'logo' => $settings['logo'] ?? '',
                'kyc_status' => isset($settings['kyc_status']) && $settings['kyc_status'] == 1,
                'maintenance_status' => isset($settings['maintenance_status']) && $settings['maintenance_status'] == 1,
                'language_status' => isset($settings['language_status']) && $settings['language_status'] == 1,
                'default_dark_mode_status' => isset($settings['default_dark_mode_status']) && $settings['default_dark_mode_status'] == 1,
                'dark_mode_status' => isset($settings['dark_mode_status']) && $settings['dark_mode_status'] == 1,
                'registration_referral_required' => isset($settings['registration_referral_required']) && $settings['registration_referral_required'] == 1,
                'swap_market' => $settings['swap_market'] ?? '',
                'default_trade_pair' => $settings['default_trade_pair'] ?? '',
                'default_futures_pair' => $settings['default_futures_pair'] ?? '',
                'withdrawal_limit' => $settings['withdrawal_limit'] ?? 0,
                'withdrawal_limit_kyc' => $settings['withdrawal_limit_kyc'] ?? 0,
                'android_download_url' => $settings['android_download_url'] ?? '',
                'ios_download_url' => $settings['ios_download_url'] ?? '',
            ];
        }

        if ($key == "ethereum") {
            $settings = Setting::get('ethereum');

            return [
                'wallet' => $settings['wallet'] ?? '',
                'private_key' => config('app.sensitive') ? str_repeat('*', 20) : ($settings['private_key'] ?? ''),
            ];
        }

        if ($key == "customtoken") {
            $settings = Setting::get('customtoken');

            return [
                'wallet' => $settings['wallet'] ?? '',
                'private_key' => config('app.sensitive') ? str_repeat('*', 20) : ($settings['private_key'] ?? ''),
            ];
        }

        if ($key == "bnb") {
            $settings = Setting::get('bnb');

            return [
                'wallet' => $settings['wallet'] ?? '',
                'private_key' => config('app.sensitive') ? str_repeat('*', 20) : ($settings['private_key'] ?? ''),
            ];
        }

        if ($key == "polygon") {
            $settings = Setting::get('polygon');

            return [
                'wallet' => $settings['wallet'] ?? '',
                'private_key' => config('app.sensitive') ? str_repeat('*', 20) : ($settings['private_key'] ?? ''),
            ];
        }

        if ($key == "tron") {
            $settings = Setting::get('tron');

            return [
                'wallet' => $settings['wallet'] ?? '',
                'private_key' => config('app.sensitive') ? str_repeat('*', 20) : ($settings['private_key'] ?? ''),
            ];
        }

        if ($key == "xlayer") {
            $settings = Setting::get('xlayer');

            return [
                'wallet' => $settings['wallet'] ?? '',
                'private_key' => !empty($settings['private_key']) ? str_repeat('*', 20) : '',
            ];
        }

        if ($key == "solana") {
            $settings = Setting::get('solana');

            return [
                'wallet' => $settings['wallet'] ?? '',
                'private_key' => config('app.sensitive') ? str_repeat('*', 20) : ($settings['private_key'] ?? ''),
            ];
        }

        if ($key == "ripple") {
            $settings = Setting::get('ripple');

            return [
                'wallet' => $settings['wallet'] ?? '',
                'private_key' => config('app.sensitive') ? str_repeat('*', 20) : ($settings['private_key'] ?? ''),
            ];
        }

        if ($key == "ton") {
            $settings = Setting::get('ton');

            return [
                'wallet' => $settings['wallet'] ?? '',
                'private_key' => config('app.sensitive') ? str_repeat('*', 20) : ($settings['private_key'] ?? ''),
            ];
        }

        if ($key == "bitcoin") {
            $generatedAddress = '';
            $settings = Setting::get('bitcoin');

            return [
                'wallet' => $settings['wallet'] ?? $generatedAddress,
            ];
        }

        if ($key == "unlimit") {
            $settings = Setting::get('unlimit');

            return [
                'base_url' => env('UNLIMIT_BASE_URL', 'https://api-sandbox.gatefi.com'),
                'access_key' => config('app.sensitive') ? str_repeat('*', 20) : env('UNLIMIT_ACCESS_KEY', ''),
                'secret_key' => config('app.sensitive') ? str_repeat('*', 20) : env('UNLIMIT_SECRET_KEY', ''),
                'partner_account_id' => env('UNLIMIT_PARTNER_ACCOUNT_ID', ''),

                /*
                 * 新增：后台可配置汇率和手续费。
                 */
                'exchange_rate' => $settings['exchange_rate'] ?? Setting::get('unlimit.exchange_rate', '1'),
                'processing_fee' => $settings['processing_fee'] ?? Setting::get('unlimit.processing_fee', '0'),
            ];
        }

        if ($key == "stripe") {
            $settings = Setting::get('stripe');

            return [
                'public_key' => $settings['public_key'] ?? '',
                'secret_key' => config('app.sensitive') ? str_repeat('*', 20) : ($settings['secret_key'] ?? ''),
                'currency' => $settings['currency'] ?? 'usd',
            ];
        }

        if ($key == "recaptcha") {
            $settings = Setting::get('recaptcha');

            return [
                'site_key' => env('NOCAPTCHA_SITEKEY'),
                'secret_key' => config('app.sensitive') ? str_repeat('*', 20) : env('NOCAPTCHA_SECRET'),
                'status' => isset($settings['status']) && $settings['status'] == 1,
            ];
        }

        if ($key == "notification") {
            $settings = Setting::get('notification');

            return [
                'admin_email' => $settings['admin_email'] ?? '',
                'crypto_deposits' => isset($settings['crypto_deposits']) && $settings['crypto_deposits'] == 1,
                'crypto_withdrawals' => isset($settings['crypto_withdrawals']) && $settings['crypto_withdrawals'] == 1,
                'fiat_deposits' => isset($settings['fiat_deposits']) && $settings['fiat_deposits'] == 1,
                'fiat_withdrawals' => isset($settings['fiat_withdrawals']) && $settings['fiat_withdrawals'] == 1,
                'kyc_received' => isset($settings['kyc_received']) && $settings['kyc_received'] == 1,
                'new_user_registered' => isset($settings['new_user_registered']) && $settings['new_user_registered'] == 1,
            ];
        }

        if ($key == "social") {
            $settings = Setting::get('social');

            return [
                'youtube' => $settings['youtube'] ?? '',
                'telegram' => $settings['telegram'] ?? '',
                'facebook' => $settings['facebook'] ?? '',
                'twitter' => $settings['twitter'] ?? '',
                'reddit' => $settings['reddit'] ?? '',
                'instagram' => $settings['instagram'] ?? '',
                'medium' => $settings['medium'] ?? '',
                'vk' => $settings['vk'] ?? '',
                'discord' => $settings['discord'] ?? '',
                'coinmarketcap' => $settings['coinmarketcap'] ?? '',
                'github' => $settings['github'] ?? '',
                'linkedin' => $settings['linkedin'] ?? '',
            ];
        }

        return [];
    }

    public function getCaptchaStatus()
    {
        $status = setting('recaptcha.status', false);

        $site_key = config('captcha.sitekey');
        $site_secret = config('captcha.secret');

        return $status && $site_key && $site_secret;
    }

    public function setQrCodeTokenCache($value, $token = null, $ip = '-', $userAgent = '-')
    {
        if (!$token) {
            $token = Str::random(10);

            $token .= '---' . $ip;

            $token .= '---' . $userAgent;

            $token = base64_encode($token);
        }

        Cache::put($token, $value, 20);

        return $token;
    }

    public function getQrCodeTokenCache($token)
    {
        return Cache::get($token);
    }
}