@component('mail::message')
# {{ __('mail.withdrawal.confirmed.title') }}

{{  __('mail.withdrawal.confirmed', ['amount' => $amount, 'symbol' => $symbol]) }}

<strong>{{ $note }}</strong>

{{ config('app.name') }} Team

This is an automated message, please do not reply.
@endcomponent
