@component('mail::message')
# New Support Ticket Received

You have received a new support ticket.

**From:** {{ $userEmail ?: 'N/A' }}

**Title:** {{ $title }}

**Message:**
{{ $body }}

@if(!empty($attachmentUrl))
Attachment: [View Attachment]({{ $attachmentUrl }})
@endif

@component('mail::button', ['url' => $url])
Open Support Tickets
@endcomponent

@endcomponent
