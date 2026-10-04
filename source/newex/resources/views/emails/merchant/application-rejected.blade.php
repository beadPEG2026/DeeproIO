@component('mail::message')
# {{ __('mail.merchant.application.rejected.title') }}

{{ __('mail.merchant.application.rejected.body', ['business_name' => $merchant->business_name]) }}

**{{ __('mail.merchant.application.rejected.reason') }}:**

{{ $reason }}

{{ __('mail.merchant.application.rejected.description') }}

@component('mail::button', ['url' => $reapplyUrl])
{{ __('mail.merchant.application.rejected.button') }}
@endcomponent

@endcomponent
