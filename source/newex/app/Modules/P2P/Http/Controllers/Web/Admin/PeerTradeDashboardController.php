<?php

namespace App\Modules\P2P\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Currency\Currency;
use App\Modules\P2P\Http\Resources\PeerAd;
use App\Modules\P2P\Http\Resources\UserPaymentMethodCollection;
use App\Modules\P2P\Models\PeerTrade\PeerOrder;
use App\Modules\P2P\Models\PeerTrade\PeerOrderAppeal;
use App\Modules\P2P\Repositories\PeerTrade\PeerAdRepository;
use App\Modules\P2P\Repositories\PeerTrade\PeerOrderRepository;
use App\Modules\P2P\Repositories\PeerTrade\PeerOrdersAppealsRepository;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Wallet\WalletRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as RequestFacade;
use Inertia\Inertia;
use Setting;

class PeerTradeDashboardController extends Controller
{


    public function index(Request $request)
    {
        $peerAdRepository = new PeerAdRepository();

        $ads = $peerAdRepository->getAdsReport();

        $baseCurrencies = Currency::type('coin')->active()->where('is_p2p', true)->pluck('symbol');
        $quoteCurrencies = Currency::type('fiat')->active()->where('is_p2p', true)->get('symbol');

        return Inertia::render('Admin/PeerTrades/PeerTradeDashboard', [
            'filters' => RequestFacade::all(['type','basePair','quotePair', 'payment_method','amount','region', 'sort']),
            'ads' => $ads,
            'baseCurrencies' => $baseCurrencies,
            'quoteCurrencies' => $quoteCurrencies,
        ]);
    }


    public function trades(Request $request)
    {
        $peerAdRepository = new PeerOrderRepository();

        $orders = $peerAdRepository->getOrdersReport();

        $baseCurrencies = Currency::type('coin')->active()->where('is_p2p', true)->pluck('symbol');
        $quoteCurrencies = Currency::type('fiat')->active()->where('is_p2p', true)->get('symbol');

        return Inertia::render('Admin/PeerTrades/PeerTradeTransactions', [
            'filters' => RequestFacade::all(['type','basePair','quotePair', 'payment_method','amount','region', 'sort']),
            'orders' => $orders,
            'baseCurrencies' => $baseCurrencies,
            'quoteCurrencies' => $quoteCurrencies,
        ]);
    }
}
