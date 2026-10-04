<?php

namespace App\Mail\Withdrawals;

use App\Repositories\Language\LanguageRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WithdrawalRejected extends Mailable
{
    use Queueable, SerializesModels;

    public $amount;
    public $symbol;
    public $reason;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($user, $amount, $symbol, $reason)
    {
        $this->amount = $amount;
        $this->symbol = $symbol;
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
        return $this->markdown('emails.withdrawals.rejected', [
            'amount' => $this->amount,
            'symbol' => $this->symbol,
            'reason' => $this->reason
        ]);
    }
}
