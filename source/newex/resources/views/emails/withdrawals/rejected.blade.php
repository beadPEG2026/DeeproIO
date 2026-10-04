@component('mail::message')
# {{ __('mail.withdrawal.rejected.title') }}

{{  __('mail.withdrawal.rejected', ['amount' => $amount, 'symbol' => $symbol]) }}

<strong>{{ $reason }}</strong>

{{ config('app.name') }} Team

This is an automated message, please do not reply.
@endcomponent
