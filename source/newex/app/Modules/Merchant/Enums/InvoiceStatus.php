<?php

namespace App\Modules\Merchant\Enums;

enum InvoiceStatus: string
{
    case AWAITING_SELECTION = 'awaiting_selection';
    case AWAITING_PAYMENT = 'awaiting_payment';
    case DETECTING = 'detecting';
    case CONFIRMING = 'confirming';
    case PAID = 'paid';
    case OVERPAID = 'overpaid';
    case UNDERPAID = 'underpaid';
    case SETTLED = 'settled';
    case EXPIRED = 'expired';
    case CANCELLED = 'cancelled';
    case FAILED = 'failed';
    case REFUNDING = 'refunding';
    case REFUNDED = 'refunded';

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::AWAITING_SELECTION => 'Awaiting Currency Selection',
            self::AWAITING_PAYMENT => 'Awaiting Payment',
            self::DETECTING => 'Payment Detecting',
            self::CONFIRMING => 'Confirming',
            self::PAID => 'Paid',
            self::OVERPAID => 'Overpaid',
            self::UNDERPAID => 'Underpaid',
            self::SETTLED => 'Settled',
            self::EXPIRED => 'Expired',
            self::CANCELLED => 'Cancelled',
            self::FAILED => 'Failed',
            self::REFUNDING => 'Refunding',
            self::REFUNDED => 'Refunded',
        };
    }

    /**
     * Check if this is a final state
     */
    public function isFinal(): bool
    {
        return in_array($this, [
            self::PAID,
            self::OVERPAID,
            self::SETTLED,
            self::EXPIRED,
            self::CANCELLED,
            self::FAILED,
            self::REFUNDED,
        ]);
    }

    /**
     * Check if invoice can accept payments in this state
     */
    public function canAcceptPayment(): bool
    {
        return in_array($this, [
            self::AWAITING_PAYMENT,
            self::DETECTING,
            self::CONFIRMING,
            self::UNDERPAID,
        ]);
    }

    /**
     * Check if invoice can be cancelled in this state
     */
    public function canBeCancelled(): bool
    {
        return in_array($this, [
            self::AWAITING_SELECTION,
            self::AWAITING_PAYMENT,
        ]);
    }

    /**
     * Check if this status should trigger a webhook
     */
    public function shouldTriggerWebhook(): bool
    {
        return in_array($this, [
            self::AWAITING_PAYMENT,
            self::DETECTING,
            self::CONFIRMING,
            self::PAID,
            self::OVERPAID,
            self::UNDERPAID,
            self::SETTLED,
            self::EXPIRED,
            self::CANCELLED,
            self::FAILED,
        ]);
    }

    /**
     * Get the corresponding webhook event type
     */
    public function getWebhookEventType(): ?string
    {
        return match ($this) {
            self::AWAITING_PAYMENT => 'invoice.pending',
            self::DETECTING => 'invoice.payment_detecting',
            self::CONFIRMING => 'invoice.confirming',
            self::PAID => 'invoice.paid',
            self::OVERPAID => 'invoice.overpaid',
            self::UNDERPAID => 'invoice.underpaid',
            self::SETTLED => 'invoice.settled',
            self::EXPIRED => 'invoice.expired',
            self::CANCELLED => 'invoice.cancelled',
            self::FAILED => 'invoice.failed',
            default => null,
        };
    }

    /**
     * Get valid transitions from this state
     */
    public function validTransitions(): array
    {
        return match ($this) {
            self::AWAITING_SELECTION => [self::AWAITING_PAYMENT, self::EXPIRED, self::CANCELLED],
            self::AWAITING_PAYMENT => [self::DETECTING, self::EXPIRED, self::CANCELLED],
            self::DETECTING => [self::CONFIRMING, self::EXPIRED],
            self::CONFIRMING => [self::PAID, self::UNDERPAID, self::OVERPAID],
            self::PAID => [self::SETTLED, self::REFUNDING],
            self::OVERPAID => [self::SETTLED, self::REFUNDING],
            self::UNDERPAID => [self::DETECTING, self::CONFIRMING, self::PAID, self::EXPIRED],
            self::REFUNDING => [self::REFUNDED, self::FAILED],
            default => [],
        };
    }

    /**
     * Check if transition to new status is valid
     */
    public function canTransitionTo(InvoiceStatus $newStatus): bool
    {
        return in_array($newStatus, $this->validTransitions());
    }
}
