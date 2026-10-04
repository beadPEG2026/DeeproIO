<?php

namespace App\Modules\Merchant\Mail;

use App\Modules\Merchant\Models\Merchant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AdminMerchantApplicationReceived extends Mailable
{
    use Queueable, SerializesModels;

    public Merchant $merchant;
    public string $url;

    /**
     * Create a new message instance.
     */
    public function __construct(Merchant $merchant, string $url)
    {
        $this->merchant = $merchant;
        $this->url = $url;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->subject(__('mail.merchant.admin.application.received.subject'))
            ->markdown('emails.merchant.admin-application-received', [
                'merchant' => $this->merchant,
                'url' => $this->url,
            ]);
    }
}
