<?php

namespace App\Models\Withdrawal;

use App\Casts\CryptoCurrencyDecimalCast;
use App\Models\Withdrawal\Traits\Relations\WithdrawalRelation;
use App\Models\Withdrawal\Traits\Scopes\WithdrawalScope;
use Illuminate\Database\Eloquent\Model;

class Withdrawal extends Model
{
    use WithdrawalRelation, WithdrawalScope;

    protected $hidden = [
        'initial_raw'
    ];

    public $fillable = [
        'withdrawal_id',
        'txn',
        'source_id',
        'fund_origin',
        'currency_id',
        'type',
        'network_id',
        'amount',
        'fee',
        'address',
        'payment_id',
        'user_id',
        'confirms',
        'status',
        'extra_status',
        'rejected_reason',
        'initial_raw',
        'raw',
        'inusd',
        'internal_id'
    ];

    public $appends = [
        'txn_link'
    ];

    protected $casts = [
        'amount' => CryptoCurrencyDecimalCast::class,
        'fee' => CryptoCurrencyDecimalCast::class,
        'created_at' => "datetime:Y-m-d H:i:s",
        'updated_at' => "datetime:Y-m-d H:i:s",
    ];

    public function getTxnLinkAttribute()
    {
        if (!$this->txn) return null;

        if (in_array((int)$this->network_id,[NETWORK_XLAYER,NETWORK_XLAYER20],true)) return 'https://www.okx.com/web3/explorer/xlayer/tx/'.$this->txn;

        if($this->network_id == NETWORK_ERC || $this->network_id == NETWORK_ETH) {
            return 'https://etherscan.io/tx/' . $this->txn;
        } elseif($this->network_id == NETWORK_BEP || $this->network_id == NETWORK_BNB) {
            return 'https://bscscan.com/tx/' . $this->txn;
        } elseif($this->network_id == NETWORK_TRC || $this->network_id == NETWORK_TRX) {
            return 'https://tronscan.org/#/transaction/' . $this->txn;
        } elseif($this->network_id == NETWORK_SOL || $this->network_id == NETWORK_SOL_SPL) {
            return 'https://solscan.io/tx/' . $this->txn;
        } elseif($this->network_id == NETWORK_MATIC || $this->network_id == NETWORK_MATIC20) {
            return 'https://polygonscan.com/tx/' . $this->txn;
        } elseif($this->network_id == NETWORK_MATIC || $this->network_id == NETWORK_MATIC20) {
            return 'https://polygonscan.com/tx/' . $this->txn;
        }

        return str_replace('%txid%', $this->txn, $this->currency->txn_explorer);
    }
}
