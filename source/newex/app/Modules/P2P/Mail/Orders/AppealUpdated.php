<?php

namespace App\Modules\P2P\Mail\Orders;

use App\Repositories\Language\LanguageRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AppealUpdated extends Mailable
{
    use Queueable, SerializesModels;

    public $data;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($user, $data)
    {
        $this->data = $data;

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
        return $this->markdown('emails.peer-trades.orders.appeal-updated', [
            'data' => $this->data,
        ])->subject(__('mail.peer.orders.buy.order.appeal-updated.title'));

    }
}
