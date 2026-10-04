@component('mail::message')
# {{ __('mail.kyc.rejected.title') }}

{{  __('mail.kyc.rejected') }}

<strong>{{ $reason }}</strong>

{{ config('app.name') }} Team

This is an automated message, please do not reply.
@endcomponent
