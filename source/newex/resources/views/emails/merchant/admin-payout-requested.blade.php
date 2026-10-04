@component('mail::message')
# {{ __('mail.merchant.admin.payout.requested.title') }}

{{ __('mail.merchant.admin.payout.requested.body') }}

**{{ __('mail.merchant.payout_reference') }}:** {{ $payout->reference }}

**{{ __('mail.merchant.merchant_name') }}:** {{ $payout->merchant->business_name }}

**{{ __('mail.merchant.amount') }}:** ${{ number_format($payout->amount_usd, 2) }}

**{{ __('mail.merchant.net_amount') }}:** ${{ number_format($payout->net_amount_usd, 2) }}

**{{ __('mail.merchant.payout_address') }}:** {{ $payout->payout_address }}

**{{ __('mail.merchant.requested_at') }}:** {{ $payout->requested_at->format('Y-m-d H:i:s') }}

@component('mail::button', ['url' => $url])
{{ __('mail.merchant.admin.payout.requested.button') }}
@endcomponent

@endcomponent
