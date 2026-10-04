@component('mail::message')
# {{ __('mail.merchant.kyc.rejected.title') }}

{{  __('mail.merchant.kyc.rejected') }}

<strong>{{ $reason }}</strong>

{{ config('app.name') }} {{ __('Team') }}

{{ __('This is an automated message, please do not reply.') }}
@endcomponent
