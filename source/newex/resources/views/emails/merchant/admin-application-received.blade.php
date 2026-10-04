@component('mail::message')
# {{ __('mail.merchant.admin.application.received.title') }}

{{ __('mail.merchant.admin.application.received.body') }}

**{{ __('mail.merchant.business_name') }}:** {{ $merchant->business_name }}

**{{ __('mail.merchant.email') }}:** {{ $merchant->business_email }}

**{{ __('mail.merchant.website') }}:** {{ $merchant->business_website ?? 'N/A' }}

**{{ __('mail.merchant.submitted_at') }}:** {{ $merchant->created_at->format('Y-m-d H:i:s') }}

@component('mail::button', ['url' => $url])
{{ __('mail.merchant.admin.application.received.button') }}
@endcomponent

@endcomponent
