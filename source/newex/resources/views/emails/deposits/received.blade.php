@component('mail::message')
# {{ __('mail.deposit.received.title') }}

{{  __('mail.deposit.received', ['amount' => $amount, 'symbol' => $symbol]) }}

{{ config('app.name') }} Team

This is an automated message, please do not reply.
@endcomponent
