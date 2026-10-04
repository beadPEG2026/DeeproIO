@component('mail::message')
# {{ __('mail.merchant.payout.failed.title') }}

{{ __('mail.merchant.payout.failed.body') }}

**{{ __('mail.merchant.payout_reference') }}:** {{ $payout->reference }}

**{{ __('mail.merchant.amount') }}:** ${{ number_format($payout->net_amount_usd, 2) }}

**{{ __('mail.merchant.payout_address') }}:** {{ $payout->payout_address }}

**{{ __('mail.merchant.payout.failed.error') }}:**

{{ $error }}

{{ __('mail.merchant.payout.failed.description') }}

@component('mail::button', ['url' => $viewUrl])
{{ __('mail.merchant.payout.failed.button') }}
@endcomponent

@endcomponent
