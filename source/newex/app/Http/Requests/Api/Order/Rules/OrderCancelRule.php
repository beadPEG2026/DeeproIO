<?php

namespace App\Http\Requests\Api\Order\Rules;

use App\Models\Order\Order;
use App\Repositories\Market\MarketRepository;
use App\Repositories\Order\OrderRepository;
use App\Repositories\Wallet\WalletRepository;
use Illuminate\Contracts\Validation\Rule;
use Auth;

class OrderCancelRule implements Rule
{
    /**
     * @var OrderRepository
     */
    private $orderRepository, $error;

    public function __construct()
    {
        $this->orderRepository = new OrderRepository();
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  string $uuid
     * @return bool
     */
    public function passes($attribute, $uuid)
    {
        if(!$order = $this->orderRepository->findById($uuid)) {
            $this->error = 'Invalid order';
            return false;
        }

        // Security: Verify order belongs to authenticated user
        if($order->user_id !== Auth::id()) {
            $this->error = 'Invalid order';
            return false;
        }

        if(!market_is_cancel_orders_allowed($order->market)) {
            $this->error = 'Cancelling orders are not allowed';
            return false;
        }

        return true;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return __($this->error);
    }
}
