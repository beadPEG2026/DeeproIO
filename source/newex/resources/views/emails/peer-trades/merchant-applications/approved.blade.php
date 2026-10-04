@component('mail::message')
# {{ __('mail.merchant.kyc.approved.title') }}

{{  __('mail.merchant.kyc.approved') }}

{{ config('app.name') }} {{ __('Team') }}

{{ __('This is an automated message, please do not reply.') }}
@endcomponent
