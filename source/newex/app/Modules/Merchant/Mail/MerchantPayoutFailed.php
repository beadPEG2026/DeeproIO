<?php

namespace App\Modules\Merchant\Mail;

use App\Models\User\User;
use App\Modules\Merchant\Models\MerchantPayout;
use App\Repositories\Language\LanguageRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MerchantPayoutFailed extends Mailable
{
    use Queueable, SerializesModels;

    public MerchantPayout $payout;
    public string $error;
    public string $viewUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, MerchantPayout $payout, string $error, string $viewUrl)
    {
        $this->payout = $payout;
        $this->error = $error;
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
        return $this->subject(__('mail.merchant.payout.failed.subject'))
            ->markdown('emails.merchant.payout-failed', [
                'payout' => $this->payout,
                'error' => $this->error,
                'viewUrl' => $this->viewUrl,
            ]);
    }
}
