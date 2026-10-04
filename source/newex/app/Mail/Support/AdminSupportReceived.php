<?php

namespace App\Mail\Support;

use App\Models\Support\SupportMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AdminSupportReceived extends Mailable
{
    use Queueable, SerializesModels;

    public string $userEmail;
    public string $title;
    public string $body;
    public ?string $attachmentUrl;
    public string $url;

    public function __construct(string $userEmail, string $title, string $body, ?string $attachmentUrl, string $url)
    {
        $this->userEmail = $userEmail;
        $this->title = $title;
        $this->body = $body;
        $this->attachmentUrl = $attachmentUrl;
        $this->url = $url;
    }

    public function build()
    {
        return $this->markdown('emails.support.admin-received', [
            'userEmail' => $this->userEmail,
            'title' => $this->title,
            'body' => $this->body,
            'attachmentUrl' => $this->attachmentUrl,
            'url' => $this->url,
        ]);
    }
}
