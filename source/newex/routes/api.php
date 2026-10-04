<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\v1\CurrencyController;
use App\Http\Controllers\Api\v1\MarketController;
use App\Http\Controllers\Api\v1\OrderController;
use App\Http\Controllers\Api\v1\WalletController;
use App\Http\Controllers\Api\v1\LaunchpadController;
use App\Http\Controllers\Api\v1\StakingController;
use App\Http\Controllers\Api\v1\TransactionController;
use App\Http\Controllers\Api\v1\Gateways\CoinpaymentsController;
use App\Http\Controllers\Api\v1\FuturesController;
use App\Http\Controllers\Api\v1\OptionController;
use App\Http\Controllers\Api\v1\Gateways\EthereumController;
use App\Http\Controllers\Api\v1\SettingController;
use App\Http\Controllers\Api\v1\Gateways\BscController;
use App\Http\Controllers\Api\v1\Gateways\BitcoinController;
use App\Http\Controllers\Api\v1\Cmc\MarketCmcController;
use App\Http\Controllers\Api\v1\Cmc\MarketCoingeckoController;
use App\Http\Controllers\Api\v1\Gateways\StripeController;
use App\Http\Controllers\Web\Client\ArticleController;
use App\Http\Controllers\Web\Client\MarketController as MarketClientController;
use App\Http\Controllers\Web\Client\WalletController as WalletClientController;
use App\Http\Controllers\Web\Client\LaunchpadController as LaunchpadClientController;
use App\Http\Controllers\Web\Client\StakingController as StakingClientController;
use App\Http\Controllers\Api\v1\Gateways\PerfectMoneyController;
use App\Http\Controllers\Api\v1\SumsubController;
use App\Http\Controllers\Api\v1\Gateways\FireblocksController;
use App\Http\Controllers\Api\v1\Gateways\PayeerController;
use App\Http\Controllers\Api\v1\Gateways\UnlimitController;
use App\Http\Controllers\Api\v1\EmailVerificationCodeController;
/*
 *
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Broadcast::routes(['middleware' => ['auth:sanctum']]);

Route::group(['prefix' => 'v1', 'middleware' => ['throttle:api']], function () {
    Route::post('/register/email/code/send', [EmailVerificationCodeController::class, 'send'])
    ->name('register.email.code.send');
    Route::get('/servertime', [SettingController::class, 'time'])->name('server-time');
    Route::post('guess/submit', [\App\Http\Controllers\Api\v1\GuessController::class, 'submit'])
        ->middleware(['auth:sanctum'])
        ->name('guess.api.submit');
    Route::post('auth/token', [SettingController::class, 'auth'])->name('auth.token');

    // Wallet Connect IPN
    Route::get('auth/walletconnect', [SettingController::class, 'walletconnect'])->name('auth.walletconnect');

    Route::get('check-auth', [SettingController::class, 'checkAuth'])->name('auth.checkAuth');
    Route::get('qr-code-token', [SettingController::class, 'getQrCodeToken'])->name('auth.qr-code-token');

    Route::get('language/file', [SettingController::class, 'languageFile'])->name('language.file');

    Route::get('countries', [SettingController::class, 'countries'])->name('countries');

    Route::get('articles/featured', [ArticleController::class, 'featured'])->name('articles.featured');

    Route::group(['middleware' => ['throttle:time']], function () {
        Route::get('/site-status', [SettingController::class, 'siteStatus'])->name('site-status');
        //Route::get('/server-time', [SettingController::class, 'time'])->name('server-time');
    });

    // Launchpads
    Route::get('launchpads', [LaunchpadController::class, 'index'])->name('launchpads.api.list');
    Route::get('launchpad', [LaunchpadController::class, 'show'])->name('launchpads.api.show');

    Route::get('stakings', [StakingController::class, 'index'])->name('stakings.api.list');
    Route::get('staking', [StakingController::class, 'show'])->name('stakings.api.show');


    Route::group(['middleware' => ['auth:sanctum', 'maintenance']], function () {

        // Unlimit checkout (on-ramp / buy)
        Route::post('/gateways/unlimit/checkout', [UnlimitController::class, 'checkout'])->name('unlimit.checkout');
        // Unlimit payout (off-ramp / sell) - legacy
        Route::post('/gateways/unlimit/payout', [UnlimitController::class, 'payout'])->name('unlimit.payout');
        // Unlimit off-ramp checkout (sell)
        Route::post('/gateways/unlimit/offramp/checkout', [UnlimitController::class, 'offrampCheckout'])->name('unlimit.offramp.checkout');
        Route::get('/gateways/unlimit/deposit-bank-card', [UnlimitController::class, 'depositBankCard']);
        Route::get('/stakings/my', [StakingController::class, 'my'])->name('stakings.api.my');

        Route::get('/auth/user', [SettingController::class, 'user'])->name('auth.user');

        Route::post('/orders/cancel', [OrderController::class, 'cancel'])->name('orders.api.cancel');
        Route::get('/orders/open', [OrderController::class, 'open'])->name('orders.api.open');
        Route::get('/orders/history', [OrderController::class, 'orderHistory'])->name('orders.api.history');
        Route::get('/orders/trades', [OrderController::class, 'tradeHistory'])->name('orders.api.trades');

        Route::post('/orders/futures/cancel', [FuturesController::class, 'cancel'])->name('orders.api.futures.cancel');
        Route::get('/orders/futures/open', [FuturesController::class, 'open'])->name('orders.api.futures.open');
        Route::get('/orders/futures/orders', [FuturesController::class, 'openOrders'])->name('orders.api.futures.orders');
        Route::get('/options/open', [OptionController::class, 'open'])->name('options.api.open');

        // Transactions routes
        Route::get('/transactions/deposits/crypto', [TransactionController::class, 'depositsCrypto'])->name('transactions.api.deposits.crypto');
        Route::get('/transactions/withdrawals/crypto', [TransactionController::class, 'withdrawalsCrypto'])->name('transactions.api.withdrawals.crypto');
        Route::get('/transactions/trades', [TransactionController::class, 'trades'])->name('transactions.api.trades');
        Route::get('/transactions/futures-trades', [TransactionController::class, 'futuresTrades'])->name('transactions.api.futures.trades');
        Route::get('/transactions/orders', [TransactionController::class, 'orders'])->name('transactions.api.orders');
        Route::get('/transactions/launchpads', [TransactionController::class, 'launchpads'])->name('transactions.api.launchpads');

        // Wallet routes
        Route::get('/wallets/getAddress', [WalletController::class, 'getAddress'])->name('wallets.api.getAddress');

        Route::get('/wallets/balance', [WalletClientController::class, 'getBalance'])->name('wallets.api.getBalance');
        Route::get('/wallets/address-book', [\App\Http\Controllers\Api\v1\WithdrawalAddressBookController::class, 'index'])->name('wallets.address-book.index');
        Route::post('/wallets/address-book', [\App\Http\Controllers\Api\v1\WithdrawalAddressBookController::class, 'store'])->middleware('throttle:20,1')->name('wallets.address-book.store');
        Route::delete('/wallets/address-book/{address}', [\App\Http\Controllers\Api\v1\WithdrawalAddressBookController::class, 'destroy'])->name('wallets.address-book.destroy');
        Route::get('/wallets/deposit/networks', [WalletController::class, 'loadNetworks'])->name('wallets.api.deposit.networks');

        Route::post('launchpad/submit', [LaunchpadClientController::class, 'submit'])->name('launchpads.api.submit');

        /*
         * Staking
         */
        Route::post('staking/submit', [StakingClientController::class, 'submit'])->name('staking.api.submit');
        Route::post('staking/redeem', [StakingClientController::class, 'redeem'])->name('staking.api.redeem');
        Route::post('staking/redemption/calculate', [StakingClientController::class, 'redemptionCalculate'])->name('staking.api.redemption.calculate');

        // Deposit routes
        Route::get('/wallets/deposits/{type}', [WalletController::class, 'getDeposits'])->name('wallets.api.deposits');
        Route::get('/wallets/fiat-deposits/{type?}', [WalletController::class, 'getFiatDeposits'])->name('wallets.api.deposits.fiat');

        Route::get('/wallets/deposit/crypto', [WalletController::class, 'getDepositInfo'])->name('wallets.api.deposit.crypto.info');
        Route::get('/wallets/withdraw/crypto', [WalletController::class, 'getWithdrawInfo'])->name('wallets.api.withdraw.crypto.info');

        // Withdraw routes
        Route::get('/wallets/withdrawals/{type}', [WalletController::class, 'getWithdrawals'])->name('wallets.api.withdrawals');
        Route::post('/wallets/withdraw', [WalletController::class, 'withdraw'])->middleware(\App\Http\Middleware\WalletRequestReceipt::class)->name('wallets.api.withdraw');
        Route::post('/wallets/transfer', [WalletController::class, 'transfer'])->middleware(\App\Http\Middleware\WalletRequestReceipt::class)->name('wallets.api.transfer');
        Route::get('/wallets/fiat-withdrawals', [WalletController::class, 'getFiatWithdrawals'])->name('wallets.api.withdrawals.fiat');

        Route::post('/wallets/payment/stripe/init', [WalletController::class, 'stripePayment'])->name('wallets.api.deposit.fiat.stripe.load');
        Route::post('/wallets/payment/stripe/validate', [WalletController::class, 'stripePaymentValidate'])->name('wallets.api.deposit.fiat.stripe.validate');

        Route::resource('wallets', WalletController::class)->only(['index']);

        Route::get('/currencies/rates-balance', [CurrencyController::class, 'rates'])->name('currencies.api.rates-balance');

        Route::get('/wallets/balance', [WalletController::class, 'getBalance'])->name('wallets.api.getBalance');

        // API For Mobile
        Route::get('/wallets/deposit/coin', [WalletController::class, 'getDepositInfo'])->name('wallets.api.deposit.coin');
    });

    Route::get('/networks', [WalletController::class, 'getNetworks'])->name('wallets.api.networks');

    Route::get('/markets/exchange_rate', [MarketClientController::class, 'exchangeRate'])->name('markets.api.exchange_rate');
    Route::get('/market/info', [MarketController::class, 'marketInfo'])->name('market.api.info');

    Route::get('/markets/swap/info', [MarketController::class, 'swap'])->name('markets.api.swap');
    Route::get('/markets/ticker', [MarketController::class, 'ticker'])->name('markets.api.ticker');
    Route::get('/markets/trades', [MarketController::class, 'trades'])->name('markets.api.trades');
    Route::get('/markets/candles/{query?}', [MarketController::class, 'candles'])->name('markets.api.candles');
    Route::get('/markets/orderbook', [MarketController::class, 'orderbook'])->name('markets.api.orderbook');
    Route::get('/markets/historical/trades', [MarketController::class, 'historicalTrades'])->name('markets.api.historical.trades');

    Route::get('/options/trades', [OptionController::class, 'trades'])->name('options.api.trades');


    Route::resource('currencies', CurrencyController::class)->only(['index']);

    Route::resource('markets', MarketController::class);
    Route::resource('orders', OrderController::class);
    Route::resource('futures', FuturesController::class)->only(['index','store']);
    Route::resource('options', OptionController::class)->only(['index','store']);

    // Coinmarketcap & CoinGecko Routes
    Route::group(['prefix' => 'spot'], function () {

        // CMC endpoints
        Route::get('/markets', [MarketCmcController::class, 'markets']);
        Route::get('/ticker', [MarketCmcController::class, 'ticker']);
        Route::get('/orderbook', [MarketCmcController::class, 'orderbook']);
        Route::get('/trades', [MarketCmcController::class, 'trades']);
        Route::get('/assets', [MarketCmcController::class, 'assets']);

        // CoinGecko endpoints
        Route::get('/cg/pairs', [MarketCoingeckoController::class, 'pairs']);
        Route::get('/cg/tickers', [MarketCoingeckoController::class, 'tickers']);
        Route::get('/cg/orderbook', [MarketCoingeckoController::class, 'orderbook']);
        Route::get('/cg/historical_trades', [MarketCoingeckoController::class, 'historicalTrades']);
    });

    // Perfect Money IPN
    Route::post('/perfect-money/ipn', [PerfectMoneyController::class, 'ipn'])->name('perfectmoney.ipn');

    // Payeer IPN
    Route::post('/payeer/checkout', [PayeerController::class, 'checkout'])->name('payeer.checkout');

    Route::post('/payeer/ipn', [PayeerController::class, 'ipn'])->name('payeer.ipn');
    Route::get('/payeer/ipn', [PayeerController::class, 'ipn'])->name('payeer.ipn2');

    // Ethereum
    Route::post('/gateways/ethereum', [EthereumController::class, 'ipn'])->name('ethereum.ipn');

    // BSC Network
    Route::post('/gateways/bnb', [BscController::class, 'ipn'])->name('bnb.ipn');

    // Bitcoin
    Route::get('/gateways/bitcoin/{symbol}', [BitcoinController::class, 'ipn'])->name('bitcoin.ipn');

    // Stripe
    Route::post('/gateways/stripe', [StripeController::class, 'ipn'])->name('stripe.ipn');

    // Sumsub IPN
    Route::post('sumsub/ipn', [SumsubController::class, 'ipn'])->name('sumsub.ipn');

    // Fireblocks
    Route::get('/gateways/fireblocks', [FireblocksController::class, 'ipn'])->name('fireblocks.ipn');
    Route::post('/gateways/fireblocks', [FireblocksController::class, 'ipn'])->name('fireblocks.ipn');

    // Unlimit On-Ramp (Buy)
    Route::get('/gateways/unlimit/currencies', [UnlimitController::class, 'getCurrencies'])->name('unlimit.currencies');
    Route::get('/gateways/unlimit/fiat-currencies', [UnlimitController::class, 'getFiatCurrencies'])->name('unlimit.fiatCurrencies');
    Route::get('/gateways/unlimit/quote/offramp', [UnlimitController::class, 'quoteOffRamp'])->name('unlimit.quote.offramp');
    Route::get('/gateways/unlimit/quote', [UnlimitController::class, 'quote'])->name('unlimit.quote');
    Route::get('/gateways/unlimit/config', [UnlimitController::class, 'config'])->name('unlimit.config');
    Route::post('/gateways/unlimit/ipn', [UnlimitController::class, 'ipn'])->name('unlimit.ipn');

    // Unlimit Off-Ramp (Sell)
    Route::get('/gateways/unlimit/offramp/currencies', [UnlimitController::class, 'getOfframpCurrencies'])->name('unlimit.offramp.currencies');
    Route::get('/gateways/unlimit/offramp/fiat-currencies', [UnlimitController::class, 'getOfframpFiatCurrencies'])->name('unlimit.offramp.fiatCurrencies');
    Route::get('/gateways/unlimit/offramp/payout-methods', [UnlimitController::class, 'getPayoutMethods'])->name('unlimit.offramp.payoutMethods');
    Route::get('/gateways/unlimit/offramp/quote', [UnlimitController::class, 'offrampQuote'])->name('unlimit.offramp.quote');
});

// Includes Modules
require app_path('Modules/P2P/Routes/api.php');
require app_path('Modules/Merchant/Routes/api.php');
