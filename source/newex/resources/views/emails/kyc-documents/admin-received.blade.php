@component('mail::message')
# {{ __('mail.kyc.admin.received.title') }}

{{  __('mail.kyc.admin.received', ['email' => $email]) }}

@component('mail::button', ['url' => $url])
    {{ __('mail.kyc.admin.received.button') }}
@endcomponent

@endcomponent
