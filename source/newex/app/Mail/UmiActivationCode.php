<?php
namespace App\Mail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
class UmiActivationCode extends Mailable {
    use Queueable,SerializesModels;
    public function __construct(public string $code, public string $purpose = 'activation') {
        if (!in_array($purpose, ['activation', 'binding'], true)) throw new \InvalidArgumentException('Invalid UMI code purpose');
    }
    public function build() {
        return $this->subject($this->purpose === 'binding' ? __('Deepro · UMI account binding code') : __('Deepro · UMI account activation code'))
            ->view('emails.umi-activation');
    }
}
