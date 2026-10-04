<?php

namespace App\Mail\KycDocuments;

use App\Repositories\Language\LanguageRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class KycRejected extends Mailable
{
    use Queueable, SerializesModels;

    public $reason;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($user, $reason)
    {
        $this->reason = $reason;

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
        return $this->markdown('emails.kyc-documents.rejected', [
            'reason' => $this->reason
        ]);
    }
}
