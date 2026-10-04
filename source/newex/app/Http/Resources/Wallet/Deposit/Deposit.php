<?php

namespace App\Http\Resources\Wallet\Deposit;

use App\Repositories\Deposit\DepositRepository;
use Illuminate\Http\Resources\Json\JsonResource;

class Deposit extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $status = $this->status;
        $raw = $this->decodeRawMeta($this->initial_raw ?: $this->raw);
        $recordType = $raw['record_type'] ?? null;
        $sourceId = $this->source_id;
        $isPlatformInternalTransfer = $sourceId === DepositRepository::PLATFORM_INTERNAL_TRANSFER_SOURCE
            || in_array($recordType, ['internal_deposit', 'internal_transfer'], true);
        $fromReferralCode = $raw['from_referral_code'] ?? $this->internal_id ?? null;
        $internalTransferAddress = $fromReferralCode
            ? '内部转账 - 来自UID:' . $fromReferralCode
            : '内部转账';

        if($status == DEPOSIT_PENDING_TRANSFER) {
            $status = DEPOSIT_PENDING;
        }

        return [
            'deposit_id' => $this->deposit_id,
            'internal_id' => $this->internal_id,
            'source_id' => $sourceId,
            'record_type' => $recordType,
            'source_label' => $isPlatformInternalTransfer ? '内部转账' : null,
            'network_display' => $isPlatformInternalTransfer ? __('Internal transfer') : \App\Services\Wallet\NetworkName::display((int)$this->network_id),
            'address_display' => $isPlatformInternalTransfer ? $internalTransferAddress : null,
            'from_user_id' => $raw['from_user_id'] ?? null,
            'from_referral_code' => $fromReferralCode,
            'note' => $raw['note'] ?? null,
            'status' => $status,
            'status_reason' => $status === DEPOSIT_IGNORED
                ? __((in_array($raw['pilot_reason'] ?? null, ['DEPOSIT_PILOT_LIMIT_EXCEEDED', 'DEPOSIT_OUTSIDE_PILOT_WINDOW'], true))
                    ? 'This deposit requires review under the channel limits. Contact support with the transaction hash.'
                    : 'This deposit was not credited under the applicable deposit rules. Contact support with the transaction hash for the recorded reason.')
                : null,
            'review_required' => in_array($raw['pilot_reason'] ?? null, ['DEPOSIT_PILOT_LIMIT_EXCEEDED', 'DEPOSIT_OUTSIDE_PILOT_WINDOW'], true),
            'txn' => $this->txn,
            'logo' => ($this->currency?->logo_path ?: '/images/currency-placeholder.svg'),
            'explorer' => $this->txn_link,
            'amount' => $this->amount,
            'network_id' => $this->network_id,
            'network_fee' => $this->network_fee,
            'fee' => $this->fee,
            'currency' => $this->currency->name,
            'symbol' => $this->currency->symbol,
            'address' => $isPlatformInternalTransfer ? $internalTransferAddress : $this->address,
            'raw_address' => $this->address,
            'payment_id' => $this->payment_id,
            'system_fee' => math_formatter($this->system_fee, 8),
            'confirms' => $this->confirms,
            'created_at' => $this->created_at->format('Y-m-d H:i:s P'),
            'created_at_iso' => $this->created_at->toIso8601String(),
        ];
    }

    protected function decodeRawMeta($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return (array) $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
