@component('mail::message')
# Support Ticket Reply

Hello {{ $user->name ?? $user->email }},

We have reviewed your support request. Please find the details below.

## Your Question
**Title:** {{ $ticket->title }}
**Message:**
{{ $ticket->body }}

@if($ticket->file)
You attached a file with your request: [View Attachment]({{ $ticket->file->url }})
@endif

## Our Reply
{{ $replyBody ?? $ticket->reply }}

If you have further questions, feel free to reply to this email.

Thanks,
{{ config('app.name') }} Support Team
@endcomponent
