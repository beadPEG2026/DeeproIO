<?php

namespace App\Mail\Deposits;

use App\Repositories\Language\LanguageRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AdminDepositReceived extends Mailable
{
    use Queueable, SerializesModels;

    public $amount;
    public $symbol;
    public $url;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($amount, $symbol, $url)
    {
        $this->amount = $amount;
        $this->symbol = $symbol;
        $this->url = $url;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->markdown('emails.deposits.admin-received', [
            'amount' => $this->amount,
            'symbol' => $this->symbol,
            'url' => $this->url
        ]);
    }
}
