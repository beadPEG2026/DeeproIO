<?php

namespace App\Modules\Merchant\Mail;

use App\Models\User\User;
use App\Modules\Merchant\Models\MerchantPayout;
use App\Repositories\Language\LanguageRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MerchantPayoutRejected extends Mailable
{
    use Queueable, SerializesModels;

    public MerchantPayout $payout;
    public string $reason;
    public string $viewUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, MerchantPayout $payout, string $reason, string $viewUrl)
    {
        $this->payout = $payout;
        $this->reason = $reason;
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
        return $this->subject(__('mail.merchant.payout.rejected.subject'))
            ->markdown('emails.merchant.payout-rejected', [
                'payout' => $this->payout,
                'reason' => $this->reason,
                'viewUrl' => $this->viewUrl,
            ]);
    }
}
