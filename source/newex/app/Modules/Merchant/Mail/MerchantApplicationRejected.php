<?php

namespace App\Modules\Merchant\Mail;

use App\Models\User\User;
use App\Modules\Merchant\Models\Merchant;
use App\Repositories\Language\LanguageRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MerchantApplicationRejected extends Mailable
{
    use Queueable, SerializesModels;

    public Merchant $merchant;
    public string $reason;
    public string $reapplyUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, Merchant $merchant, string $reason, string $reapplyUrl)
    {
        $this->merchant = $merchant;
        $this->reason = $reason;
        $this->reapplyUrl = $reapplyUrl;

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
        return $this->subject(__('mail.merchant.application.rejected.subject'))
            ->markdown('emails.merchant.application-rejected', [
                'merchant' => $this->merchant,
                'reason' => $this->reason,
                'reapplyUrl' => $this->reapplyUrl,
            ]);
    }
}
