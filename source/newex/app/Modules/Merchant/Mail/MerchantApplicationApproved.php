<?php

namespace App\Modules\Merchant\Mail;

use App\Models\User\User;
use App\Modules\Merchant\Models\Merchant;
use App\Repositories\Language\LanguageRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MerchantApplicationApproved extends Mailable
{
    use Queueable, SerializesModels;

    public Merchant $merchant;
    public string $dashboardUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, Merchant $merchant, string $dashboardUrl)
    {
        $this->merchant = $merchant;
        $this->dashboardUrl = $dashboardUrl;

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
        return $this->subject(__('mail.merchant.application.approved.subject'))
            ->markdown('emails.merchant.application-approved', [
                'merchant' => $this->merchant,
                'dashboardUrl' => $this->dashboardUrl,
            ]);
    }
}
