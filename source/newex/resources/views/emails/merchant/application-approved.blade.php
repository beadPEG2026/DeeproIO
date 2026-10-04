@component('mail::message')
# {{ __('mail.merchant.application.approved.title') }}

{{ __('mail.merchant.application.approved.body', ['business_name' => $merchant->business_name]) }}

{{ __('mail.merchant.application.approved.description') }}

@component('mail::button', ['url' => $dashboardUrl])
{{ __('mail.merchant.application.approved.button') }}
@endcomponent

@endcomponent
