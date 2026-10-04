<?php

namespace App\Modules\Merchant\Mail;

use App\Models\User\User;
use App\Modules\Merchant\Models\MerchantInvoice;
use App\Modules\Merchant\Models\MerchantInvoicePayment;
use App\Repositories\Language\LanguageRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MerchantPaymentReceived extends Mailable
{
    use Queueable, SerializesModels;

    public MerchantInvoice $invoice;
    public MerchantInvoicePayment $payment;
    public string $viewUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, MerchantInvoice $invoice, MerchantInvoicePayment $payment, string $viewUrl)
    {
        $this->invoice = $invoice;
        $this->payment = $payment;
        $this->viewUrl = $viewUrl;

        // Set default user language
        if ($user->language) {
            $this->locale = $user->language->slug;
        } else {
            $language = (new LanguageRepository())->getByDefault();
            $this->locale = $language->slug;
        }
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->subject(__('mail.merchant.payment.received.subject'))
            ->markdown('emails.merchant.payment-received', [
                'invoice' => $this->invoice,
                'payment' => $this->payment,
                'viewUrl' => $this->viewUrl,
            ]);
    }
}
