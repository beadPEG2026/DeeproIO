<?php

namespace App\Modules\Merchant\Mail;

use App\Models\User\User;
use App\Modules\Merchant\Models\MerchantInvoice;
use App\Repositories\Language\LanguageRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MerchantInvoiceCreated extends Mailable
{
    use Queueable, SerializesModels;

    public MerchantInvoice $invoice;
    public string $viewUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, MerchantInvoice $invoice, string $viewUrl)
    {
        $this->invoice = $invoice;
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
        return $this->subject(__('mail.merchant.invoice.created.subject'))
            ->markdown('emails.merchant.invoice-created', [
                'invoice' => $this->invoice,
                'viewUrl' => $this->viewUrl,
            ]);
    }
}
