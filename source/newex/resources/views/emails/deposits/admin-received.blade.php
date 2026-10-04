@component('mail::message')
# {{ __('mail.deposit.admin.received.title') }}

{{  __('mail.deposit.admin.received', ['amount' => $amount, 'symbol' => $symbol]) }}

@component('mail::button', ['url' => $url])
    {{ __('mail.deposit.admin.received.button') }}
@endcomponent

@endcomponent

