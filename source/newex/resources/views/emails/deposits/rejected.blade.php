@component('mail::message')
# {{ __('mail.deposit.rejected.title') }}

{{  __('mail.deposit.rejected', ['amount' => $amount, 'symbol' => $symbol]) }}

<strong>{{ $reason }}</strong>

{{ config('app.name') }} Team

This is an automated message, please do not reply.
@endcomponent
