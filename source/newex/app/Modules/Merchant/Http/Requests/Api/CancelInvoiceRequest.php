<?php

namespace App\Modules\Merchant\Http\Requests\Api;

use App\Modules\Merchant\Models\Merchant;
use Illuminate\Foundation\Http\FormRequest;

class CancelInvoiceRequest extends FormRequest
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
        return [
            'reason' => 'nullable|string|max:500',
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
