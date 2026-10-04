<?php

namespace App\Mail\Withdrawals;

use App\Repositories\Language\LanguageRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WithdrawalConfirmed extends Mailable
{
    use Queueable, SerializesModels;

    public $amount;
    public $symbol;
    public $note;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($user, $amount, $symbol, $note)
    {
        $this->amount = $amount;
        $this->symbol = $symbol;
        $this->note = $note;

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
        return $this->markdown('emails.withdrawals.confirmed', [
            'amount' => $this->amount,
            'symbol' => $this->symbol,
            'note' => $this->note,
        ]);
    }
}
