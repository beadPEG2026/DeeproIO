@component('mail::message')
# {{ __('mail.merchant.payment.received.title') }}

{{ __('mail.merchant.payment.received.body') }}

**{{ __('mail.merchant.invoice_reference') }}:** {{ $invoice->reference }}

**{{ __('mail.merchant.payment_amount') }}:** {{ $payment->amount_crypto }} {{ $invoice->currencyModel ? $invoice->currencyModel->symbol : '' }}

**{{ __('mail.merchant.usd_value') }}:** ${{ number_format($payment->amount_usd, 2) }}

**{{ __('mail.merchant.confirmations') }}:** {{ $payment->confirmations }}/{{ $payment->required_confirmations }}

**{{ __('mail.merchant.txn_hash') }}:** {{ $payment->txn_hash }}

@component('mail::button', ['url' => $viewUrl])
{{ __('mail.merchant.payment.received.button') }}
@endcomponent

@endcomponent
