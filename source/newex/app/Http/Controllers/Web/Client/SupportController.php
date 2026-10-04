<?php
namespace App\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Support\SupportFormRequest;
use App\Mail\Support\AdminSupportReceived;
use App\Models\Support\{SupportMessage, SupportTicketEntry};
use App\Models\User\User;
use App\Services\Support\SupportAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Mail, Redirect};
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Setting;

class SupportController extends Controller
{
    public function index() { return Inertia::render('Support/SupportForm', ['success' => session('success'),'channels'=>app(\App\Services\Content\SitePresentation::class)->get('support')]); }

    public function store(SupportFormRequest $request)
    {
        $created = false;
        $ticket = DB::transaction(function () use ($request, &$created) {
            // Serialize retries for this owner before testing the unique request key.
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $prior = SupportMessage::where('request_key', $request->input('request_key'))->first();
            if ($prior) { abort_unless((int)$prior->user_id === (int)$request->user()->id, 409); return $prior; }
            $created = true;
            return SupportMessage::create([
                'title' => $request->input('title'), 'body' => $request->input('body'),
                'file_id' => SupportAttachment::claim($request->input('file_id'), $request->user()->id),
                'request_key' => $request->input('request_key'), 'user_id' => $request->user()->id, 'status' => 'new',
            ]);
        });
        if ($created && ($email = Setting::get('notification.admin_email', false))) {
            // The saved ticket remains usable even when the optional email transport is unavailable.
            try {
                Mail::to($email)->queue(new AdminSupportReceived($request->user()->email, $ticket->title, $ticket->body,
                    $ticket->file ? $ticket->file->url : null, route('admin.support.tickets')));
            } catch (\Throwable $e) { report($e); }
        }
        return Redirect::route('support.tickets.show', $ticket->id)->with('success', __('Your ticket has been saved.'));
    }

    public function tickets(Request $request)
    {
        $data = $request->validate(['status' => 'nullable|in:new,replied,closed', 'search' => 'nullable|string|max:150']);
        $query = SupportMessage::where('user_id', $request->user()->id);
        if (!empty($data['status'])) $query->where('status', $data['status']);
        if ($search = trim($data['search'] ?? '')) $query->where(fn($q) => $q->where('ticket_id', 'like', '%'.$search.'%')->orWhere('title', 'like', '%'.$search.'%'));
        $tickets = $query->orderByDesc('updated_at')->orderByDesc('id')->paginate(15, ['id','ticket_id','title','status','created_at','updated_at'])->withQueryString();
        $tickets->through(fn($t) => $t->only(['id','ticket_id','title','status']) + ['created_at' => $t->created_at?->toIso8601String(), 'updated_at' => $t->updated_at?->toIso8601String()]);
        return Inertia::render('Support/Tickets', ['tickets' => $tickets, 'filters' => $data]);
    }

    public function show(Request $request, SupportMessage $ticket)
    {
        abort_unless((int)$ticket->user_id === (int)$request->user()->id, 404);
        $entries = $ticket->entries()->whereIn('kind', ['reply','customer_reply','customer_status'])->with('file')->get();
        $public = $entries->map(fn($e) => [
            'id' => $e->id, 'kind' => $e->kind, 'body' => $e->body, 'created_at' => $e->created_at->toIso8601String(),
            'file' => SupportAttachment::present($e->file),
            'status' => $e->kind === 'customer_status' ? ($e->changes['after']['status'] ?? null) : null,
        ])->all();
        if ($ticket->reply && !$entries->contains(fn($e) => $e->kind === 'reply' && $e->body === $ticket->reply)) {
            $public[] = ['id' => 'legacy', 'kind' => 'reply', 'body' => $ticket->reply, 'created_at' => $ticket->replied_at?->toIso8601String(), 'file' => null];
        }
        return Inertia::render('Support/Ticket', [
            'ticket' => $ticket->only(['id','ticket_id','title','body','status','revision','created_at','updated_at']) + [
                'file' => SupportAttachment::present($ticket->file), 'entries' => $public,
            ], 'success' => session('success'),
        ]);
    }

    public function update(Request $request, SupportMessage $ticket)
    {
        abort_unless((int)$ticket->user_id === (int)$request->user()->id, 404);
        $data = $request->validate([
            'action' => 'required|in:reply,close,reopen', 'request_key' => 'required|uuid', 'revision' => 'required|integer|min:0',
            'body' => 'nullable|required_if:action,reply|string|max:5000', 'file_id' => 'nullable|integer|exists:file_uploads,id',
        ]);
        DB::transaction(function () use ($request, $ticket, $data) {
            $ticket = SupportMessage::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $prior = SupportTicketEntry::where('request_key', $data['request_key'])->first();
            if ($prior) { abort_unless((int)$prior->support_message_id === (int)$ticket->id && (int)$prior->actor_id === (int)$request->user()->id, 409); return; }
            if ($ticket->revision !== $data['revision']) throw ValidationException::withMessages(['revision' => __('Ticket changed. Refresh before saving.')]);
            if ($data['action'] === 'reply' && $ticket->status === 'closed') throw ValidationException::withMessages(['action' => __('Reopen the ticket before replying.')]);
            if (!empty($data['file_id']) && $data['action'] !== 'reply') throw ValidationException::withMessages(['file_id' => __('Attachments require a reply.')]);
            $before = $ticket->status;
            $ticket->status = $data['action'] === 'close' ? 'closed' : 'new';
            $ticket->revision++;
            $ticket->save();
            SupportTicketEntry::create([
                'support_message_id' => $ticket->id, 'actor_id' => $request->user()->id, 'request_key' => $data['request_key'],
                'kind' => $data['action'] === 'reply' ? 'customer_reply' : 'customer_status', 'body' => $data['action'] === 'reply' ? $data['body'] : null,
                'file_id' => SupportAttachment::claim($data['file_id'] ?? null, $request->user()->id),
                'changes' => ['before' => ['status' => $before], 'after' => ['status' => $ticket->status]],
            ]);
        });
        return Redirect::route('support.tickets.show', $ticket->id)->with('success', __('Your ticket has been saved.'));
    }
}
