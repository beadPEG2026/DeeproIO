<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\Web\Client as Client;
use App\Http\Controllers\Web\Admin as Admin;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

require_once __DIR__ . '/jetstream.php';

// Includes Modules
require_once app_path('Modules/P2P/Routes/web.php');
require_once app_path('Modules/Merchant/Routes/web.php');

Route::group(['middleware' => ['read.only']], function () {

    Route::get('license', [Client\SystemMonitorController::class, 'index'])->name('system-monitor.ping');
    Route::post('license', [Client\SystemMonitorController::class, 'register'])->name('system-monitor.ping.register');

    /*
    * Maintenance Static Pages
    */
    Route::get('maintenance', [Client\PageController::class, 'maintenance'])->name('page.maintenance');

    Route::group(['middleware' => ['language.detect', 'maintenance', 'ping']], function () {

        Route::get('/', [Client\HomeController::class, 'index'])->name('home');
        Route::get('download/{platform?}', [Client\DownloadController::class, 'show'])->where('platform', 'android|ios')->name('download');

        Route::get('stocks', [Client\ExploreController::class, 'stocks'])->name('stocks');
        Route::get('markets/data/overview', [Client\MarketDiscoveryController::class, 'overview'])->middleware('throttle:30,1');
        Route::get('markets/data/catalog', [Client\MarketDiscoveryController::class, 'catalog'])->middleware('throttle:30,1');
        Route::get('markets/data/news', [Client\MarketDiscoveryController::class, 'news'])->middleware('throttle:30,1');
        Route::get('markets/data/crypto-news', [Client\CryptoNewsController::class, 'index'])->middleware('throttle:30,1')->name('markets.crypto-news');
        Route::get('markets/data/sparklines', [Client\MarketDiscoveryController::class, 'sparklines'])->middleware('throttle:30,1');
        Route::get('stocks/data/fx', [Client\ExploreController::class, 'stockFx'])->middleware('throttle:30,1')->name('stocks.fx');
        Route::get('stocks/data/quotes', [Client\ExploreController::class, 'stockQuotes'])->middleware('throttle:60,1')->name('stocks.quotes');
        Route::get('stocks/data/{symbol}/reference-depth', [Client\ExploreController::class, 'referenceDepth'])->middleware('throttle:60,1')->name('stocks.reference-depth');
        Route::get('stocks/data/{symbol}/candles', [Client\ExploreController::class, 'stockCandles'])->middleware('throttle:60,1')->name('stocks.candles');
        Route::get('stocks/{symbol}', [Client\ExploreController::class, 'stocks'])->name('stocks.show');
        Route::get('umi-ecosystem', [Client\ExploreController::class, 'ecosystem'])->name('umi.ecosystem');
        Route::get('umi-ecosystem/activate', [Client\UmiController::class, 'activation'])->name('umi.activate');
        Route::post('umi-ecosystem/recovery', [Client\UmiController::class, 'recover'])->middleware('throttle:3,10')->name('umi.recovery');
        Route::post('umi-ecosystem/activate/code', [Client\UmiController::class, 'send'])->middleware('throttle:5,1')->name('umi.activate.code');
        Route::post('umi-ecosystem/activate/complete', [Client\UmiController::class, 'complete'])->middleware('throttle:10,1')->name('umi.activate.complete');
        Route::post('umi-ecosystem/bind/code', [Client\UmiController::class, 'bindingCode'])->middleware(['auth','verified','throttle:5,1'])->name('umi.bind.code');
        Route::post('umi-ecosystem/bind/complete', [Client\UmiController::class, 'bindingComplete'])->middleware(['auth','verified','throttle:10,1'])->name('umi.bind.complete');
        Route::post('umi-ecosystem/finance/preview', [Client\UmiBusinessController::class, 'preview'])->middleware(['auth','verified','throttle:30,1'])->name('umi.finance.preview');
        Route::get('umi-ecosystem/finance', [Client\UmiBusinessController::class, 'index'])->middleware(['auth','verified'])->name('umi.finance');
        Route::post('umi-ecosystem/finance', [Client\UmiBusinessController::class, 'submit'])->middleware(['auth','verified','throttle:30,1'])->name('umi.finance.submit');
        Route::get('umi-ecosystem/account', [Client\UmiController::class, 'account'])->middleware('auth')->name('umi.account');
        Route::get('umi-ecosystem/portfolio/quote', [Client\UmiV2Controller::class, 'quote'])->middleware(['auth','verified','throttle:30,1'])->name('umi.quote');
        Route::get('umi-ecosystem/portfolio/records', [\App\Http\Controllers\Web\UmiRecordController::class, 'member'])->middleware(['auth','verified','throttle:60,1'])->name('umi.records');
        Route::get('umi-ecosystem/portfolio/team', [\App\Http\Controllers\Web\UmiRecordController::class, 'memberTeam'])->middleware(['auth','verified'])->name('umi.team');
        Route::get('umi-ecosystem/portfolio', [Client\UmiV2Controller::class, 'index'])
            ->middleware(['auth', 'verified'])->name('umi.portfolio');
        Route::post('umi-ecosystem/portfolio', [Client\UmiV2Controller::class, 'submit'])
            ->middleware(['auth', 'verified', 'throttle:30,1'])->name('umi.portfolio.submit');
        Route::post('umi-ecosystem/portfolio/preview', [Client\UmiV2Controller::class, 'preview'])
            ->middleware(['auth', 'verified', 'throttle:30,1'])->name('umi.portfolio.preview');
        Route::get('umi-ecosystem/v2', [Client\UmiV2Controller::class, 'index'])
            ->middleware(['auth', 'verified'])->name('umi.v2');
        Route::post('umi-ecosystem/v2', [Client\UmiV2Controller::class, 'submit'])
            ->middleware(['auth', 'verified', 'throttle:30,1'])->name('umi.v2.submit');
        Route::post('umi-ecosystem/v2/preview', [Client\UmiV2Controller::class, 'preview'])
            ->middleware(['auth', 'verified', 'throttle:30,1'])->name('umi.v2.preview');
        Route::get('umi-ecosystem/documents/{document}', [Client\ExploreController::class, 'document'])->name('umi.document');
        // Public pages: Fees and Trading Rules
        Route::get('fees', [Client\CurrencyController::class, 'fees'])->name('page.fees');
        Route::get('trading-rules', [Client\CurrencyController::class, 'tradingRules'])->name('page.trading-rules');

        Route::get('markets/lite', [Client\MarketController::class, 'indexLite'])->name('markets.lite');
        Route::get('market/lite/{market:name}/{chart?}', [Client\MarketController::class, 'showLite'])->name('market.lite');
        Route::get('options-market/lite/{market:name}', [Client\MarketController::class, 'optionsShowLite'])->name('options-market.lite');

        /*
         * Public Market Pages
         */
        Route::get('markets', [Client\MarketController::class, 'index'])->name('markets');
        Route::get('markets/spot', [Client\MarketController::class, 'spot'])->name('markets.spot');
        Route::get('markets/futures', [Client\MarketController::class, 'futuresIndex'])->name('markets.futures');
        Route::get('markets/options', [Client\MarketController::class, 'optionsIndex'])->name('markets.options');
        Route::get('markets/exchange_rate', [Client\MarketController::class, 'exchangeRate'])->name('market.exchange_rate');
        Route::get('copy-trading', [Client\CopyTradingController::class, 'index'])->name('copy-trading');
        Route::get('copy-trading/{copyTrader}', [Client\CopyTradingController::class, 'show'])->name('copy-trading.show');
        Route::post('copy-trading/{copyTrader}/follow', [Client\CopyTradingController::class, 'follow'])->middleware('auth')->name('copy-trading.follow');
        Route::delete('copy-trading/{copyTrader}/follow', [Client\CopyTradingController::class, 'unfollow'])->middleware('auth')->name('copy-trading.unfollow');

        Route::get('market/{market:name}/{chart?}', [Client\MarketController::class, 'show'])->name('market');
        Route::get('futures-market/lite/{market:name}', [Client\MarketController::class, 'futuresShowLite'])->name('futures-market.lite');
        Route::get('futures-market/{market:name}', [Client\MarketController::class, 'futuresShow'])->name('futures-market');
        Route::get('/markets/{market}/kline-delete-time', [Client\MarketController::class, 'klineDeleteTime'])->name('markets.kline-delete-time');
        Route::get('options-market/{market:name}', [Client\MarketController::class, 'optionsShow'])->name('options-market');

        /*
         * Swap
         */
        Route::get('swap', [Client\MarketController::class, 'swap'])->name('swap');

        /*
         * Public Launchpad Pages
         */
        Route::get('launchpads/{sort?}', [Client\LaunchpadController::class, 'index'])->name('launchpads');
        Route::get('launchpad/{launchpad}', [Client\LaunchpadController::class, 'show'])->name('launchpad');
        Route::post('launchpad/submit', [Client\LaunchpadController::class, 'submit'])->name('launchpad.submit');

        /*
         * Public Article Pages
         */
        Route::get('articles/{sort?}', [Client\ArticleController::class, 'index'])->name('articles');
        Route::get('article/short/{article}', [Client\ArticleController::class, 'showShort'])->name('article.short');
        Route::get('article/{article}', [Client\ArticleController::class, 'show'])->name('article');
        Route::get('faq', [Client\ArticleController::class, 'faq'])->name('faq');
        /*
         * Public Staking Pages
         */
        Route::get('staking/{sort?}', [Client\StakingController::class, 'index'])->name('stakings');
        Route::get('staking-page/{staking}', [Client\StakingController::class, 'show'])->name('staking');
        Route::post('staking/submit', [Client\StakingController::class, 'submit'])->name('staking.submit');
        Route::post('staking/redeem', [Client\StakingController::class, 'redeem'])->name('staking.redeem');
        Route::get('staking/redemption/calculate', [Client\StakingController::class, 'redemptionCalculate'])->name('staking.redemption.calculate');

        /*
         * Public Lending Pages
         */
        Route::get('lending/collaterral/calc', [Client\LendingController::class, 'calcCollaterral'])->name('lendings.collaterral.calc');
        Route::get('lending/collaterrals', [Client\LendingController::class, 'collaterrals'])->name('lendings.collaterrals');
        Route::get('lending/{sort?}', [Client\LendingController::class, 'index'])->name('lendings');
        Route::get('lending-page/{lending}', [Client\LendingController::class, 'show'])->name('lending');

        Route::post('lending/borrow', [Client\LendingController::class, 'borrow'])->name('lending.borrow');


        Route::post('lending.collateral.add', [Client\LendingController::class, 'addCollateral'])->name('lending.collateral.add');

        Route::post('lending/repay', [Client\LendingController::class, 'repay'])->name('lending.repay');
        Route::post('lending/redemption/calculate', [Client\LendingController::class, 'redemptionCalculate'])->name('lending.redemption.calculate');
        
        

        /*
         * Public Chart Page
         */
        Route::group(['middleware' => ['throttle:chart']], function () {
            foreach (['history','symbols','config','time'] as $endpoint) {
                Route::get('tradingview-chart/trades/'.$endpoint, [Client\ChartController::class, $endpoint])->defaults('chart_feed','trades');
            }
            Route::get('tradingview-chart/config', [Client\ChartController::class, 'config'])->name('chart.config');
            Route::get('tradingview-chart/symbols', [Client\ChartController::class, 'symbols'])->name('chart.symbols');
            Route::get('tradingview-chart/search', [Client\ChartController::class, 'search'])->name('chart.search');
            Route::get('tradingview-chart/chart-mobile/{symbol}/{route}/{theme}', [Client\ChartController::class, 'mobile'])->name('chart.mobile');
            Route::get('tradingview-chart/chart/{symbol}/{route}/{theme}', [Client\ChartController::class, 'index'])->name('chart');
            Route::get('tradingview-chart/time', [Client\ChartController::class, 'time'])->name('chart.time');
            Route::get('tradingview-chart/history', [Client\ChartController::class, 'history'])->name('chart.history');
            Route::get('tradingview-chart', [Client\ChartController::class, 'candles'])->name('chart.candles');
            Route::get('chart.io/config', [Client\ChartController::class, 'config'])->name('chart.io.config');
            Route::get('chart.io/symbols', [Client\ChartController::class, 'symbols'])->name('chart.io.symbols');
            Route::get('chart.io/search', [Client\ChartController::class, 'search'])->name('chart.io.search');
            Route::get('chart.io/time', [Client\ChartController::class, 'time'])->name('chart.io.time');
            Route::get('chart.io/history', [Client\ChartController::class, 'history'])->name('chart.io.history');
            Route::get('chart.io', [Client\ChartController::class, 'candles'])->name('chart.io');
        });

        Route::get('external-kyc/{user}', [Client\SumsubController::class, 'index'])->name('sumsub.main');

        /*
         * Settings Controller
         */
        Route::get('settings/mode', [Client\SettingsController::class, 'mode'])->name('settings.theme.mode');

        /*
         * Language Loader
         */
        Route::get('language/load', [Client\LanguageController::class, 'index'])->name('language.load');
        Route::get('language/set', [Client\LanguageController::class, 'set'])->name('language.set');

        // Support Form (viewable by everyone, but submission requires login)
        Route::get('support', [Client\SupportController::class, 'index'])->name('support');
        Route::post('support', [Client\SupportController::class, 'store'])->middleware(['auth', 'throttle:5,1'])->name('support.store');

        Route::middleware('auth')->group(function () {
            Route::get('support/tickets', [Client\SupportController::class, 'tickets'])->name('support.tickets');
            Route::get('support/tickets/{ticket}', [Client\SupportController::class, 'show'])->name('support.tickets.show');
            Route::post('support/tickets/{ticket}', [Client\SupportController::class, 'update'])->middleware('throttle:10,1')->name('support.tickets.update');
            Route::post('support/attachments', [Client\SupportAttachmentController::class, 'store'])->middleware('throttle:upload')->name('support.attachments.store');
            Route::get('support/attachments/{file}', [Client\SupportAttachmentController::class, 'download'])->name('support.attachments.download');
            Route::delete('support/attachments', [Client\FileController::class, 'delete'])->middleware('throttle:upload')->name('support.attachments.delete');
        });

        Route::group(['middleware' => ['verified']], function () {

            Route::get('user/kyc', [Client\KycDocumentController::class, 'index'])->name('user.kyc');
            Route::post('user/kyc', [Client\KycDocumentController::class, 'store'])->name('user.kyc.store');

            Route::group(['middleware' => ['throttle:upload']], function () {
                Route::post('/upload', [Client\FileController::class, 'upload'])->name('user-file-upload');
                Route::post('/upload-document', [Client\FileController::class, 'uploadDocument'])->name('user-file-upload-document');
                Route::post('/upload-merchant', [Client\FileController::class, 'uploadMerchant'])->name('user-file-upload-merchant');
                Route::delete('/upload', [Client\FileController::class, 'delete'])->name('user-file-delete');

            });

            /*
             * Bank Account
            */
            Route::post('/bank-account/plaid-token', [Client\BankAccountController::class, 'plaid_token'])->name('bank_account.plaid_token');
            Route::post('/bank-account/kyc', [Client\BankAccountController::class, 'kyc'])->name('bank_account.kyc_submit');
            Route::post('/bank-account/link-account', [Client\BankAccountController::class, 'link_account'])->name('bank_account.link_account');
            Route::post('/bank-account/delete-account', [Client\BankAccountController::class, 'delete_account'])->name('bank_account.delete_account');
            Route::post('/bank-account/deposit', [Client\BankAccountController::class, 'issueSila'])->name('bank_account.deposit');
            Route::get('/bank-account/accounts', [Client\BankAccountController::class, 'getAccounts'])->name('bank_account.accounts');
            Route::get('/bank-account/transactions', [Client\BankAccountController::class, 'getTransactions'])->name('bank_account.transactions');
            
            Route::get('/bank-account/countries', [Client\BankAccountController::class, 'countries'])->name('bank_account.countries');

            Route::post('/wallets/saveAutoInvest', [Client\WalletController::class, 'saveAutoInvest'])->name('wallets.saveAutoInvest');
            
            
            Route::get('/wallets/auto-invest', [Client\WalletController::class, 'autoInvest'])->name('wallets.auto-invest');
            /*
             * Bank Account
            */
            Route::post('/voucher/redeem', [Client\VoucherController::class, 'redeem'])->name('voucher.redeem');
            Route::get('/voucher/transactions', [Client\VoucherController::class, 'transactions'])->name('voucher.transactions');

            // Wallets Page

            Route::get('wallets/lite', [Client\WalletController::class, 'indexLite'])->name('wallets.lite');

            Route::get('wallets/accounts', [Client\WalletController::class, 'newWallets'])->name('wallets.new');
            
            Route::get('wallets/trading', [Client\WalletController::class, 'trading'])->name('wallets.trading');
            
            Route::get('wallets/investfunding', [Client\WalletController::class, 'investfunding'])->name('wallets.investfunding');
            
            Route::get('wallets/trading/lite', [Client\WalletController::class, 'tradingLite'])->name('wallets.trading.lite');
            Route::get('wallets/investfunding/lite', [Client\WalletController::class, 'investfundingLite'])->name('wallets.investfunding.lite');
            
            
            Route::get('wallets/transfer', [Client\WalletController::class, 'transfer'])->name('wallets.transfer');
            Route::get('wallets', [Client\WalletController::class, 'index'])->name('wallets');

            Route::get('wallets/deposit/crypto/{symbol}', [Client\WalletController::class, 'depositCrypto'])->name('wallets.deposit.crypto');

            Route::get('wallets/deposit/fiat/success/', [Client\WalletController::class, 'depositFiatSuccess'])->name('wallets.deposit.fiat.success');
            Route::get('wallets/deposit/fiat/failed', [Client\WalletController::class, 'depositFiatFailed'])->name('wallets.deposit.fiat.failed');

            Route::get('wallets/deposit/payment/success', [Client\WalletController::class, 'depositPaymentSuccess'])->name('wallets.deposit.payment.success');
            Route::get('wallets/deposit/payment/failed', [Client\WalletController::class, 'depositPaymentFailed'])->name('wallets.deposit.payment.failed');
            Route::get('wallets/deposit/payment/cancel', [Client\WalletController::class, 'depositPaymentCancel'])->name('wallets.deposit.payment.cancel');

            Route::get('wallets/deposit/fiat/cancel/{symbol}', [Client\WalletController::class, 'depositFiatCancel'])->name('wallets.deposit.fiat.cancel');
            Route::get('wallets/deposit/fiat/{symbol}', [Client\WalletController::class, 'depositFiat'])->name('wallets.deposit.fiat');

            Route::get('wallets/withdraw/crypto/success', [Client\WalletController::class, 'withdrawCryptoSuccess'])->name('wallets.withdraw.crypto.success');
            Route::get('wallets/withdraw/crypto/{symbol}', [Client\WalletController::class, 'withdrawCrypto'])->middleware('auth')->name('wallets.withdraw.crypto');

            Route::get('wallets/withdraw/fiat/success', [Client\WalletController::class, 'withdrawFiatSuccess'])->name('wallets.withdraw.fiat.success');
            Route::get('wallets/withdraw/fiat/{symbol}', [Client\WalletController::class, 'withdrawFiat'])->name('wallets.withdraw.fiat');

            Route::post('wallets/deposit/store/fiat', [Client\WalletController::class, 'depositStore'])->name('wallets.deposit.store.bank');
            Route::post('wallets/redeemAutoInvestOrder', [Client\WalletController::class, 'redeemAutoInvestOrder'])->name('wallets.redeemAutoInvestOrder');
            
            Route::post('wallets/withdraw/store/fiat', [Client\WalletController::class, 'withdrawStore'])->name('wallets.withdraw.store.fiat');

            // Orders Page
            Route::get('orders', [Client\OrderController::class, 'index'])->name('orders');

            // Reports Page
            Route::get('reports/deposits', [Client\ReportController::class, 'deposits'])->name('reports.deposits');
            
            Route::get('reports/fee-refunds', [Client\ReportController::class, 'feeRefunds'])->name('reports.fee-refunds');
            
            Route::get('reports/fiat-deposits', [Client\ReportController::class, 'fiatDeposits'])->name('reports.deposits.fiat');

            Route::get('reports/withdrawals', [Client\ReportController::class, 'withdrawals'])->name('reports.withdrawals');
            Route::get('reports/fiat-withdrawals', [Client\ReportController::class, 'fiatWithdrawals'])->name('reports.withdrawals.fiat');

            Route::get('reports/trades', [Client\ReportController::class, 'trades'])->name('reports.trades');
            Route::get('reports/options', [Client\ReportController::class, 'options'])->name('reports.options');

            Route::get('orders/order-history', [Client\ReportController::class, 'orderHistory'])->name('reports.order-history');
            Route::get('reports/referral-transactions', [Client\ReportController::class, 'referralTransactions'])->name('reports.referral-transactions');
            Route::get('reports/launchpad-transactions', [Client\ReportController::class, 'launchpadTransactions'])->name('reports.launchpad-transactions');
            Route::get('reports/bonuses', [Client\ReportController::class, 'depositBonuses'])->name('reports.bonuses');
            Route::get('reports/adjustments', [Client\ReportController::class, 'adjustments'])->name('reports.adjustments');
            Route::get('reports/futures-trades', [Client\ReportController::class, 'futuresTrades'])->name('reports.trades.futures');
            Route::get('reports/funding-fee-distributions', [Client\ReportController::class, 'fundingFeeDistributions'])->name('reports.funding-fee-distributions');

            Route::middleware('auth:sanctum')->get('/user', function (\Illuminate\Http\Request $request) {
                return $request->user();
            });
        });

        // oAuth redirect and callback urls
        Route::get('/login/google', [Client\GoogleLoginController::class, 'redirectToGoogle'])->name('auth.google');
        Route::get('/login/google/callback', [Client\GoogleLoginController::class, 'handleGoogleCallback']);
        Route::get('/auth/twitter', [Client\TwitterController::class, 'redirectToTwitter'])->name('auth.twitter');
        Route::get('/auth/twitter/callback', [Client\TwitterController::class, 'handleTwitterCallback']);

        /*
         * Public Static Pages
         */

        Route::get('/short/{slug}', [Client\PageController::class, 'showShort'])->name('page.show.short');

        Route::get('demo-mode', [Client\SystemMonitorController::class, 'assignRole'])->name('system-monitor.assignRole');

        Route::get('{slug}', [Client\PageController::class, 'show'])->name('page.show');

    });
});
