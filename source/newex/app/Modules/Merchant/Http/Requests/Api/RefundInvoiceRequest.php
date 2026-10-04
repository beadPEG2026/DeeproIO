<?php

namespace App\Modules\Merchant\Http\Requests\Api;

use App\Modules\Merchant\Models\Merchant;
use Illuminate\Foundation\Http\FormRequest;

class RefundInvoiceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $apiKey = $this->get('api_key');
        return $apiKey && $apiKey->hasPermission('refunds:write');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'amount' => 'nullable|numeric|min:0',
            'destination_address' => 'required|string|max:255',
            'destination_memo' => 'nullable|string|max:255',
            'reason' => 'nullable|string|max:500',
            'refund_type' => 'nullable|in:full,partial,overpayment',
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
            'amount.min' => 'Refund amount must be positive',
            'destination_address.max' => 'Destination address is too long',
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
