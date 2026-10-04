<?php

namespace App\Modules\P2P\Mail\MerchantApplications;

use App\Repositories\Language\LanguageRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MerchantKycApproved extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($user)
    {
        // Set default user language
        if($user->language) {
            $this->locale = $user->language->slug;
        } else {
            $language = (new LanguageRepository())->getByDefault();
            $this->locale = $language->slug;
        }
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->markdown('emails.peer-trades.merchant-applications.approved');
    }
}
