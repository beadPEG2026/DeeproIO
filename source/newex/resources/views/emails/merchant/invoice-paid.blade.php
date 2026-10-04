@component('mail::message')
# {{ __('mail.merchant.invoice.paid.title') }}

{{ __('mail.merchant.invoice.paid.body') }}

**{{ __('mail.merchant.invoice_reference') }}:** {{ $invoice->reference }}

**{{ __('mail.merchant.amount') }}:** ${{ number_format($invoice->amount_usd, 2) }}

**{{ __('mail.merchant.fee') }}:** ${{ number_format($invoice->fee_amount_usd, 2) }}

**{{ __('mail.merchant.net_amount') }}:** ${{ number_format($invoice->amount_usd - $invoice->fee_amount_usd, 2) }}

**{{ __('mail.merchant.paid_at') }}:** {{ $invoice->paid_at ? $invoice->paid_at->format('Y-m-d H:i:s') : 'N/A' }}

@if($invoice->currencyModel)
**{{ __('mail.merchant.paid_in') }}:** {{ $invoice->amount_crypto }} {{ $invoice->currencyModel->symbol }}
@endif

@component('mail::button', ['url' => $viewUrl])
{{ __('mail.merchant.invoice.paid.button') }}
@endcomponent

@endcomponent
