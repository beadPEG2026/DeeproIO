<?php

namespace App\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\Market\Market as MarketResource;
use App\Http\Resources\Market\MarketCollection;
use App\Http\Resources\Order\OpenOrderCollection;
use App\Interfaces\Order\OrderRepositoryInterface;
use App\Models\Market\Market;
use App\Repositories\Currency\CurrencyRepository;
use App\Services\Market\MarketService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;

class TestController extends Controller
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

        return Inertia::render('Mobile/Main', [
            'orders' => $orders
        ]);
    }

}
