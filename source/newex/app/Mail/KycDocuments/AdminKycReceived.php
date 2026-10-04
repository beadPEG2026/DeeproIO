<?php

namespace App\Mail\KycDocuments;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AdminKycReceived extends Mailable
{
    use Queueable, SerializesModels;

    public $email;
    public $url;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($email, $url)
    {
        $this->email = $email;
        $this->url = $url;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->markdown('emails.kyc-documents.admin-received', [
            'email' => $this->email,
            'url' => $this->url
        ]);
    }
}
