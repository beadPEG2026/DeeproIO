<?php

namespace App\Modules\Merchant\Mail;

use App\Models\User\User;
use App\Modules\Merchant\Models\MerchantPayout;
use App\Repositories\Language\LanguageRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MerchantPayoutApproved extends Mailable
{
    use Queueable, SerializesModels;

    public MerchantPayout $payout;
    public string $viewUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, MerchantPayout $payout, string $viewUrl)
    {
        $this->payout = $payout;
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
        return $this->subject(__('mail.merchant.payout.approved.subject'))
            ->markdown('emails.merchant.payout-approved', [
                'payout' => $this->payout,
                'viewUrl' => $this->viewUrl,
            ]);
    }
}
