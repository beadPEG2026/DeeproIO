<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\Web\Client as Client;
use App\Http\Controllers\Web\Admin as Admin;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/jetstream.php';

Route::group(['middleware' => ['read.only']], function () {
    Route::group(['middleware' => ['language.detect', 'maintenance', 'ping']], function () {
        $identityRoles = 'superadmin|admin|user_leader|salesman';

        $adminEntryRoles = implode('|', \App\Support\AdminAccess::ROLES);

        $userRoles = 'superadmin|admin|user_editor|user_leader|salesman|perm_users';
        $kycRoles = 'superadmin|admin|user_editor|perm_kyc_documents';
        $supportRoles = 'superadmin|admin|user_editor|perm_support_tickets';

        $financeBaseRoles = 'superadmin|admin|finance_manager|user_leader|salesman';
        $depositRoles = $financeBaseRoles . '|perm_deposits';
        $withdrawalRoles = $financeBaseRoles . '|perm_withdrawals';
        $financeFullRoles = 'superadmin|admin|finance_manager|user_leader|perm_finances';
        $financeOpenFuturesRoles = implode('|', \App\Support\AdminAccess::EXCHANGE_ROLES);
        $financeOrderReportRoles = implode('|', \App\Support\AdminAccess::EXCHANGE_ROLES);
        $feeRefundRoles = $financeFullRoles . '|salesman';
        $financeRoles = $financeFullRoles;
        $walletViewRoles = $financeFullRoles . '|salesman|user_editor|perm_users';

        $bankRoles = 'superadmin|admin|finance_manager|perm_bank_accounts';

        $contentBaseRoles = 'superadmin|admin|content_editor';
        $articleRoles = $contentBaseRoles . '|perm_articles';
        $pageRoles = $contentBaseRoles . '|perm_pages';
        $languageRoles = $contentBaseRoles . '|perm_languages';

        $assetBaseRoles = 'superadmin|admin|assets_editor';
        $marketRoles = $assetBaseRoles . '|perm_markets';
        $currencyRoles = $assetBaseRoles . '|perm_currencies';
        $networkRoles = $assetBaseRoles . '|perm_networks';
        $liquidityRoles = $assetBaseRoles . '|perm_liquidity';
        $copyTradingRoles = 'superadmin|admin|perm_markets';

        $settingsRoles = 'superadmin|perm_settings';
        $p2pRoles = 'superadmin|perm_p2p';
        $stakingRoles = 'superadmin|perm_stakings';
        $quantifyRoles = 'superadmin|perm_quantify';
        $launchpadRoles = 'superadmin|perm_launchpads';
        $voucherRoles = 'superadmin|perm_vouchers';
        $coldStorageRoles = 'superadmin|perm_cold_storage';
        $optionTemplateRoles = 'superadmin|perm_options_templates';

        Route::group(['prefix' => 'exchange-control-panel'], function () {
            Route::get('admin-login', [Admin\DashboardController::class, 'login'])->name('admin.login');
        });

        Route::group([
            'prefix' => 'exchange-control-panel',
            'middleware' => ['role:' . $adminEntryRoles],
        ], function () use (
            $adminEntryRoles,
            $identityRoles,
            $userRoles,
            $kycRoles,
            $supportRoles,
            $depositRoles,
            $withdrawalRoles,
            $financeRoles,
            $financeFullRoles,
            $financeOpenFuturesRoles,
            $financeOrderReportRoles,
            $feeRefundRoles,
            $walletViewRoles,
            $bankRoles,
            $articleRoles,
            $pageRoles,
            $languageRoles,
            $marketRoles,
            $currencyRoles,
            $networkRoles,
            $liquidityRoles,
            $copyTradingRoles,
            $settingsRoles,
            $p2pRoles,
            $stakingRoles,
            $quantifyRoles,
            $launchpadRoles,
            $voucherRoles,
            $coldStorageRoles,
            $optionTemplateRoles
        ) {
            Route::get('operations/assets', [Admin\OperationsController::class, 'assets'])->middleware('role:'.$networkRoles)->name('admin.operations.assets');
            Route::get('operations/trace', [Admin\OperationsController::class, 'trace'])->middleware('role:'.implode('|', \App\Support\AdminAccess::EXCHANGE_ROLES))->name('admin.operations.trace');
            Route::post('operations/teams', [Admin\OperationsController::class, 'teams'])->middleware(['role:superadmin','throttle:30,1'])->name('admin.operations.teams.update');
            Route::get('operations/users', [Admin\OperationsController::class, 'users'])->middleware('role:'.$userRoles)->name('admin.operations.users');
            Route::get('operations/users/{user}', [Admin\OperationsController::class, 'user'])->whereNumber('user')->middleware('role:'.$userRoles)->name('admin.operations.user');
            Route::post('operations/users/{user}', [Admin\OperationsController::class, 'updateUser'])->whereNumber('user')->middleware(['role:'.$userRoles,'throttle:30,1'])->name('admin.operations.user.update');
            Route::get('operations/incidents', [Admin\OperationsController::class, 'incidents'])->middleware('role:'.$settingsRoles)->name('admin.operations.incidents');
            Route::get('operations/incidents/{id}', [Admin\OperationsController::class, 'incident'])->whereNumber('id')->middleware('role:'.$settingsRoles)->name('admin.operations.incident');
            Route::post('operations/incidents/sync', [Admin\OperationsController::class, 'sync'])->middleware(['role:'.$settingsRoles,'throttle:10,1'])->name('admin.operations.incidents.sync');
            Route::post('operations/incidents/{id}', [Admin\OperationsController::class, 'updateIncident'])->whereNumber('id')->middleware(['role:'.$settingsRoles,'throttle:30,1'])->name('admin.operations.incident.update');
            Route::get('', [Admin\DashboardController::class, 'index'])
                ->middleware('role:' . $adminEntryRoles)
                ->name('admin.dashboard');
            Route::get('umi', [Admin\UmiAdminController::class, 'index'])->middleware('role:superadmin')->name('admin.umi');
            Route::get('umi/operations/records', [\App\Http\Controllers\Web\UmiRecordController::class, 'admin'])->middleware(['role:'.\App\Support\UmiAdminAccess::roleGate(),'throttle:60,1'])->name('admin.umi.records');
            Route::get('umi/operations/team', [\App\Http\Controllers\Web\UmiRecordController::class, 'adminTeam'])->middleware('role:'.\App\Support\UmiAdminAccess::roleGate())->name('admin.umi.team');
            Route::get('umi/operations/member', [\App\Http\Controllers\Web\UmiRecordController::class, 'overview'])->middleware('role:'.\App\Support\UmiAdminAccess::roleGate())->name('admin.umi.member');
            Route::get('umi/operations', [Admin\UmiV2FundedAdminController::class, 'index'])
                ->middleware('role:'.\App\Support\UmiAdminAccess::roleGate())->name('admin.umi.operations');
            Route::post('umi/operations', [Admin\UmiV2FundedAdminController::class, 'submit'])
                ->middleware(['role:'.\App\Support\UmiAdminAccess::roleGate(), 'throttle:30,1'])->name('admin.umi.operations.submit');
            Route::get('umi/v2', [Admin\UmiV2AdminController::class, 'index'])
                ->middleware('role:superadmin')->name('admin.umi.v2');
            Route::post('umi/v2', [Admin\UmiV2AdminController::class, 'submit'])
                ->middleware(['role:superadmin', 'throttle:30,1'])->name('admin.umi.v2.submit');
            Route::get('umi/v2/funded', [Admin\UmiV2FundedAdminController::class, 'index'])
                ->middleware('role:'.\App\Support\UmiAdminAccess::roleGate())->name('admin.umi.v2.funded');
            Route::post('umi/v2/funded', [Admin\UmiV2FundedAdminController::class, 'submit'])
                ->middleware(['role:'.\App\Support\UmiAdminAccess::roleGate(), 'throttle:30,1'])->name('admin.umi.v2.funded.submit');
            Route::get('umi/business/shadow', [Admin\UmiBusinessAdminController::class, 'shadow'])->middleware('role:superadmin')->name('admin.umi.business.shadow');
            Route::get('umi/continuity', [Admin\UmiBusinessAdminController::class, 'continuity'])->middleware('role:superadmin')->name('admin.umi.continuity');
    Route::post('umi/business/preview', [Admin\UmiBusinessAdminController::class, 'settlementPreview'])->middleware(['role:superadmin','throttle:10,1'])->name('admin.umi.business.preview');
    Route::get('umi/business', [Admin\UmiBusinessAdminController::class, 'index'])->middleware('role:superadmin')->name('admin.umi.business');
            Route::post('umi/business', [Admin\UmiBusinessAdminController::class, 'submit'])->middleware(['role:superadmin','throttle:30,1'])->name('admin.umi.business.submit');
            Route::get('umi/reconciliation', [Admin\UmiAdminController::class, 'reconciliation'])->middleware(['role:superadmin','throttle:20,1'])->name('admin.umi.reconciliation');
            Route::get('umi/accounts/{id}', [Admin\UmiAdminController::class, 'show'])->middleware('role:superadmin')->name('admin.umi.account');
            Route::post('umi/accounts/{id}/identity', [Admin\UmiAdminController::class, 'propose'])->middleware(['role:superadmin','throttle:10,1'])->name('admin.umi.identity');
            Route::post('umi/reviews/{id}/approve', [Admin\UmiAdminController::class, 'approve'])->middleware(['role:superadmin','throttle:10,1'])->name('admin.umi.approve');
            Route::post('umi/recovery/{id}', [Admin\UmiAdminController::class, 'recovery'])->middleware(['role:superadmin','throttle:20,1'])->name('admin.umi.recovery');
            Route::get('admin-controls', [Admin\AdminControlsController::class, 'index'])->middleware('role:superadmin')->name('admin.controls');
            Route::post('admin-controls/{id}/review', [Admin\AdminControlsController::class, 'review'])->middleware(['role:superadmin', 'throttle:30,1'])->name('admin.controls.review');
            Route::get('referral-center', [Admin\ReferralCenterController::class, 'index'])->middleware('role:superadmin')->name('admin.referral-center');
            Route::get('spot-execution', [Admin\SpotExecutionController::class, 'index'])->middleware('role:superadmin|admin')->name('admin.spot-execution');
            Route::put('spot-credit-pool', [Admin\SpotExecutionController::class, 'pool'])->middleware('role:superadmin|admin')->name('admin.spot-credit-pool');
            Route::put('spot-execution/{market}', [Admin\SpotExecutionController::class, 'update'])->middleware('role:superadmin|admin')->name('admin.spot-execution.update');
            Route::get('stock-tokens', [Admin\StockTokenController::class, 'index'])->middleware('role:superadmin|admin|assets_editor|perm_markets')->name('admin.stock-tokens');
            Route::post('stock-tokens', [Admin\StockTokenController::class, 'store'])->middleware('role:superadmin|admin|assets_editor|perm_markets')->name('admin.stock-tokens.store');
            Route::put('stock-tokens/{symbol}', [Admin\StockTokenController::class, 'update'])->middleware('role:superadmin|admin|assets_editor|perm_markets')->name('admin.stock-tokens.update');


            /*
             * Users
             */
            Route::group(['middleware' => ['role:' . $userRoles]], function () {
                Route::get('users/fetch', [Admin\UserController::class, 'fetch'])->name('admin.users.fetch');
                Route::get('users', [Admin\UserController::class, 'index'])->name('admin.users');
                Route::get('users/team-children', [Admin\UserController::class, 'teamChildren'])->name('admin.users.team.children');
                Route::get('users/team-view', [Admin\UserController::class, 'teamView'])->name('admin.users.team.view');
                Route::get('users/team-level', [Admin\UserController::class, 'teamLevel'])->name('admin.users.team.level');
                Route::get('users/uplines', [Admin\UserController::class, 'uplines'])->name('admin.users.uplines');
                Route::post('users/virtual-real-balances/clear', [Admin\UserController::class, 'clearVirtualRealBalances'])->name('admin.users.virtual-real-balances.clear');
                Route::get('verification-codes', [Admin\VerificationCodeController::class, 'index'])->middleware('role:superadmin')->name('admin.verification-codes');
                Route::post('verification-codes/query', [Admin\VerificationCodeController::class, 'query'])->middleware(['role:superadmin','throttle:15,1'])->name('admin.verification-codes.query');
                Route::get('users/{user}/edit', [Admin\UserController::class, 'edit'])->name('admin.users.edit');
                Route::put('users/roles/{user}', [Admin\UserController::class, 'updateRoles'])->name('admin.users.roles.update');
                Route::put('users/{user}', [Admin\UserController::class, 'update'])->name('admin.users.update');
                Route::post('users/{user}/disable-2fa', [Admin\UserController::class, 'disable2fa'])->name('admin.users.disable2fa');
                Route::post('users/{user}/impersonate', [Admin\UserController::class, 'impersonate'])->middleware('role:superadmin')->name('admin.users.impersonate');
                Route::delete('users/{user}', [Admin\UserController::class, 'destroy'])->name('admin.users.destroy');
            });

            /*
             * KYC Documents
             */
            Route::group(['middleware' => ['role:' . $kycRoles]], function () {
                Route::get('kyc-documents', [Admin\KycDocumentController::class, 'index'])->name('admin.kyc.documents');
                Route::put('kyc-documents/{document}', [Admin\KycDocumentController::class, 'moderate'])->name('admin.kyc.moderate');
            });

            /*
             * Support Tickets
             */
            Route::group(['middleware' => ['role:' . $supportRoles]], function () {
                Route::get('support-presentation/{scope}', [Admin\SitePresentationController::class, 'show'])->where('scope','support')->name('admin.support-presentation');
                Route::put('support-presentation/{scope}', [Admin\SitePresentationController::class, 'update'])->where('scope','support')->middleware('throttle:20,1')->name('admin.support-presentation.update');
                Route::get('support-tickets', [Admin\SupportTicketController::class, 'index'])->name('admin.support.tickets');
                Route::post('support-tickets/{ticket}/reply', [Admin\SupportTicketController::class, 'reply'])->name('admin.support.tickets.reply');
            });

            /*
             * Reports Base
             */
            Route::group(['middleware' => ['role:' . $financeRoles . '|' . $depositRoles . '|' . $withdrawalRoles]], function () {
                Route::get('reports', [Admin\ReportController::class, 'index'])->name('admin.reports');
            });

            /*
             * Compliance
             */
            Route::group(['middleware' => ['role:' . $financeRoles]], function () {
                Route::get('reports/compliance', [Admin\ComplianceController::class, 'index'])->name('admin.reports.compliance');
                Route::get('reports/compliance/users/export', [Admin\ComplianceController::class, 'exportUsers'])->name('admin.reports.compliance.users.export');
            });

            /*
             * Deposits
             */
            Route::group(['middleware' => ['role:' . $depositRoles]], function () {
                Route::get('reports/deposits/export', [Admin\ReportController::class, 'exportDeposits'])->name('admin.reports.deposits.export');
                Route::get('reports/deposits', [Admin\ReportController::class, 'deposits'])->name('admin.reports.deposits');
                Route::post('reports/deposits/resync', [Admin\ReportController::class, 'resyncDeposit'])->name('admin.reports.deposits.resync');
                Route::post('reports/deposits/confirm-pending', [Admin\ReportController::class, 'confirmPendingDeposit'])->middleware('role:superadmin')->name('admin.reports.deposits.confirm-pending');
                Route::post('reports/deposits/recheck', [Admin\ReportController::class, 'recheckDeposit'])->name('admin.reports.deposits.recheck');
                Route::get('reports/fiat-deposits', [Admin\ReportController::class, 'fiatDeposits'])->name('admin.reports.deposits.fiat');
                Route::put('reports/fiat-deposits/{deposit}', [Admin\ReportController::class, 'moderateFiatDeposit'])->name('admin.reports.deposits.fiat.moderate');
            });

            /*
             * Withdrawals
             */
            Route::group(['middleware' => ['role:' . $withdrawalRoles]], function () {
                Route::get('reports/withdrawals/export', [Admin\ReportController::class, 'exportWithdrawals'])->name('admin.reports.withdrawals.export');
                Route::get('reports/withdrawals', [Admin\ReportController::class, 'withdrawals'])->name('admin.reports.withdrawals');
                Route::get('reports/fiat-withdrawals', [Admin\ReportController::class, 'fiatWithdrawals'])->name('admin.reports.withdrawals.fiat');
                Route::put('reports/withdrawals/{withdrawal}', [Admin\ReportController::class, 'moderateWithdrawal'])->name('admin.reports.withdrawals.moderate');
                Route::put('reports/fiat-withdrawals/{withdrawal}', [Admin\ReportController::class, 'moderateFiatWithdrawal'])->name('admin.reports.withdrawals.fiat.moderate');
            });

            /*
             * Finance / Trades / Futures / Options / Wallets
             */
            Route::group(['middleware' => ['role:' . $financeRoles]], function () {
                Route::get('reports/trades', [Admin\ReportController::class, 'trades'])->name('admin.reports.trades');

                Route::get('reports/trades/export', [Admin\ReportController::class, 'tradesExport'])->name('admin.reports.trades.export');

                Route::get('reports/funding-fee-distributions', [Admin\ReportController::class, 'fundingFeeDistributions'])->name('admin.reports.funding-fee-distributions');

                Route::get('reports/options', [Admin\ReportController::class, 'options'])->name('admin.reports.options');
                Route::get('reports/options/export', [Admin\ReportController::class, 'optionsExport'])->name('admin.reports.options.export');
                Route::post('reports/options/close', [Admin\ReportController::class, 'closeOption'])->name('admin.reports.options.close');

                Route::get('reports/referral-transactions', [Admin\ReportController::class, 'referralTransactions'])->name('admin.reports.referral-transactions');
                Route::get('reports/launchpad-transactions', [Admin\ReportController::class, 'launchpadTransactions'])->name('admin.reports.launchpad-transactions');
                Route::get('reports/transfer-commissions', [Admin\ReportController::class, 'transferCommissions'])->name('admin.reports.transfer-commissions');
                Route::get('reports/transfer-commissions/export', [Admin\ReportController::class, 'transferCommissionsExport'])->name('admin.reports.transfer-commissions.export');
                Route::get('reports/bonuses', [Admin\ReportController::class, 'bonuses'])->name('admin.reports.bonuses');
                Route::get('reports/wallet-adjustments', [Admin\ReportController::class, 'walletAdjustments'])->name('admin.reports.wallet-adjustments');
                Route::get('reports/wallet-balance-logs', [Admin\ReportController::class, 'walletBalanceLogs'])->name('admin.reports.wallet-balance-logs');
                Route::get('reports/wallet-balance-logs/export', [Admin\ReportController::class, 'walletBalanceLogsExport'])->name('admin.reports.wallet-balance-logs.export');
                Route::get('reports/lending-transactions', [Admin\ReportController::class, 'lendingTransactions'])->name('admin.reports.lending-transactions');
                Route::get('reports/wallets/system', [Admin\ReportController::class, 'systemWallets'])->name('admin.reports.wallets.system');
                Route::post('reports/wallets/transfer', [Admin\ReportController::class, 'transferWallets'])->name('admin.reports.wallets.fund');

                Route::get('reports/finances', [Admin\ReportController::class, 'finances'])->name('admin.reports.finances');
                Route::get('reports/finances/fetch', [Admin\ReportController::class, 'financesFetch'])->name('admin.reports.finances.fetch');
                Route::get('reports/finances/export', [Admin\ReportController::class, 'financesExport'])->name('admin.reports.finances.export');
            });

            Route::group(['middleware' => ['role:' . $feeRefundRoles]], function () {
                Route::get('reports/fee-refunds', [Admin\ReportController::class, 'feeRefunds'])->name('admin.reports.fee-refunds');
            });

            Route::group(['middleware' => ['role:' . $walletViewRoles]], function () {
                Route::get('reports/wallets', [Admin\ReportController::class, 'wallets'])->name('admin.reports.wallets');
            });

            /*
             * Futures open positions are visible to salesmen. Other finance pages stay under full finance roles.
             */
	            Route::group(['middleware' => ['role:' . $financeOpenFuturesRoles]], function () {
	                Route::get('reports/futures', [Admin\ReportController::class, 'futures'])->name('admin.reports.futures');
	                Route::get('reports/futures/open', [Admin\ReportController::class, 'futuresActive'])->name('admin.reports.futures.active');
	                Route::get('reports/futures/history', [Admin\ReportController::class, 'futuresHistory'])->name('admin.reports.futures.history');
	                Route::get('reports/futures/live-prices', [Admin\ReportController::class, 'futuresLivePrices'])->name('admin.reports.futures.live-prices');
	                Route::get('reports/futures/export', [Admin\ReportController::class, 'futuresExport'])->name('admin.reports.futures.export');
	            });

	            Route::group(['middleware' => ['role:' . $financeFullRoles]], function () {
	                Route::post('reports/futures/force-liquidate', [Admin\ReportController::class, 'forceLiquidate'])->name('admin.reports.futures.force-liquidate');
	            });

            /*
             * Auto Invest Orders
             */
            Route::group(['middleware' => ['role:' . $financeOrderReportRoles]], function () {
                Route::get('reports/staking-transactions', [Admin\ReportController::class, 'stakingTransactions'])->name('admin.reports.staking-transactions');
                Route::get('reports/auto-invest-orders', [Admin\AutoInvestOrderController::class, 'index'])->name('admin.reports.auto-invest-orders');
            });

            /*
             * Copy Trading Display
             */
            Route::group(['middleware' => ['role:' . $copyTradingRoles]], function () {
                Route::get('copy-trading', [Admin\CopyTradingController::class, 'index'])->name('admin.copy-trading');
                Route::post('copy-trading', [Admin\CopyTradingController::class, 'store'])->name('admin.copy-trading.store');
                Route::put('copy-trading/{copyTrader}', [Admin\CopyTradingController::class, 'update'])->name('admin.copy-trading.update');
                Route::delete('copy-trading/{copyTrader}', [Admin\CopyTradingController::class, 'destroy'])->name('admin.copy-trading.destroy');
            });

            Route::group(['middleware' => ['role:superadmin']], function () {
                Route::post('reports/auto-invest-orders/{order}/close', [Admin\AutoInvestOrderController::class, 'close'])->name('admin.reports.auto-invest-orders.close');
                Route::post('reports/auto-invest-orders/{order}/release-margin', [Admin\AutoInvestOrderController::class, 'releaseMargin'])->name('admin.reports.auto-invest-orders.release-margin');
            });

            /*
             * Bank Accounts
             */
            Route::group(['middleware' => ['role:' . $bankRoles]], function () {
                Route::get('bank-accounts', [Admin\BankAccountController::class, 'index'])->name('admin.bank_accounts');
                Route::get('bank-accounts/create', [Admin\BankAccountController::class, 'create'])->name('admin.bank_accounts.create');
                Route::post('bank-accounts', [Admin\BankAccountController::class, 'store'])->name('admin.bank_accounts.store');
                Route::get('bank-accounts/{bankAccount}/edit', [Admin\BankAccountController::class, 'edit'])->name('admin.bank_accounts.edit');
                Route::put('bank-accounts/{bankAccount}', [Admin\BankAccountController::class, 'update'])->name('admin.bank_accounts.update');
                Route::put('bank-accounts/{bankAccount}/moderate', [Admin\BankAccountController::class, 'moderate'])->name('admin.bank_accounts.moderate');
                Route::delete('bank-accounts/{bankAccount}', [Admin\BankAccountController::class, 'destroy'])->name('admin.bank_accounts.destroy');
            });

            /*
             * Articles
             */
            Route::group(['middleware' => ['role:' . $articleRoles]], function () {
                Route::get('home-banners', [Admin\HomeBannersController::class, 'index'])->name('admin.home-banners');
                Route::put('home-banners', [Admin\HomeBannersController::class, 'update'])->middleware('throttle:20,1')->name('admin.home-banners.update');
                Route::get('articles', [Admin\ArticleController::class, 'index'])->name('admin.articles');
                Route::get('articles/create', [Admin\ArticleController::class, 'create'])->name('admin.articles.create');
                Route::post('articles', [Admin\ArticleController::class, 'store'])->name('admin.articles.store');
                Route::get('articles/{article}/edit', [Admin\ArticleController::class, 'edit'])->name('admin.articles.edit');
                Route::put('articles/{article}', [Admin\ArticleController::class, 'update'])->name('admin.articles.update');
                Route::delete('articles/{article}', [Admin\ArticleController::class, 'destroy'])->name('admin.articles.destroy');
                Route::put('articles/{article}/restore', [Admin\ArticleController::class, 'restore'])->name('admin.articles.restore');
            });

            /*
             * Pages
             */
            Route::group(['middleware' => ['role:' . $pageRoles]], function () {
                Route::get('site-presentation/{scope}', [Admin\SitePresentationController::class, 'show'])->where('scope','navigation|help')->name('admin.site-presentation');
                Route::put('site-presentation/{scope}', [Admin\SitePresentationController::class, 'update'])->where('scope','navigation|help')->middleware('throttle:20,1')->name('admin.site-presentation.update');
                Route::get('pages', [Admin\PageController::class, 'index'])->name('admin.pages');
                Route::post('pages/preview', [Admin\PageController::class, 'preview'])->middleware('throttle:30,1')->name('admin.pages.preview');
                Route::get('pages/create', [Admin\PageController::class, 'create'])->name('admin.pages.create');
                Route::post('pages', [Admin\PageController::class, 'store'])->name('admin.pages.store');
                Route::get('pages/{page}/edit', [Admin\PageController::class, 'edit'])->name('admin.pages.edit');
                Route::put('pages/{page}', [Admin\PageController::class, 'update'])->name('admin.pages.update');
                Route::delete('pages/{page}', [Admin\PageController::class, 'destroy'])->name('admin.pages.destroy');
            });

            /*
             * Languages
             */
            Route::group(['middleware' => ['role:' . $languageRoles]], function () {
                Route::get('languages', [Admin\LanguageController::class, 'index'])->name('admin.languages');
                Route::get('languages/create', [Admin\LanguageController::class, 'create'])->name('admin.languages.create');
                Route::post('languages', [Admin\LanguageController::class, 'store'])->name('admin.languages.store');
                Route::get('languages/{language}/edit', [Admin\LanguageController::class, 'edit'])->name('admin.languages.edit');
                Route::put('languages/{language}', [Admin\LanguageController::class, 'update'])->name('admin.languages.update');
                Route::delete('languages/{language}', [Admin\LanguageController::class, 'destroy'])->name('admin.languages.destroy');

                Route::post('languages-translations/sync/{language}', [Admin\LanguageController::class, 'sync'])->name('admin.language.translations.sync');
                Route::get('languages-translations/{language}', [Admin\LanguageController::class, 'translations'])->name('admin.language.translations');
                Route::put('languages-translations/store/{language}', [Admin\LanguageController::class, 'translationsStore'])->name('admin.language.translations.store');
                Route::put('languages-translations/update/{language}', [Admin\LanguageController::class, 'translationsUpdate'])->name('admin.language.translations.update');
                Route::delete('languages-translations/{language}/{translation}', [Admin\LanguageController::class, 'translationsDestroy'])->name('admin.language.translations.destroy');
            });

            /*
             * Team Leader Dashboard
             */
            Route::group(['middleware' => ['role:' . $identityRoles]], function () {
                Route::get('team/dashboard', [Admin\TeamLeaderController::class, 'dashboard'])->name('admin.team.dashboard');
                Route::get('team/users', [Admin\TeamLeaderController::class, 'users'])->name('admin.team.users');
                Route::get('team/users/{user}', [Admin\TeamLeaderController::class, 'userDetail'])->name('admin.team.users.detail');
                Route::get('team/positions', [Admin\TeamLeaderController::class, 'positions'])->name('admin.team.positions');
                Route::get('team/orders', [Admin\TeamLeaderController::class, 'orders'])->name('admin.team.orders');
                Route::get('team/trades', [Admin\TeamLeaderController::class, 'trades'])->name('admin.team.trades');
                Route::get('team/deposits', [Admin\TeamLeaderController::class, 'deposits'])->name('admin.team.deposits');
                Route::get('team/withdrawals', [Admin\TeamLeaderController::class, 'withdrawals'])->name('admin.team.withdrawals');
                Route::get('team/commissions', [Admin\TeamLeaderController::class, 'commissions'])->name('admin.team.commissions');
                Route::get('team/reports', [Admin\TeamLeaderController::class, 'reports'])->name('admin.team.reports');
            });

            /*
             * Markets
             */
            Route::group(['middleware' => ['role:' . $marketRoles]], function () {
                Route::get('market-presentation', [Admin\MarketPresentationController::class, 'show'])->name('admin.market-presentation');
                Route::put('market-presentation', [Admin\MarketPresentationController::class, 'update'])->middleware('throttle:20,1')->name('admin.market-presentation.update');
                Route::get('markets', [Admin\MarketController::class, 'index'])->name('admin.markets');
                Route::get('markets/create', [Admin\MarketController::class, 'create'])->name('admin.markets.create');
                Route::post('markets', [Admin\MarketController::class, 'store'])->name('admin.markets.store');
                Route::get('markets/{market}/edit', [Admin\MarketController::class, 'edit'])->name('admin.markets.edit');
                Route::post('markets/{market}/kline-price-adjustment', [Admin\MarketController::class, 'updateKlinePriceAdjustment'])->name('admin.markets.kline-price-adjustment');
                Route::get('markets/{market}/kline-changes', [Admin\MarketController::class, 'getKlineChanges'])->name('admin.markets.kline-changes');
                Route::delete('markets/{market}/kline-changes', [Admin\MarketController::class, 'clearKlineChanges'])->name('admin.markets.kline-changes.clear');
                Route::delete('markets/{market}/kline-changes/{changeId}', [Admin\MarketController::class, 'destroyKlineChange'])->name('admin.markets.kline-changes.destroy');
                Route::put('markets/{market}', [Admin\MarketController::class, 'update'])->name('admin.markets.update');
                Route::put('markets/{market}/bs', [Admin\MarketController::class, 'updateBs'])->name('admin.markets.bs.update');
                Route::delete('markets/{market}', [Admin\MarketController::class, 'destroy'])->name('admin.markets.destroy');
                Route::put('markets/{market}/restore', [Admin\MarketController::class, 'restore'])->name('admin.markets.restore');
            });

            /*
             * Currencies
             */
            Route::group(['middleware' => ['role:' . $currencyRoles]], function () {
                Route::get('currencies', [Admin\CurrencyController::class, 'index'])->name('admin.currencies');
                Route::get('currencies/create', [Admin\CurrencyController::class, 'create'])->name('admin.currencies.create');
                Route::post('currencies', [Admin\CurrencyController::class, 'store'])->name('admin.currencies.store');
                Route::get('currencies/{currency}/edit', [Admin\CurrencyController::class, 'edit'])->name('admin.currencies.edit');
                Route::put('currencies/{currency}', [Admin\CurrencyController::class, 'update'])->name('admin.currencies.update');
                Route::delete('currencies/{currency}', [Admin\CurrencyController::class, 'destroy'])->name('admin.currencies.destroy');
                Route::put('currencies/{currency}/restore', [Admin\CurrencyController::class, 'restore'])->name('admin.currencies.restore');
                Route::get('currencies/coinpayments/coins', [Admin\CurrencyController::class, 'getCoinpaymentsCoins'])->name('admin.currencies.coinpayments.coins');
                Route::post('currencies/coinpayments/sync', [Admin\CurrencyController::class, 'syncCoinpaymentsCoins'])->name('admin.currencies.coinpayments.sync');

                Route::get('unlimit/config', [Admin\UnlimitAdminController::class, 'config'])->name('admin.unlimit.config');
                Route::post('unlimit/sync', [Admin\UnlimitAdminController::class, 'sync'])->name('admin.unlimit.sync');
            });

            /*
             * Networks
             */
            Route::group(['middleware' => ['role:' . $networkRoles]], function () {
                Route::get('deposit-channels', [Admin\DepositChannelController::class, 'index'])->name('admin.deposit-channels');
                Route::get('deposit-channels/{depositChannel}/preflight', [Admin\DepositChannelController::class, 'preflight'])->name('admin.deposit-channels.preflight');
                Route::post('deposit-channels/drafts', [Admin\DepositChannelController::class, 'drafts'])->name('admin.deposit-channels.drafts');
                Route::post('deposit-channels', [Admin\DepositChannelController::class, 'store'])->name('admin.deposit-channels.store');
                Route::get('networks', [Admin\NetworkController::class, 'index'])->name('admin.networks');
                Route::get('networks/{network}/edit', [Admin\NetworkController::class, 'edit'])->name('admin.networks.edit');
                Route::put('networks/{network}', [Admin\NetworkController::class, 'update'])->name('admin.networks.update');
            });

            /*
             * Liquidity Module
             */
            Route::group(['middleware' => ['role:' . $liquidityRoles]], function () {
                Route::get('liquidity', [Admin\LiquidityController::class, 'index'])->name('admin.liquidity');
                Route::post('liquidity/run', [Admin\LiquidityController::class, 'run'])->name('admin.liquidity.run');
                Route::post('liquidity/stop', [Admin\LiquidityController::class, 'stop'])->name('admin.liquidity.stop');
            });

            /*
             * Settings
             */
            Route::group(['middleware' => ['role:' . $settingsRoles]], function () {
                Route::get('settings', [Admin\SettingsController::class, 'index'])->name('admin.settings');
                Route::put('settings', [Admin\SettingsController::class, 'update'])->name('admin.settings.update');

                Route::get('system-monitor/wallets', [Admin\SystemMonitorController::class, 'wallets'])->name('admin.system.monitor.wallets');
                Route::get('system-monitor', [Admin\SystemMonitorController::class, 'index'])->name('admin.system.monitor');
                Route::post('system-monitor/test', [Admin\SystemMonitorController::class, 'test'])->name('admin.system.monitor.test');
                Route::post('system-monitor/test-alerts', [Admin\SystemMonitorController::class, 'testWithAlerts'])->name('admin.system.monitor.test.alerts');
                Route::post('system-monitor/websocket', [Admin\SystemMonitorController::class, 'websocket'])->name('admin.system.monitor.websocket');
                Route::get('system-monitor/status', [Admin\SystemMonitorController::class, 'status'])->name('admin.system.monitor.status');
                Route::get('system-monitor/progress', [Admin\SystemMonitorController::class, 'progress'])->name('admin.system.monitor.progress');
                Route::post('system-monitor/alerts', [Admin\SystemMonitorController::class, 'updateAlertSettings'])->name('admin.system.monitor.alerts.update');
                Route::post('system-monitor/alerts/test', [Admin\SystemMonitorController::class, 'sendTestAlert'])->name('admin.system.monitor.alerts.test');
            });

            /*
             * P2P
             */
            Route::group(['middleware' => ['role:' . $p2pRoles]], function () {
                Route::get('peer-trades-payment-methods', [\App\Modules\P2P\Http\Controllers\Web\Admin\PeerPaymentMethodController::class, 'index'])->name('admin.peerPaymentMethods');
                Route::get('peer-trades-payment-methods/create', [\App\Modules\P2P\Http\Controllers\Web\Admin\PeerPaymentMethodController::class, 'create'])->name('admin.peerPaymentMethods.create');
                Route::post('peer-trades-payment-methods', [\App\Modules\P2P\Http\Controllers\Web\Admin\PeerPaymentMethodController::class, 'store'])->name('admin.peerPaymentMethods.store');
                Route::get('peer-trades-payment-methods/{paymentMethod}/edit', [\App\Modules\P2P\Http\Controllers\Web\Admin\PeerPaymentMethodController::class, 'edit'])->name('admin.peerPaymentMethods.edit');
                Route::put('peer-trades-payment-methods/{paymentMethod}', [\App\Modules\P2P\Http\Controllers\Web\Admin\PeerPaymentMethodController::class, 'update'])->name('admin.peerPaymentMethods.update');
                Route::delete('peer-trades-payment-methods/{paymentMethod}', [\App\Modules\P2P\Http\Controllers\Web\Admin\PeerPaymentMethodController::class, 'destroy'])->name('admin.peerPaymentMethods.destroy');

                Route::get('peer-trades-payment-fields/{paymentMethod}/create', [\App\Modules\P2P\Http\Controllers\Web\Admin\PeerPaymentFieldController::class, 'create'])->name('admin.peerPaymentFields.create');
                Route::get('peer-trades-payment-fields/{paymentMethod}', [\App\Modules\P2P\Http\Controllers\Web\Admin\PeerPaymentFieldController::class, 'index'])->name('admin.peerPaymentFields');
                Route::post('peer-trades-payment-fields', [\App\Modules\P2P\Http\Controllers\Web\Admin\PeerPaymentFieldController::class, 'store'])->name('admin.peerPaymentFields.store');
                Route::get('peer-trades-payment-fields/{paymentMethod}/{paymentField}/edit', [\App\Modules\P2P\Http\Controllers\Web\Admin\PeerPaymentFieldController::class, 'edit'])->name('admin.peerPaymentFields.edit');
                Route::put('peer-trades-payment-fields/{paymentField}', [\App\Modules\P2P\Http\Controllers\Web\Admin\PeerPaymentFieldController::class, 'update'])->name('admin.peerPaymentFields.update');
                Route::delete('peer-trades-payment-fields/{paymentField}', [\App\Modules\P2P\Http\Controllers\Web\Admin\PeerPaymentFieldController::class, 'destroy'])->name('admin.peerPaymentFields.destroy');
            });

            /*
             * Stakings
             */
            Route::group(['middleware' => ['role:' . $stakingRoles . '|perm_quantify']], function () use ($stakingRoles) {
                Route::get('staking', [Admin\StakingController::class, 'index'])->name('admin.stakings');
                Route::get('staking/operations', [Admin\StakingOperationsController::class, 'index'])->middleware('role:' . $stakingRoles)->name('admin.stakings.operations');
                Route::get('staking/operations/export', [Admin\StakingOperationsController::class, 'export'])->middleware('role:' . $stakingRoles)->name('admin.stakings.operations.export');
                Route::post('staking/operations/retry', [Admin\StakingOperationsController::class, 'retry'])->middleware('role:' . $stakingRoles)->name('admin.stakings.operations.retry');
            });

            Route::group(['middleware' => ['role:' . $stakingRoles . '|perm_quantify']], function () {
                Route::get('staking/create', [Admin\StakingController::class, 'create'])->name('admin.stakings.create');
                Route::post('staking', [Admin\StakingController::class, 'store'])->name('admin.stakings.store');
                Route::get('staking/{staking}/edit', [Admin\StakingController::class, 'edit'])->name('admin.stakings.edit');
                Route::put('staking/{staking}', [Admin\StakingController::class, 'update'])->name('admin.stakings.update');
                Route::delete('staking/{staking}', [Admin\StakingController::class, 'destroy'])->name('admin.stakings.destroy');
            });

            /*
             * Quantify
             */
            Route::group(['middleware' => ['role:' . $quantifyRoles]], function () {
                Route::get('staking-quantify', [Admin\StakingController::class, 'index'])->name('admin.stakings.quantify');
            });

            /*
             * Cold Storage
             */
            Route::group(['middleware' => ['role:' . $coldStorageRoles]], function () {
                Route::post('cold-storage/google-verify', [Admin\ColdStorageController::class, 'verifyGoogleCode'])->name('admin.cold_storage.google.verify');
                Route::get('custody/transfers/{id}/estimate', [Admin\CustodyController::class, 'estimate'])->whereNumber('id')->name('admin.custody.estimate');
                Route::get('custody/audits/export', [Admin\CustodyController::class, 'exportAudits'])->name('admin.custody.audits.export');
                Route::get('custody/bitcoin', [Admin\BitcoinWalletController::class, 'index'])->name('admin.bitcoin');
                Route::post('custody/bitcoin/{action}', [Admin\BitcoinWalletController::class, 'action'])->where('action','connect|backup|propose|approve|configure|migrate')->name('admin.bitcoin.action');
                Route::get('wallet-recovery', [Admin\WalletRecoveryController::class, 'index'])->name('admin.wallet.recovery');
                Route::post('wallet-recovery/events/{id}/retry', [Admin\WalletRecoveryController::class, 'retry'])->whereNumber('id')->name('admin.wallet.recovery.retry');
                Route::get('custody', [Admin\CustodyController::class, 'index'])->name('admin.custody');
                Route::put('custody/networks/{chain}', [Admin\CustodyController::class, 'network'])->name('admin.custody.network');
                Route::post('custody/permissions', [Admin\CustodyController::class, 'grant'])->name('admin.custody.grant');
                Route::post('custody/{kind}/{id}/{action}', [Admin\CustodyController::class, 'action'])->whereNumber('id')->name('admin.custody.action');
                Route::get('cold-storage', [Admin\ColdStorageController::class, 'index'])->name('admin.cold_storage');
                Route::get('cold-storage/create', [Admin\ColdStorageController::class, 'create'])->name('admin.cold_storage.create');
                Route::post('cold-storage', [Admin\ColdStorageController::class, 'store'])->name('admin.cold_storage.store');
                Route::get('cold-storage/{coldStorage}/edit', [Admin\ColdStorageController::class, 'edit'])->name('admin.cold_storage.edit');
                Route::put('cold-storage/{coldStorage}', [Admin\ColdStorageController::class, 'update'])->name('admin.cold_storage.update');
                Route::put('cold-storage/{coldStorage}/deposit-bank-card', [Admin\ColdStorageController::class, 'updateDepositBankCard'])->name('admin.cold_storage.deposit_bank_card.update');
                Route::delete('cold-storage/{coldStorage}', [Admin\ColdStorageController::class, 'destroy'])->name('admin.cold_storage.destroy');
            });

            /*
             * Vouchers
             */
            Route::group(['middleware' => ['role:' . $voucherRoles]], function () {
                Route::get('vouchers', [Admin\VoucherController::class, 'index'])->name('admin.vouchers');
                Route::get('vouchers/create', [Admin\VoucherController::class, 'create'])->name('admin.vouchers.create');
                Route::post('vouchers', [Admin\VoucherController::class, 'store'])->name('admin.vouchers.store');
                Route::get('vouchers/{voucher}/edit', [Admin\VoucherController::class, 'edit'])->name('admin.vouchers.edit');
                Route::put('vouchers/{voucher}', [Admin\VoucherController::class, 'update'])->name('admin.vouchers.update');
                Route::delete('vouchers/{voucher}', [Admin\VoucherController::class, 'destroy'])->name('admin.vouchers.destroy');
            });

            /*
             * Lending
             */
            Route::group(['middleware' => ['role:superadmin']], function () {
                Route::get('lending', [Admin\LendingController::class, 'index'])->name('admin.lendings');
                Route::get('lending/create', [Admin\LendingController::class, 'create'])->name('admin.lendings.create');
                Route::post('lending', [Admin\LendingController::class, 'store'])->name('admin.lendings.store');

                Route::get('lending/{lending}/collaterals/create', [Admin\LendingController::class, 'collateralCreate'])->name('admin.lendings.collateral.create');
                Route::get('lending/{lending}/collaterals', [Admin\LendingController::class, 'collaterals'])->name('admin.lendings.collaterals');
                Route::get('lending/{lending}/collaterals/edit/{currency}', [Admin\LendingController::class, 'collateralEdit'])->name('admin.lendings.collateral.edit');
                Route::put('lending/{lending}/collaterals/{currency}', [Admin\LendingController::class, 'collateralUpdate'])->name('admin.lendings.collateral.update');
                Route::delete('lending/collaterals/{currency}', [Admin\LendingController::class, 'collateralDestroy'])->name('admin.lendings.collateral.destroy');
                Route::post('lending/collaterals', [Admin\LendingController::class, 'collateralStore'])->name('admin.lendings.collateral.store');

                Route::get('lending/{lending}/edit', [Admin\LendingController::class, 'edit'])->name('admin.lendings.edit');
                Route::put('lending/{lending}', [Admin\LendingController::class, 'update'])->name('admin.lendings.update');
                Route::delete('lending/{lending}', [Admin\LendingController::class, 'destroy'])->name('admin.lendings.destroy');
            });

            /*
             * Options Templates
             */
            Route::group(['middleware' => ['role:' . $optionTemplateRoles]], function () {
                Route::get('options-templates', [Admin\OptionTemplateController::class, 'index'])->name('admin.options.templates');
                Route::get('options-templates/create', [Admin\OptionTemplateController::class, 'create'])->name('admin.options.templates.create');
                Route::post('options-templates', [Admin\OptionTemplateController::class, 'store'])->name('admin.options.templates.store');
                Route::get('options-templates/{optionTemplate}/edit', [Admin\OptionTemplateController::class, 'edit'])->name('admin.options.templates.edit');
                Route::put('options-templates/{optionTemplate}', [Admin\OptionTemplateController::class, 'update'])->name('admin.options.templates.update');
                Route::delete('options-templates/{optionTemplate}', [Admin\OptionTemplateController::class, 'destroy'])->name('admin.options.templates.destroy');
            });

            /*
             * Launchpads
             */
            Route::group(['middleware' => ['role:' . $launchpadRoles]], function () {
                Route::get('launchpads', [Admin\LaunchpadController::class, 'index'])->name('admin.launchpads');
                Route::get('launchpads/create', [Admin\LaunchpadController::class, 'create'])->name('admin.launchpads.create');
                Route::post('launchpads', [Admin\LaunchpadController::class, 'store'])->name('admin.launchpads.store');
                Route::get('launchpads/{launchpad}/edit', [Admin\LaunchpadController::class, 'edit'])->name('admin.launchpads.edit');
                Route::put('launchpads/{launchpad}', [Admin\LaunchpadController::class, 'update'])->name('admin.launchpads.update');
                Route::delete('launchpads/{launchpad}', [Admin\LaunchpadController::class, 'destroy'])->name('admin.launchpads.destroy');
            });

            /*
             * Guess Games
             */
            Route::group(['middleware' => ['role:superadmin']], function () {
                Route::get('guess-games', [Admin\GuessGameController::class, 'index'])->name('admin.guess-games');
                Route::get('guess-games/create', [Admin\GuessGameController::class, 'create'])->name('admin.guess-games.create');
                Route::post('guess-games', [Admin\GuessGameController::class, 'store'])->name('admin.guess-games.store');

                Route::get('guess-orders', [Admin\GuessOrderController::class, 'index'])->name('admin.guess-orders');
                Route::get('guess-orders/{id}', [Admin\GuessOrderController::class, 'show'])->name('admin.guess-orders.show');
            });

            /*
             * File Upload
             */
            Route::group(['middleware' => ['role:' . implode('|', \App\Support\AdminAccess::EXCHANGE_ROLES)]], function () {
                Route::post('/upload', [Admin\FileController::class, 'upload'])->name('file-upload');
                Route::delete('/upload', [Admin\FileController::class, 'delete'])->name('file-delete');
            });
        });
    });
});

// Includes Modules
require_once app_path('Modules/P2P/Routes/admin.php');

require_once __DIR__ . '/common.php';
