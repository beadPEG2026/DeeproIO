<?php

namespace App\Http\Requests\Api\Order;

use App\Http\Requests\Api\Order\Rules\OrderCancelRule;
use App\Models\Order\Order;
use App\Models\Wallet\Wallet;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Http\FormRequest;
use Auth;

class OrderCancelRequest extends FormRequest
{
    public $walletService;

    public function __construct(array $query = [], array $request = [], array $attributes = [], array $cookies = [], array $files = [], array $server = [], $content = null)
    {
        parent::__construct($query, $request, $attributes, $cookies, $files, $server, $content);
        $this->walletService = new WalletService();
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'uuid' => ['bail', 'required', 'max:100', new OrderCancelRule()],
        ];
    }
}
