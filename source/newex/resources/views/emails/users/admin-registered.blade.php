@component('mail::message')
# {{ __('mail.user.admin.registered.title') }}

{{  __('mail.user.admin.registered', ['email' => $email]) }}

@component('mail::button', ['url' => $url])
    {{ __('mail.kyc.admin.registered.button') }}
@endcomponent

@endcomponent
