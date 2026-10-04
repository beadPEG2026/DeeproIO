<?php

namespace App\Modules\Merchant\Http\Requests\Api;

use App\Modules\Merchant\Models\Merchant;
use App\Modules\Merchant\Models\MerchantApiKey;
use Illuminate\Foundation\Http\FormRequest;

class CreateInvoiceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $apiKey = $this->get('api_key');
        return $apiKey && $apiKey->hasPermission('invoices:write');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        $merchant = $this->getMerchant();
        $minAmount = max(
            $merchant?->min_invoice_amount_usd ?? 1,
            config('merchant_acquiring.invoice.min_amount_usd', 1)
        );
        $maxAmount = $merchant?->single_invoice_limit_usd ?? 100000;

        return [
            'amount' => [
                'required',
                'numeric',
                "min:{$minAmount}",
                "max:{$maxAmount}",
            ],
            'currency' => 'string|in:USD|max:3',
            'external_id' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'customer_email' => 'nullable|email|max:255',
            'customer_name' => 'nullable|string|max:255',
            'customer_metadata' => 'nullable|array',
            'customer_metadata.*' => 'string|max:1000',
            'metadata' => 'nullable|array',
            'metadata.*' => 'string|max:1000',
            'line_items' => 'nullable|array|max:50',
            'line_items.*.name' => 'required_with:line_items|string|max:255',
            'line_items.*.quantity' => 'required_with:line_items|integer|min:1',
            'line_items.*.price' => 'required_with:line_items|numeric|min:0',
            'line_items.*.description' => 'nullable|string|max:500',
            'redirect_url' => 'nullable|url|max:2000',
            'cancel_url' => 'nullable|url|max:2000',
            'webhook_url' => 'nullable|url|max:2000',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'Invoice amount is required',
            'amount.numeric' => 'Amount must be a valid number',
            'amount.min' => 'Amount is below the minimum allowed',
            'amount.max' => 'Amount exceeds the maximum allowed for single invoice',
            'customer_email.email' => 'Invalid email format',
            'redirect_url.url' => 'Redirect URL must be a valid URL',
            'cancel_url.url' => 'Cancel URL must be a valid URL',
            'webhook_url.url' => 'Webhook URL must be a valid URL',
        ];
    }

    /**
     * Get the merchant from the request
     *
     * @return Merchant|null
     */
    public function getMerchant(): ?Merchant
    {
        return $this->get('merchant');
    }

    /**
     * Get the API key from the request
     *
     * @return MerchantApiKey|null
     */
    public function getApiKey(): ?MerchantApiKey
    {
        return $this->get('api_key');
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Ensure amount is numeric
        if ($this->has('amount')) {
            $this->merge([
                'amount' => (float) $this->amount,
            ]);
        }

        // Default currency to USD
        if (!$this->has('currency')) {
            $this->merge(['currency' => 'USD']);
        }
    }
}
