@component('mail::message')
# {{ __('mail.merchant.suspended.title') }}

{{ __('mail.merchant.suspended.body', ['business_name' => $merchant->business_name]) }}

**{{ __('mail.merchant.suspended.reason') }}:**

{{ $reason }}

{{ __('mail.merchant.suspended.description') }}

@endcomponent
