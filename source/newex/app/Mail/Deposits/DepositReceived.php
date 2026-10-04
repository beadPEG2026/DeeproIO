<?php

namespace App\Mail\Deposits;

use App\Repositories\Language\LanguageRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DepositReceived extends Mailable
{
    use Queueable, SerializesModels;

    public $amount;
    public $symbol;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($user, $amount, $symbol)
    {
        $this->amount = $amount;
        $this->symbol = $symbol;

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
        return $this->markdown('emails.deposits.received', [
            'amount' => $this->amount,
            'symbol' => $this->symbol,
        ]);
    }
}
