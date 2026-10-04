@component('mail::message')
# {{ __('mail.merchant.reactivated.title') }}

{{ __('mail.merchant.reactivated.body', ['business_name' => $merchant->business_name]) }}

{{ __('mail.merchant.reactivated.description') }}

@component('mail::button', ['url' => $dashboardUrl])
{{ __('mail.merchant.reactivated.button') }}
@endcomponent

@endcomponent
