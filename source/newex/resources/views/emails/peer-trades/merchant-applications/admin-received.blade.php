@component('mail::message')
# {{ __('mail.merchant.kyc.admin.received.title') }}

{{  __('mail.merchant.kyc.admin.received', ['email' => $email]) }}

@component('mail::button', ['url' => $url])
    {{ __('mail.kyc.admin.received.button') }}
@endcomponent

@endcomponent
