@component('mail::message')
# {{ __('mail.merchant.payout.approved.title') }}

{{ __('mail.merchant.payout.approved.body') }}

**{{ __('mail.merchant.payout_reference') }}:** {{ $payout->reference }}

**{{ __('mail.merchant.amount') }}:** ${{ number_format($payout->amount_usd, 2) }}

**{{ __('mail.merchant.fee') }}:** ${{ number_format($payout->fee_usd, 2) }}

**{{ __('mail.merchant.net_amount') }}:** ${{ number_format($payout->net_amount_usd, 2) }}

**{{ __('mail.merchant.payout_address') }}:** {{ $payout->payout_address }}

{{ __('mail.merchant.payout.approved.description') }}

@component('mail::button', ['url' => $viewUrl])
{{ __('mail.merchant.payout.approved.button') }}
@endcomponent

@endcomponent
