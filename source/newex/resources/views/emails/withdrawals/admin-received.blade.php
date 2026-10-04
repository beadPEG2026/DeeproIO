@component('mail::message')
# {{ __('mail.withdrawal.admin.received.title') }}

{{  __('mail.withdrawal.admin.received', ['amount' => $amount, 'symbol' => $symbol]) }}

@component('mail::button', ['url' => $url])
    {{ __('mail.withdrawal.admin.received.button') }}
@endcomponent

@endcomponent

