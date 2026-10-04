<?php

const GENERAL_SETTINGS = [
    'name' => [
        'type' => 'string',
        'location' => 'file',
        'key' => 'APP_NAME'
    ],
    'swap_market' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'general.swap_market'
    ],
    'default_trade_pair' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'general.default_trade_pair'
    ],
    'default_futures_pair' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'general.default_futures_pair'
    ],
    'android_download_url' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'general.android_download_url'
    ],
    'ios_download_url' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'general.ios_download_url'
    ],
    'withdrawal_limit' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'general.withdrawal_limit'
    ],
    'withdrawal_limit_kyc' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'general.withdrawal_limit_kyc'
    ],
    'logo' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'general.logo'
    ],
    'kyc_status' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'general.kyc_status'
    ],
    'maintenance_status' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'general.maintenance_status'
    ],
    'default_dark_mode_status' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'general.default_dark_mode_status'
    ],
    'dark_mode_status' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'general.dark_mode_status'
    ],
    'language_status' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'general.language_status'
    ],
    'registration_referral_required' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'general.registration_referral_required'
    ],
];

const MAIL_SETTINGS = [
    'mail_from_name' => [
        'type' => 'string',
        'location' => 'file',
        'key' => 'MAIL_FROM_NAME'
    ],
    'mail_from_address' => [
        'type' => 'string',
        'location' => 'file',
        'key' => 'MAIL_FROM_ADDRESS'
    ],
    'mail_mailer' => [
        'type' => 'string',
        'location' => 'file',
        'key' => 'MAIL_MAILER'
    ],
    'mail_host' => [
        'type' => 'string',
        'location' => 'file',
        'key' => 'MAIL_HOST'
    ],
    'mail_port' => [
        'type' => 'string',
        'location' => 'file',
        'key' => 'MAIL_PORT'
    ],
    'mail_username' => [
        'type' => 'string',
        'location' => 'file',
        'key' => 'MAIL_USERNAME'
    ],
    'mail_password' => [
        'type' => 'string',
        'location' => 'file',
        'key' => 'MAIL_PASSWORD'
    ],
    'mail_encryption' => [
        'type' => 'string',
        'location' => 'file',
        'key' => 'MAIL_ENCRYPTION'
    ],
    'mailgun_enabled' => [
        'type' => 'boolean',
        'location' => 'database',
        'key' => 'mail.mailgun_enabled'
    ],
    'mailgun_domain' => [
        'type' => 'string',
        'location' => 'file',
        'key' => 'MAILGUN_DOMAIN'
    ],
    'mailgun_secret' => [
        'type' => 'string',
        'location' => 'file',
        'key' => 'MAILGUN_SECRET'
    ],
    'mailgun_endpoint' => [
        'type' => 'string',
        'location' => 'file',
        'key' => 'MAILGUN_ENDPOINT'
    ],
];

const COINPAYMENTS_SETTINGS = [
    'public_key' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'coinpayments.public_key'
    ],
    'private_key' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'coinpayments.private_key'
    ],
    'ipn_secret' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'coinpayments.ipn_secret'
    ],
    'merchant_id' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'coinpayments.merchant_id'
    ],
    'pay_deposit_fee' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'coinpayments.pay_deposit_fee'
    ],
];

const RECAPTCHA_SETTINGS = [
    'site_key' => [
        'type' => 'string',
        'location' => 'file',
        'key' => 'NOCAPTCHA_SITEKEY'
    ],
    'secret_key' => [
        'type' => 'string',
        'location' => 'file',
        'key' => 'NOCAPTCHA_SECRET'
    ],
    'status' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'recaptcha.status'
    ],
];

const TRADE_SETTINGS = [
    'taker_fee' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'trade.taker_fee'
    ],
    'maker_fee' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'trade.maker_fee'
    ],
    'referral_fee' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'trade.referral_fee'
    ],
    'disable_trades' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'trade.disable_trades'
    ],
    // Futures timeframe toggle stored under 'futures' namespace but edited in Trade tab
    'futures_timeframe_enabled' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'futures.timeframe_enabled'
    ],
    'futures_funding_fee_rate' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'futures.funding_fee_rate'
    ],
    'futures_funding_fee_interval_hours' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'futures.funding_fee_interval_hours'
    ],
    'transfer_commission_percent' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'trade.transfer_commission_percent'
    ],
    'options_result_mode' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'trade.options_result_mode'
    ],
    'options_pnl' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'trade.options_pnl'
    ],
    'futures_maker_fee' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'futures.maker_fee'
    ],
        'lc30' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'trade.lc30'
    ],
        'lc90' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'trade.lc90'
    ],
        'lc180' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'trade.lc180'
    ],
        'lc_dq30' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'trade.lc_dq30'
    ],
    'lc365' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'trade.lc365'
    ],
        'lc_dq365' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'trade.lc_dq365'
    ],
        'lc_dq90' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'trade.lc_dq90'
    ],
        'lc_dq180' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'trade.lc_dq180'
    ],
        'lc_vip_1_boost_percent' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'trade.lc_vip_1_boost_percent'
    ],
        'lc_vip_2_boost_percent' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'trade.lc_vip_2_boost_percent'
    ],
        'lc_vip_3_boost_percent' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'trade.lc_vip_3_boost_percent'
    ],
        'lc_vip_4_boost_percent' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'trade.lc_vip_4_boost_percent'
    ],
        'lc_vip_5_boost_percent' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'trade.lc_vip_5_boost_percent'
    ],
        'lc_vip_6_boost_percent' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'trade.lc_vip_6_boost_percent'
    ],
        'lc_vip_7_boost_percent' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'trade.lc_vip_7_boost_percent'
    ],
        'lc_vip_8_boost_percent' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'trade.lc_vip_8_boost_percent'
    ],
    
    
    
    'futures_taker_fee' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'futures.taker_fee'
    ],
];

const ETHEREUM_SETTINGS = [
    'wallet' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'ethereum.wallet'
    ],
    'private_key' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'ethereum.private_key'
    ],
];

const BNB_SETTINGS = [
    'wallet' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'bnb.wallet'
    ],
    'private_key' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'bnb.private_key'
    ],
];

const SOLANA_SETTINGS = [
    'wallet' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'solana.wallet'
    ],
    'private_key' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'solana.private_key'
    ],
];

const RIPPLE_SETTINGS = [
    'wallet' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'ripple.wallet'
    ],
    'private_key' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'ripple.private_key'
    ],
];

const CUSTOMTOKEN_SETTINGS = [
    'wallet' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'customtoken.wallet'
    ],
    'private_key' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'customtoken.private_key'
    ],
];

const POLYGON_SETTINGS = [
    'wallet' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'polygon.wallet'
    ],
    'private_key' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'polygon.private_key'
    ],
];

const XLAYER_SETTINGS = [
    'wallet' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'xlayer.wallet'
    ],
    'private_key' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'xlayer.private_key'
    ],
];

const TRON_SETTINGS = [
    'wallet' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'tron.wallet'
    ],
    'private_key' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'tron.private_key'
    ],
];

const TON_SETTINGS = [
    'wallet' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'ton.wallet'
    ],
    'private_key' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'ton.private_key'
    ],
];

const STRIPE_SETTINGS = [
    'public_key' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'stripe.public_key'
    ],
    'secret_key' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'stripe.secret_key'
    ],
    'currency' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'stripe.currency'
    ],
];

const NOTIFICATION_SETTINGS = [
    'admin_email' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'notification.admin_email'
    ],
    'crypto_deposits' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'notification.crypto_deposits'
    ],
    'crypto_withdrawals' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'notification.crypto_withdrawals'
    ],
    'fiat_deposits' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'notification.fiat_deposits'
    ],
    'fiat_withdrawals' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'notification.fiat_withdrawals'
    ],
    'kyc_received' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'notification.kyc_received'
    ],
    'new_user_registered' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'notification.new_user_registered'
    ],
];

const SOCIAL_SETTINGS = [
    'youtube' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'social.youtube'
    ],
    'telegram' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'social.telegram'
    ],
    'facebook' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'social.facebook'
    ],
    'twitter' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'social.twitter'
    ],
    'reddit' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'social.reddit'
    ],
    'instagram' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'social.instagram'
    ],
    'medium' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'social.medium'
    ],
    'vk' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'social.vk'
    ],
    'discord' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'social.discord'
    ],
    'coinmarketcap' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'social.coinmarketcap'
    ],
    'github' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'social.github'
    ],
    'linkedin' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'social.linkedin'
    ],
];

const BITCOIN_SETTINGS = [
    'wallet' => [
        'type' => 'string',
        'location' => 'database',
        'key' => 'bitcoin.wallet'
    ],
];

const UNLIMIT_SETTINGS = [
    'base_url' => [
        'type' => 'string',
        'location' => 'file',
        'key' => 'UNLIMIT_BASE_URL'
    ],
    'access_key' => [
        'type' => 'string',
        'location' => 'file',
        'key' => 'UNLIMIT_ACCESS_KEY'
    ],
    'secret_key' => [
        'type' => 'string',
        'location' => 'file',
        'key' => 'UNLIMIT_SECRET_KEY'
    ],
    'partner_account_id' => [
        'type' => 'string',
        'location' => 'file',
        'key' => 'UNLIMIT_PARTNER_ACCOUNT_ID'
    ]
];


if ( ! function_exists('get_settings_by_name')) {
    function get_settings_by_name($key)
    {
        switch ($key) {
            case 'general':
                return GENERAL_SETTINGS;
            case 'trade':
                return TRADE_SETTINGS;
            case 'mail':
                return MAIL_SETTINGS;
            case 'coinpayments':
                return COINPAYMENTS_SETTINGS;
            case 'ethereum':
                return ETHEREUM_SETTINGS;
            case 'bnb':
                return BNB_SETTINGS;
            case 'xlayer':
                return XLAYER_SETTINGS;
            case 'polygon':
                return POLYGON_SETTINGS;
            case 'solana':
                return SOLANA_SETTINGS;
            case 'ripple':
                return RIPPLE_SETTINGS;
            case 'ton':
                return TON_SETTINGS;
            case 'customtoken':
                return CUSTOMTOKEN_SETTINGS;
            case 'tron':
                return TRON_SETTINGS;
            case 'recaptcha':
                return RECAPTCHA_SETTINGS;
            case 'stripe':
                return STRIPE_SETTINGS;
            case 'notification':
                return NOTIFICATION_SETTINGS;
            case 'social':
                return SOCIAL_SETTINGS;
            case 'bitcoin':
                return BITCOIN_SETTINGS;
            case 'unlimit':
                return UNLIMIT_SETTINGS;
        }
    }
}

if ( ! function_exists('update_settings_from_env'))
{
    function update_settings_from_env($key, $value)
    {
        app(\App\Services\Settings\AtomicEnvWriter::class)->update([$key => $value]);
    }
}
