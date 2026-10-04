@component('mail::message')
# {{ __('mail.merchant.payout.completed.title') }}

{{ __('mail.merchant.payout.completed.body') }}

**{{ __('mail.merchant.payout_reference') }}:** {{ $payout->reference }}

**{{ __('mail.merchant.amount') }}:** ${{ number_format($payout->net_amount_usd, 2) }}

@if($payout->amount_crypto)
**{{ __('mail.merchant.crypto_amount') }}:** {{ $payout->amount_crypto }} {{ $payout->currency ? $payout->currency->symbol : '' }}
@endif

**{{ __('mail.merchant.payout_address') }}:** {{ $payout->payout_address }}

@if($payout->txn_hash)
**{{ __('mail.merchant.txn_hash') }}:** {{ $payout->txn_hash }}
@endif

**{{ __('mail.merchant.completed_at') }}:** {{ $payout->completed_at ? $payout->completed_at->format('Y-m-d H:i:s') : 'N/A' }}

@if($payout->explorer_url)
@component('mail::button', ['url' => $payout->explorer_url])
{{ __('mail.merchant.payout.completed.view_transaction') }}
@endcomponent
@endif

@component('mail::button', ['url' => $viewUrl, 'color' => 'secondary'])
{{ __('mail.merchant.payout.completed.button') }}
@endcomponent

@endcomponent
