<?php

namespace App\Modules\Merchant\Enums;

enum WebhookEventType: string
{
    case INVOICE_CREATED = 'invoice.created';
    case INVOICE_PENDING = 'invoice.pending';
    case INVOICE_PAYMENT_DETECTING = 'invoice.payment_detecting';
    case INVOICE_CONFIRMING = 'invoice.confirming';
    case INVOICE_PAID = 'invoice.paid';
    case INVOICE_UNDERPAID = 'invoice.underpaid';
    case INVOICE_OVERPAID = 'invoice.overpaid';
    case INVOICE_SETTLED = 'invoice.settled';
    case INVOICE_EXPIRED = 'invoice.expired';
    case INVOICE_CANCELLED = 'invoice.cancelled';
    case INVOICE_FAILED = 'invoice.failed';
    case INVOICE_LATE_PAYMENT = 'invoice.late_payment';
    case REFUND_INITIATED = 'refund.initiated';
    case REFUND_COMPLETED = 'refund.completed';

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::INVOICE_CREATED => 'Invoice Created',
            self::INVOICE_PENDING => 'Invoice Pending Payment',
            self::INVOICE_PAYMENT_DETECTING => 'Payment Detecting',
            self::INVOICE_CONFIRMING => 'Payment Confirming',
            self::INVOICE_PAID => 'Invoice Paid',
            self::INVOICE_UNDERPAID => 'Invoice Underpaid',
            self::INVOICE_OVERPAID => 'Invoice Overpaid',
            self::INVOICE_SETTLED => 'Invoice Settled',
            self::INVOICE_EXPIRED => 'Invoice Expired',
            self::INVOICE_CANCELLED => 'Invoice Cancelled',
            self::INVOICE_FAILED => 'Invoice Failed',
            self::INVOICE_LATE_PAYMENT => 'Late Payment Received',
            self::REFUND_INITIATED => 'Refund Initiated',
            self::REFUND_COMPLETED => 'Refund Completed',
        };
    }

    /**
     * Get description for this event type
     */
    public function description(): string
    {
        return match ($this) {
            self::INVOICE_CREATED => 'Sent when a new invoice is created',
            self::INVOICE_PENDING => 'Sent when invoice is pending payment (currency selected)',
            self::INVOICE_PAYMENT_DETECTING => 'Sent when a payment is detected in the mempool',
            self::INVOICE_CONFIRMING => 'Sent when payment is confirming on the blockchain',
            self::INVOICE_PAID => 'Sent when invoice is fully paid',
            self::INVOICE_UNDERPAID => 'Sent when invoice receives partial payment',
            self::INVOICE_OVERPAID => 'Sent when invoice receives excess payment',
            self::INVOICE_SETTLED => 'Sent when payment is settled and funds transferred',
            self::INVOICE_EXPIRED => 'Sent when invoice expires without payment',
            self::INVOICE_CANCELLED => 'Sent when invoice is cancelled',
            self::INVOICE_FAILED => 'Sent when invoice processing fails',
            self::INVOICE_LATE_PAYMENT => 'Sent when payment received after expiry',
            self::REFUND_INITIATED => 'Sent when a refund is initiated',
            self::REFUND_COMPLETED => 'Sent when a refund is completed',
        };
    }

    /**
     * Get priority for this event type
     */
    public function priority(): WebhookPriority
    {
        return match ($this) {
            self::INVOICE_PAID, self::INVOICE_SETTLED => WebhookPriority::CRITICAL,
            self::INVOICE_UNDERPAID, self::INVOICE_OVERPAID,
            self::INVOICE_PAYMENT_DETECTING, self::REFUND_INITIATED,
            self::REFUND_COMPLETED, self::INVOICE_LATE_PAYMENT => WebhookPriority::HIGH,
            self::INVOICE_EXPIRED, self::INVOICE_CANCELLED,
            self::INVOICE_FAILED => WebhookPriority::NORMAL,
            default => WebhookPriority::NORMAL,
        };
    }

    /**
     * Check if this is a critical event
     */
    public function isCritical(): bool
    {
        return $this->priority() === WebhookPriority::CRITICAL;
    }

    /**
     * Get all invoice-related events
     */
    public static function invoiceEvents(): array
    {
        return [
            self::INVOICE_CREATED,
            self::INVOICE_PENDING,
            self::INVOICE_PAYMENT_DETECTING,
            self::INVOICE_CONFIRMING,
            self::INVOICE_PAID,
            self::INVOICE_UNDERPAID,
            self::INVOICE_OVERPAID,
            self::INVOICE_SETTLED,
            self::INVOICE_EXPIRED,
            self::INVOICE_CANCELLED,
            self::INVOICE_FAILED,
            self::INVOICE_LATE_PAYMENT,
        ];
    }

    /**
     * Get all refund-related events
     */
    public static function refundEvents(): array
    {
        return [
            self::REFUND_INITIATED,
            self::REFUND_COMPLETED,
        ];
    }
}
