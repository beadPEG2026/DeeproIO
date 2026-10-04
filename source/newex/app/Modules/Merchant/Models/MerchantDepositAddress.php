<?php

namespace App\Modules\Merchant\Models;

use App\Modules\Merchant\Models\Traits\Relations\MerchantDepositAddressRelation;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class MerchantDepositAddress extends Model
{
    use HasFactory, HasUuids, MerchantDepositAddressRelation;

    protected $table = 'merchant_deposit_addresses';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'merchant_id',
        'invoice_id',
        'currency_id',
        'network_id',
        'address',
        'private_key',
        'memo',
        'derivation_path',
        'address_index',
        'status',
        'assigned_at',
        'first_payment_at',
        'last_payment_at',
        'released_at',
        'expires_at',
        'is_sweep_required',
        'swept_at',
        'sweep_txn_hash',
        'notes',
    ];

    /**
     * Hide private_key from serialization
     */
    protected $hidden = [
        'private_key',
    ];

    protected $casts = [
        'is_sweep_required' => 'boolean',
        'assigned_at' => 'datetime',
        'first_payment_at' => 'datetime',
        'last_payment_at' => 'datetime',
        'released_at' => 'datetime',
        'expires_at' => 'datetime',
        'swept_at' => 'datetime',
    ];

    /**
     * Encrypt/decrypt private key automatically
     */
    protected function privateKey(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? Crypt::decryptString($value) : null,
            set: fn ($value) => $value ? Crypt::encryptString($value) : null,
        );
    }

    /**
     * Status constants
     */
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_USED = 'used';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_COMPROMISED = 'compromised';

    /**
     * Check if address can be assigned
     */
    public function canBeAssigned(): bool
    {
        return $this->status === self::STATUS_AVAILABLE;
    }

    /**
     * Check if address is currently in use
     */
    public function isInUse(): bool
    {
        return $this->status === self::STATUS_ASSIGNED;
    }

    /**
     * Assign address to an invoice
     */
    public function assignToInvoice(string $invoiceId): void
    {
        $this->invoice_id = $invoiceId;
        $this->status = self::STATUS_ASSIGNED;
        $this->assigned_at = now();
        $this->save();
    }

    /**
     * Mark address as used (invoice completed)
     */
    public function markAsUsed(): void
    {
        $this->status = self::STATUS_USED;
        $this->released_at = now();
        $this->save();
    }

    /**
     * Mark address as expired
     */
    public function markAsExpired(): void
    {
        $this->status = self::STATUS_EXPIRED;
        $this->released_at = now();
        $this->save();
    }

    /**
     * Record payment received
     */
    public function recordPayment(): void
    {
        if ($this->first_payment_at === null) {
            $this->first_payment_at = now();
        }
        $this->last_payment_at = now();
        $this->save();
    }

    /**
     * Mark for sweep (funds need to be consolidated)
     */
    public function markForSweep(): void
    {
        $this->is_sweep_required = true;
        $this->save();
    }

    /**
     * Record sweep completion
     */
    public function recordSweep(string $txnHash): void
    {
        $this->is_sweep_required = false;
        $this->swept_at = $txnHash ? now() : null;
        $this->sweep_txn_hash = $txnHash ?? null;
        $this->save();
    }

    /**
     * Get full address with memo if applicable
     */
    public function getFullAddress(): string
    {
        if ($this->memo) {
            return $this->address . ' (Memo: ' . $this->memo . ')';
        }
        return $this->address;
    }
}
