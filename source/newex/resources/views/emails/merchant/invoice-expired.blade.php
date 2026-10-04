@component('mail::message')
# {{ __('mail.merchant.invoice.expired.title') }}

{{ __('mail.merchant.invoice.expired.body') }}

**{{ __('mail.merchant.invoice_reference') }}:** {{ $invoice->reference }}

**{{ __('mail.merchant.amount') }}:** ${{ number_format($invoice->amount_usd, 2) }}

**{{ __('mail.merchant.expired_at') }}:** {{ $invoice->expires_at ? $invoice->expires_at->format('Y-m-d H:i:s') : 'N/A' }}

@if($invoice->customer_email)
**{{ __('mail.merchant.customer_email') }}:** {{ $invoice->customer_email }}
@endif

@component('mail::button', ['url' => $viewUrl])
{{ __('mail.merchant.invoice.expired.button') }}
@endcomponent

@endcomponent
