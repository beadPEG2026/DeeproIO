<?php

namespace App\Mail\Support;

use App\Models\Support\SupportMessage;
use App\Models\User\User;
use App\Repositories\Language\LanguageRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SupportReply extends Mailable
{
    use Queueable, SerializesModels;

    public User $user;
    public SupportMessage $ticket;
    public ?string $replyBody = null;

    public function __construct(User $user, SupportMessage $ticket, ?string $replyBody = null)
    {
        $this->user = $user;
        $this->ticket = $ticket;
        $this->replyBody = $replyBody ?? $ticket->reply;

        if($user->language) {
            $this->locale = $user->language->slug;
        } else {
            $language = (new LanguageRepository())->getByDefault();
            $this->locale = $language->slug;
        }
    }

    public function build()
    {
        $subject = config('app.name') . ' - Support Ticket #' . $this->ticket->ticket_id;

        return $this->subject($subject)
            ->markdown('emails.support.reply', [
                'user' => $this->user,
                'ticket' => $this->ticket,
                'replyBody' => $this->replyBody ?? $this->ticket->reply,
            ]);
    }
}
