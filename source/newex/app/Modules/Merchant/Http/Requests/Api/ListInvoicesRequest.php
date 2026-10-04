<?php

namespace App\Modules\Merchant\Http\Requests\Api;

use App\Modules\Merchant\Enums\InvoiceStatus;
use App\Modules\Merchant\Models\Merchant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListInvoicesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $apiKey = $this->get('api_key');
        return $apiKey && $apiKey->hasPermission('invoices:read');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        $validStatuses = array_column(InvoiceStatus::cases(), 'value');

        return [
            'status' => 'nullable|string',
            'external_id' => 'nullable|string|max:255',
            'customer_email' => 'nullable|email|max:255',
            'currency' => 'nullable|string|max:20',
            'created_from' => 'nullable|date',
            'created_to' => 'nullable|date|after_or_equal:created_from',
            'amount_min' => 'nullable|numeric|min:0',
            'amount_max' => 'nullable|numeric|min:0|gte:amount_min',
            'environment' => 'nullable|in:live,test',
            'sort' => 'nullable|in:created_at,amount_usd,status,paid_at',
            'order' => 'nullable|in:asc,desc',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
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
}
