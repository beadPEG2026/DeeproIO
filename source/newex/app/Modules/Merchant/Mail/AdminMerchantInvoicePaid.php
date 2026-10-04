<?php

namespace App\Modules\Merchant\Mail;

use App\Modules\Merchant\Models\MerchantInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AdminMerchantInvoicePaid extends Mailable
{
    use Queueable, SerializesModels;

    public MerchantInvoice $invoice;
    public string $url;

    /**
     * Create a new message instance.
     */
    public function __construct(MerchantInvoice $invoice, string $url)
    {
        $this->invoice = $invoice;
        $this->url = $url;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->subject(__('mail.merchant.admin.invoice.paid.subject'))
            ->markdown('emails.merchant.admin-invoice-paid', [
                'invoice' => $this->invoice,
                'url' => $this->url,
            ]);
    }
}
