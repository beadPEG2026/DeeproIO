@component('mail::message')
# {{ __('mail.merchant.payout.rejected.title') }}

{{ __('mail.merchant.payout.rejected.body') }}

**{{ __('mail.merchant.payout_reference') }}:** {{ $payout->reference }}

**{{ __('mail.merchant.amount') }}:** ${{ number_format($payout->amount_usd, 2) }}

**{{ __('mail.merchant.payout_address') }}:** {{ $payout->payout_address }}

**{{ __('mail.merchant.payout.rejected.reason') }}:**

{{ $reason }}

{{ __('mail.merchant.payout.rejected.description') }}

@component('mail::button', ['url' => $viewUrl])
{{ __('mail.merchant.payout.rejected.button') }}
@endcomponent

@endcomponent
