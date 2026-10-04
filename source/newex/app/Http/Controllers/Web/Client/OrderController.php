<?php

namespace App\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\Order\OpenOrderCollection;
use App\Http\Resources\Order\OrderCollection;
use App\Interfaces\Order\OrderRepositoryInterface;
use Inertia\Inertia;

class OrderController extends Controller
{
    /**
     * @var OrderRepository
     */
    private $orderRepository;

    /**
     * OrdersController constructor.
     * @param OrderRepositoryInterface $orderRepository
     */
    public function __construct(OrderRepositoryInterface $orderRepository)
    {
        $this->orderRepository = $orderRepository;
    }


    public function index()
    {
        $orders = new OpenOrderCollection($this->orderRepository->open());

        return Inertia::render('Order/Orders', [
            'orders' => $orders
        ]);
    }
}
