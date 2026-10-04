<?php

namespace App\Models\Currency;

use App\Casts\CryptoCurrencyDecimalCast;
use App\Casts\PercentageDecimalCast;
use App\Models\Currency\Traits\Relations\CurrencyRelation;
use App\Models\Currency\Traits\Scopes\CurrencyScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Currency extends Model
{
    public function getLogoPathAttribute(): string
    {
        $icon = config('currency-icons.' . $this->id);
        if ($icon && $icon['symbol'] === $this->symbol
            && strtolower($icon['contract']) === strtolower((string) $this->contract)
            && strtolower($icon['bep_contract']) === strtolower((string) $this->bep_contract)
            && is_file(public_path($icon['path']))) {
            return $icon['path'];
        }
        if ($catalog=\App\Services\Market\CatalogIcon::path($this)) return $catalog;
        $path = $this->file?->path;
        if ($path && (str_starts_with($path, 'https://') || is_file(public_path($path)))) return $path;
        return '/images/currency-placeholder.svg';
    }

    public $table = 'currencies';

    use HasFactory, SoftDeletes, CurrencyRelation, CurrencyScope;

    public $fillable = [
        'asset_category', 'asset_issuer', 'asset_unit', 'asset_display_enabled', 'asset_chart_interval',
        'name',
        'symbol',
        'alt_symbol',
        'type',
        'decimals',
        'status',
        'bank_account',
        'deposit_status',
        'withdraw_status',
        'file_id',

        //fee
        'deposit_fee',
        'deposit_fee_fixed',

        'deposit_fee_bep_fixed',
        'deposit_fee_erc_fixed',
        'deposit_fee_sol_fixed',
        'deposit_fee_trc_fixed',
        'deposit_fee_matic_fixed',

        'deposit_fee_bep',
        'deposit_fee_erc',
        'deposit_fee_sol',
        'deposit_fee_trc',
        'deposit_fee_matic',

        'withdraw_fee',
        'withdraw_fee_fixed',

        'withdraw_fee_bep_fixed',
        'withdraw_fee_erc_fixed',
        'withdraw_fee_trc_fixed',
        'withdraw_fee_sol_fixed',
        'withdraw_fee_matic_fixed',

        'withdraw_fee_bep',
        'withdraw_fee_erc',
        'withdraw_fee_trc',
        'withdraw_fee_sol',
        'withdraw_fee_matic',

        'min_deposit',
        'max_deposit',
        'min_withdraw',
        'max_withdraw',
        'min_deposit_confirmation',
        'contract',
        'bep_contract',
        'trc_contract',
        'sol_contract',
        'matic_contract',
        'xlayer_contract', 'deposit_fee_xlayer', 'deposit_fee_xlayer_fixed', 'withdraw_fee_xlayer', 'withdraw_fee_xlayer_fixed',
        'custom_contract',
        'coinpayments_description',
        'bank_status',
        'cc_status',
        'cc_exchange_rate',
        'has_payment_id',
        'txn_explorer',
        'cold_min_balance_amount',
        'cold_transfer_amount',
        'cold_storage_id',
        'enable_cold',
        'inusd',
        'is_p2p',

        'p2p_min_order_amount',
        'p2p_max_order_amount',
        'p2p_maker_buy_fee',
        'p2p_maker_sell_fee',
        'p2p_taker_buy_fee',
        'p2p_taker_sell_fee',

        'p2p_maker_buy_min_fee',
        'p2p_maker_sell_min_fee',
        'p2p_taker_buy_min_fee',
        'p2p_taker_sell_min_fee',
        'is_stable',
        'disabled_withdrawal_networks',
        'disabled_deposit_networks',
        'is_token',
        'social_discord',
        'social_twitter',
        'social_website',

        // Merchant acquiring
        'is_merchant',
        'merchant_fee_percent',
        'merchant_min_amount_usd',
        'merchant_max_amount_usd',
        'merchant_confirmations',
        'merchant_enabled_networks',

        // Unlimit buy settings
        'allowed_buy_fiats',
        'allowed_buy_fiat',

        // Unlimit sell (off-ramp) settings
        'allowed_sell_crypto',
        'allowed_sell_fiat',
        'allowed_sell_fiats',
    ];

    protected $casts = [
        'asset_reference' => 'array',
        'asset_display_enabled' => 'boolean',
        'status' => 'boolean',
        'deposit_status' => 'boolean',
        'withdraw_status' => 'boolean',
        'bank_status' => 'boolean',
        'cc_status' => 'boolean',
        'enable_cold' => 'boolean',
        'is_p2p' => 'boolean',
        'is_stable' => 'boolean',
        'is_token' => 'boolean',
        'is_merchant' => 'boolean',
        'allowed_buy_fiat' => 'boolean',
        'allowed_buy_fiats' => 'array',
        'allowed_sell_crypto' => 'boolean',
        'allowed_sell_fiat' => 'boolean',
        'allowed_sell_fiats' => 'array',
        'merchant_fee_percent' => 'decimal:4',
        'merchant_min_amount_usd' => 'decimal:2',
        'merchant_max_amount_usd' => 'decimal:2',
        'merchant_enabled_networks' => 'array',
        'deposit_fee'=> PercentageDecimalCast::class,
        'deposit_fee_fixed'=> PercentageDecimalCast::class,

        'deposit_fee_bep'=> CryptoCurrencyDecimalCast::class,
        'deposit_fee_erc'=> CryptoCurrencyDecimalCast::class,
        'deposit_fee_trc'=> CryptoCurrencyDecimalCast::class,
        'deposit_fee_matic'=> CryptoCurrencyDecimalCast::class,
        'deposit_fee_sol'=> CryptoCurrencyDecimalCast::class,

        'deposit_fee_bep_fixed'=> CryptoCurrencyDecimalCast::class,
        'deposit_fee_erc_fixed'=> CryptoCurrencyDecimalCast::class,
        'deposit_fee_trc_fixed'=> CryptoCurrencyDecimalCast::class,
        'deposit_fee_sol_fixed'=> CryptoCurrencyDecimalCast::class,
        'deposit_fee_matic_fixed'=> CryptoCurrencyDecimalCast::class,

        'withdraw_fee'=> CryptoCurrencyDecimalCast::class,
        'withdraw_fee_bep'=> CryptoCurrencyDecimalCast::class,
        'withdraw_fee_erc'=> CryptoCurrencyDecimalCast::class,
        'withdraw_fee_trc'=> CryptoCurrencyDecimalCast::class,
        'withdraw_fee_matic'=> CryptoCurrencyDecimalCast::class,
        'withdraw_fee_sol'=> CryptoCurrencyDecimalCast::class,

        'withdraw_fee_fixed'=> CryptoCurrencyDecimalCast::class,
        'withdraw_fee_sol_fixed'=> CryptoCurrencyDecimalCast::class,
        'withdraw_fee_bep_fixed'=> CryptoCurrencyDecimalCast::class,
        'withdraw_fee_erc_fixed'=> CryptoCurrencyDecimalCast::class,
        'withdraw_fee_trc_fixed'=> CryptoCurrencyDecimalCast::class,
        'withdraw_fee_matic_fixed'=> CryptoCurrencyDecimalCast::class,

        'min_deposit'=> CryptoCurrencyDecimalCast::class,
        'min_withdraw'=> CryptoCurrencyDecimalCast::class,
        'max_withdraw'=> CryptoCurrencyDecimalCast::class,
        'wallet_balance' => CryptoCurrencyDecimalCast::class,
        'wallet_balance_erc' => CryptoCurrencyDecimalCast::class,
        'wallet_balance_trc' => CryptoCurrencyDecimalCast::class,
        'wallet_balance_bep' => CryptoCurrencyDecimalCast::class,
        'wallet_balance_sol' => CryptoCurrencyDecimalCast::class,
        'wallet_balance_matic' => CryptoCurrencyDecimalCast::class,
        'locked_balance' => CryptoCurrencyDecimalCast::class,
    ];

    protected function disabledDepositNetworks(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? array_map('intval', explode(',', $value)) : [],
            set: fn ($value) => is_array($value) ? implode(',', $value) : '',
        );
    }

    protected function disabledWithdrawalNetworks(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? array_map('intval', explode(',', $value)) : [],
            set: fn ($value) => is_array($value) ? implode(',', $value) : '',
        );
    }
}
