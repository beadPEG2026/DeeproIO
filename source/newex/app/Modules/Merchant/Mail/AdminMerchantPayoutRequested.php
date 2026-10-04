<?php

namespace App\Modules\Merchant\Mail;

use App\Modules\Merchant\Models\MerchantPayout;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AdminMerchantPayoutRequested extends Mailable
{
    use Queueable, SerializesModels;

    public MerchantPayout $payout;
    public string $url;

    /**
     * Create a new message instance.
     */
    public function __construct(MerchantPayout $payout, string $url)
    {
        $this->payout = $payout;
        $this->url = $url;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->subject(__('mail.merchant.admin.payout.requested.subject'))
            ->markdown('emails.merchant.admin-payout-requested', [
                'payout' => $this->payout,
                'url' => $this->url,
            ]);
    }
}
