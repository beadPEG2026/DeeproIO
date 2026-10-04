@component('mail::message')
# {{ __('mail.merchant.admin.invoice.paid.title') }}

{{ __('mail.merchant.admin.invoice.paid.body') }}

**{{ __('mail.merchant.invoice_reference') }}:** {{ $invoice->reference }}

**{{ __('mail.merchant.merchant_name') }}:** {{ $invoice->merchant->business_name }}

**{{ __('mail.merchant.amount') }}:** ${{ number_format($invoice->amount_usd, 2) }}

**{{ __('mail.merchant.fee') }}:** ${{ number_format($invoice->fee_amount_usd, 2) }}

**{{ __('mail.merchant.paid_at') }}:** {{ $invoice->paid_at ? $invoice->paid_at->format('Y-m-d H:i:s') : 'N/A' }}

@component('mail::button', ['url' => $url])
{{ __('mail.merchant.admin.invoice.paid.button') }}
@endcomponent

@endcomponent
