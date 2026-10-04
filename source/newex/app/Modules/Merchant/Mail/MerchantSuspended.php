<?php

namespace App\Modules\Merchant\Mail;

use App\Models\User\User;
use App\Modules\Merchant\Models\Merchant;
use App\Repositories\Language\LanguageRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MerchantSuspended extends Mailable
{
    use Queueable, SerializesModels;

    public Merchant $merchant;
    public string $reason;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, Merchant $merchant, string $reason)
    {
        $this->merchant = $merchant;
        $this->reason = $reason;

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
        return $this->subject(__('mail.merchant.suspended.subject'))
            ->markdown('emails.merchant.suspended', [
                'merchant' => $this->merchant,
                'reason' => $this->reason,
            ]);
    }
}
