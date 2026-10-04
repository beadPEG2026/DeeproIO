<?php
namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Support\{SupportMessage,SupportTicketEntry};
use App\Models\User\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class SupportTicketController extends Controller
{
    private function operators() { return User::role(['superadmin','admin','user_editor','perm_support_tickets'])->where('deleted',false)->where('deactivated',false); }

    public function index(Request $request)
    {
        $filters=$request->validate(['search'=>'nullable|string|max:150','status'=>'nullable|in:new,replied,closed','priority'=>'nullable|in:normal,high,urgent','assigned_to'=>'nullable|integer|min:1','overdue'=>'nullable|boolean']);
        $query=SupportMessage::with(['user','file','entries.file']);
        foreach(['status','priority','assigned_to'] as $key) if(!empty($filters[$key]))$query->where($key,$filters[$key]);
        if(!empty($filters['overdue']))$query->where('due_at','<',now())->where('status','!=','closed');
        if($search=trim($filters['search']??''))$query->where(function($q)use($search){
            $q->where('ticket_id','like','%'.$search.'%')->orWhere('title','like','%'.$search.'%')
              ->orWhereHas('user',fn($u)=>$u->where('email','like','%'.$search.'%'));
            if(ctype_digit($search))$q->orWhere('user_id',(int)$search);
        });
        return Inertia::render('Admin/Support/Tickets',[
            'tickets'=>$query->orderByDesc('created_at')->paginate(20)->withQueryString(),
            'filters'=>$filters,'operators'=>$this->operators()->orderBy('id')->get(['id','name']),
        ]);
    }

    public function reply(Request $request, SupportMessage $ticket)
    {
        $data=$request->validate([
            'reply'=>'nullable|string|max:5000','request_key'=>'required|uuid','revision'=>'required|integer|min:0',
            'status'=>'required|in:new,replied,closed','priority'=>'required|in:normal,high,urgent',
            'assigned_to'=>'nullable|integer|min:1','due_at'=>'nullable|date','internal_note'=>'sometimes|boolean',
        ]);
        if(!empty($data['assigned_to'])&&!$this->operators()->whereKey($data['assigned_to'])->exists())throw ValidationException::withMessages(['assigned_to'=>__('Invalid support operator.')]);
        DB::transaction(function()use($data,$request,$ticket){
            $ticket=SupportMessage::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $prior=SupportTicketEntry::where('request_key',$data['request_key'])->first();
            if($prior){abort_unless($prior->support_message_id===$ticket->id&&$prior->actor_id===$request->user()->id,409);return;}
            if($ticket->revision!==$data['revision'])throw ValidationException::withMessages(['revision'=>__('Ticket changed. Refresh before saving.')]);
            // Preserve the old single reply on first workflow edit, including after a code rollback.
            $last=SupportTicketEntry::where('support_message_id',$ticket->id)->where('kind','reply')->latest('id')->first();
            if($ticket->reply && (!$last || $last->body!==$ticket->reply))SupportTicketEntry::create([
                'support_message_id'=>$ticket->id,'actor_id'=>null,'request_key'=>(string)\Illuminate\Support\Str::uuid(),
                'kind'=>'reply','body'=>$ticket->reply,'mail_status'=>'unknown','created_at'=>$ticket->replied_at??now(),
            ]);
            $before=$ticket->only(['status','priority','assigned_to','due_at']);
            foreach(['status','priority','assigned_to','due_at'] as $key)$ticket->$key=$data[$key]??null;
            $body=trim($data['reply']??'');
            $send=$body!=='' && empty($data['internal_note']);
            if($send){$ticket->reply=$body;$ticket->replied_at=now();if($ticket->status==='new')$ticket->status='replied';}
            $ticket->revision++;$ticket->save();
            $entry=SupportTicketEntry::create(['support_message_id'=>$ticket->id,'actor_id'=>$request->user()->id,
                'request_key'=>$data['request_key'],'kind'=>$send?'reply':($body!==''?'note':'workflow'),'body'=>$body?:null,
                'changes'=>['before'=>$before,'after'=>$ticket->only(['status','priority','assigned_to','due_at'])],
                'mail_status'=>$send?'queued':'not_requested']);
            if($send)\App\Jobs\Support\DeliverSupportReply::dispatch($entry->id)->afterCommit();
        });
        return back();
    }
}
