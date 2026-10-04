<?php
namespace App\Jobs\Support;
use App\Models\Support\{SupportMessage,SupportTicketEntry};
use App\Mail\Support\SupportReply;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue,SerializesModels};
use Illuminate\Support\Facades\Mail;
class DeliverSupportReply implements ShouldQueue {
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public $tries=3;
    public $backoff=60;
    public function __construct(public int $entryId) {}
    public function handle():void {
        $entry=SupportTicketEntry::findOrFail($this->entryId);
        if($entry->kind!=='reply'||$entry->mail_status==='smtp_accepted')return;
        $ticket=SupportMessage::with('user')->findOrFail($entry->support_message_id);
        if(!$ticket->user || !$ticket->user->email){$entry->update(['mail_status'=>'no_recipient']);return;}
        Mail::to($ticket->user->email)->send(new SupportReply($ticket->user,$ticket,$entry->body));
        // Transport acceptance is not proof of inbox delivery.
        $entry->update(['mail_status'=>'smtp_accepted','mailed_at'=>now()]);
    }
    public function failed(?\Throwable $error):void { SupportTicketEntry::whereKey($this->entryId)->where('mail_status','!=','smtp_accepted')->update(['mail_status'=>'failed']); }
}
