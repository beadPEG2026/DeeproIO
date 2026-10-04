<?php

namespace App\Http\Controllers\Api\v1\Gateways;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Wallet\FiatPayeerDepositFormRequest;
use App\Models\Wallet\Wallet;
use App\Repositories\Currency\CurrencyRepository;
use App\Services\Wallet\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UnlimitController extends Controller
{

    /**
     * 汇率直接从 settings 表读取：
     * key = unlimit.exchange_rate
     *
     * 例如：
     * value = 150 表示 1 USDT = 150 法币。
     */
    protected function getLocalExchangeRate(): float
    {
        return $this->getNumericSettingValue('unlimit.exchange_rate', 1);
    }

    /**
     * 手续费直接从 settings 表读取：
     * key = unlimit.processing_fee
     *
     * 例如：
     * value = 8 表示 8%。
     */
    protected function getLocalFeePercent(): float
    {
        return $this->getNumericSettingValue('unlimit.processing_fee', 0);
    }

    /**
     * The local sell/off-ramp keeps its original Unlimit processing fee.
     * Buy/on-ramp fees use the same setting.
     */
    protected function getLocalWithdrawalFeePercent(): float
    {
        return $this->getLocalFeePercent();
    }

    protected function getNumericSettingValue(string $key, float $default = 0): float
    {
        $value = Cache::remember('settings:numeric:'.$key, now()->addSeconds(60), function () use ($key) {
            try {
                return DB::table('settings')
                    ->where('key', $key)
                    ->value('value');
            } catch (\Throwable $e) {
                return null;
            }
        });

        if ($value === null || $value === '') {
            return $default;
        }

        $value = str_replace(',', '', (string) $value);

        if (!is_numeric($value)) {
            return $default;
        }

        return (float) $value;
    }


    public function ipn(Request $request)
    {
        return response()->json([
            'status' => 'ok',
            'mode' => 'local',
        ]);
    }
public function depositBankCard()
{
    $card = \Illuminate\Support\Facades\DB::table('fiat_deposit_instructions')
        ->where('id', 1)
        ->first();

    if (!$card || !$card->status || empty($card->address)) {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'Deposit bank card is not configured.',
        ]);
    }

    return response()->json([
        'success' => true,
        'data' => [
            'id' => $card->id,
            'address' => $card->address,
        ],
    ]);
}
    /**
     * 获取支持买入的加密货币
     */
    public function getCurrencies(FiatPayeerDepositFormRequest $request)
    {
        $currencyRepository = new CurrencyRepository();

        $fiatAmount = (float) $request->get('amount', 0);

        $currencyCollection = $currencyRepository->all(false, false, ['file'], 'coin');
        $fiatCollection = $currencyRepository->all(false, false, ['file'], 'fiat');

        $allowedFiatSymbols = $this->getAllowedBuyFiatSymbols($fiatCollection);

        $currencies = [];

        foreach ($currencyCollection as $currency) {
            $pairs = $this->normalizeAllowedSymbols($currency->allowed_buy_fiats ?? []);

            if (!empty($allowedFiatSymbols)) {
                $pairs = array_values(array_filter($pairs, function ($pair) use ($allowedFiatSymbols) {
                    return isset($allowedFiatSymbols[$pair]);
                }));
            }

            if (empty($pairs)) {
                continue;
            }

            $priceInUsdt = $this->getCurrencyPriceInUsdt($currency, $currencyRepository);

            if ($priceInUsdt <= 0) {
                continue;
            }

            $priceInFiat = $this->convertUsdtToFiat($priceInUsdt);

            $estimatedCryptoAmount = 0;

            if ($fiatAmount > 0 && $priceInFiat > 0) {
                $estimatedCryptoAmount = $fiatAmount / $priceInFiat;
            }

            $currencies[$currency->id] = [
                'name' => $currency->symbol,
                'symbol' => $currency->symbol,
                'id' => $currency->id,
                'logo' => $currency->logo_path,
                'pairs' => $pairs,
                'price_in_usdt' => math_formatter($priceInUsdt, 8, '.', ''),
                'usdt_to_fiat_rate' => math_formatter($this->getLocalExchangeRate(), 8, '.', ''),
                'price_in_fiat' => math_formatter($priceInFiat, 8, '.', ''),
                'estimated_crypto_amount' => math_formatter($estimatedCryptoAmount, 8, '.', ''),
            ];
        }

        return response()->json($currencies);
    }

    /**
     * 获取支持买币的法币
     */
    public function getFiatCurrencies(FiatPayeerDepositFormRequest $request)
    {
        $currencyRepository = new CurrencyRepository();
        $currencyCollection = $currencyRepository->all(false, false, ['file'], 'fiat');

        $currencies = [];

        foreach ($currencyCollection as $currency) {
            if (!$currency->allowed_buy_fiat) {
                continue;
            }

            $currencies[$currency->id] = [
                'name' => $currency->symbol,
                'symbol' => $currency->symbol,
                'id' => $currency->id,
                'logo' => $currency->logo_path,
                'usdt_to_fiat_rate' => math_formatter($this->getLocalExchangeRate(), 8, '.', ''),
            ];
        }

        return response()->json($currencies);
    }

    /**
     * 本地买入报价
     */
    public function quote(Request $request)
    {
        $data = $request->validate([
            'base_currency_id' => 'required|integer',
            'quote_currency_id' => 'required|integer',
            'amount' => 'required|numeric|min:0.00000001',
            'payment' => 'sometimes|string',
            'payment_method' => 'sometimes|string',
            'region' => 'sometimes|string|size:2',
            'bank_account_id' => 'sometimes|integer',
            'bank_account_name' => 'sometimes|string',
        ]);

        $currencyRepository = new CurrencyRepository();

        $crypto = $currencyRepository->get($data['base_currency_id']);
        $fiat = $currencyRepository->get($data['quote_currency_id']);

        if (!$crypto || !$fiat) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid currency selection.',
            ], 422);
        }

        if (!$this->isFiatAllowedForBuy($fiat)) {
            return response()->json([
                'success' => false,
                'error' => 'Selected fiat currency is not allowed for buying.',
            ], 422);
        }

        if (!$this->isCryptoAllowedForBuy($crypto, $fiat->symbol)) {
            return response()->json([
                'success' => false,
                'error' => 'Selected crypto is not allowed for this fiat currency.',
            ], 422);
        }

        $quote = $this->buildOnRampQuote($crypto, $fiat, (float) $data['amount'], $currencyRepository);

        return response()->json([
            'success' => true,
            'mode' => 'local',
            'data' => $quote,
        ]);
    }

    /**
     * checkout 入口。
     *
     * 兼容两种前端请求：
     *
     * 买入：
     * base_currency_id / quote_currency_id / amount / payment
     *
     * 卖出：
     * crypto_currency_id / fiat_currency_id / amount / payout_method
     */
    public function checkout(Request $request)
    {
        if (
            $request->has('crypto_currency_id') ||
            $request->has('fiat_currency_id') ||
            $request->has('payout_method')
        ) {
            return $this->handleSellCheckout($request);
        }

        return $this->handleBuyCheckout($request);
    }

    /**
     * 买入 checkout：
     * 写入 fiat_deposits。
     */
    protected function handleBuyCheckout(Request $request)
    {
        $data = $request->validate([
            'base_currency_id' => 'required|integer',
            'quote_currency_id' => 'required|integer',
            'amount' => 'required|numeric|min:0.00000001',
            'payment' => 'sometimes|string',
            'payment_method' => 'sometimes|string',
            'region' => 'sometimes|string|size:2',
            'bank_account_id' => 'sometimes|integer',
            'bank_account_name' => 'sometimes|string',
        ]);

        $user = $request->user();

        if (!$user || !$user->kyc_verified) {
            return response()->json([
                'success' => false,
                'error' => 'User must complete KYC verification before using this feature.',
                'code' => 'user_not_kyc_verified',
            ], 503);
        }

        $currencyRepository = new CurrencyRepository();

        $crypto = $currencyRepository->get($data['base_currency_id']);
        $fiat = $currencyRepository->get($data['quote_currency_id']);

        if (!$crypto || !$fiat) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid currency selection.',
            ], 422);
        }

        if (!$this->isFiatAllowedForBuy($fiat)) {
            return response()->json([
                'success' => false,
                'error' => 'Selected fiat currency is not allowed for buying.',
            ], 422);
        }

        if (!$this->isCryptoAllowedForBuy($crypto, $fiat->symbol)) {
            return response()->json([
                'success' => false,
                'error' => 'Selected crypto is not allowed for this fiat currency.',
            ], 422);
        }

        $fiatAmount = (float) $data['amount'];
        $orderId = $this->makeOrderId('buy');

        $quote = $this->buildOnRampQuote($crypto, $fiat, $fiatAmount, $currencyRepository);

        $feeFiat = $fiatAmount * ($this->getLocalFeePercent() / 100);

        $paymentMethod = $data['payment_method'] ?? $data['payment'] ?? 'LOCAL';
        $bankAccountId = $data['bank_account_id'] ?? null;
        $bankAccountSnapshot = $this->getBankAccountSnapshot($user, $bankAccountId);

        $note = [
            'source' => 'local_onramp',
            'order_id' => $orderId,

            'crypto_currency_id' => $crypto->id,
            'crypto_symbol' => $crypto->symbol,

            'fiat_currency_id' => $fiat->id,
            'fiat_symbol' => $fiat->symbol,

            'fiat_amount' => $fiatAmount,
            'estimated_crypto_amount' => $quote['cryptoAmount'] ?? $quote['amountOut'] ?? '0',

            'price_in_usdt' => $quote['priceInUsdt'] ?? null,
            'price_in_fiat' => $quote['priceInFiat'] ?? null,
            'exchange_rate' => $quote['exchangeRate'] ?? null,
            'usdt_to_fiat_rate' => $quote['usdtToFiatRate'] ?? null,

            'fee_percent' => $this->getLocalFeePercent(),
            'fee_amount' => $feeFiat,

            'payment' => $paymentMethod,
            'payment_method' => $paymentMethod,
            'bank_account_id' => $bankAccountId,
            'bank_account_name' => $data['bank_account_name'] ?? ($bankAccountSnapshot['display_name'] ?? null),
            'bank_account' => $bankAccountSnapshot,
            'region' => $data['region'] ?? null,
        ];

        try {
            $depositId = DB::table('fiat_deposits')->insertGetId(
                $this->filterTableColumns('fiat_deposits', [
                    'user_id' => $user->id,
                    'currency_id' => $fiat->id,
                    'amount' => $fiatAmount,
                    'fee' => $feeFiat,
                    'status' => defined('FIAT_DEPOSIT_PENDING') ? FIAT_DEPOSIT_PENDING : 0,
                    'type' => $paymentMethod,
                    'payment_method' => $paymentMethod,
                    'bank_account_id' => $bankAccountId,
                    'bank_account_snapshot' => $bankAccountSnapshot ? json_encode($bankAccountSnapshot, JSON_UNESCAPED_UNICODE) : null,
                    'deposit_id' => $orderId,
                    'receipt_id' => null,
                    'note' => json_encode($note, JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        } catch (\Throwable $e) {
            Log::error('Local onramp order insert failed', [
                'message' => $e->getMessage(),
                'user_id' => $user->id,
                'order_id' => $orderId,
                'payload' => $data,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to create fiat deposit order.',
                'debug' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }

        return response()->json([
            'success' => true,
            'mode' => 'local',
            'local_checkout' => true,
            'message' => 'Local buy order created successfully.',
            'redirect_url' => route('wallets.deposit.payment.success'),
            'order_id' => $orderId,
            'deposit_id' => $depositId,
            'data' => array_merge($quote, [
                'feePercent' => $this->getLocalFeePercent(),
                'feeAmount' => math_formatter($feeFiat, 2, '.', ''),
                'paymentMethod' => $paymentMethod,
                'bankAccountId' => $bankAccountId,
                'bankAccountName' => $bankAccountSnapshot['display_name'] ?? ($data['bank_account_name'] ?? null),
                'database_record_created' => true,
            ]),
        ]);
    }

    /**
     * 卖出 checkout：
     * 当前前端请求 checkout 时传 crypto_currency_id / fiat_currency_id。
     * 这里写入 fiat_withdrawals，并锁定用户加密货币余额。
     */
    protected function handleSellCheckout(Request $request)
    {
        $data = $request->validate([
            'crypto_currency_id' => 'required|integer',
            'fiat_currency_id' => 'required|integer',
            'amount' => 'required|numeric|min:0.00000001',
            'payout_method' => 'sometimes|string',
            'region' => 'sometimes|string|size:2',
            'bank_account_id' => 'sometimes|integer',
        ]);

        $user = $request->user();

        if (!$user || !$user->kyc_verified) {
            return response()->json([
                'success' => false,
                'error' => 'User must complete KYC verification before using this feature.',
                'code' => 'user_not_kyc_verified',
            ], 503);
        }

        $currencyRepository = new CurrencyRepository();

        $crypto = $currencyRepository->get($data['crypto_currency_id']);
        $fiat = $currencyRepository->get($data['fiat_currency_id']);

        if (!$crypto || !$fiat) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid currency selection.',
            ], 422);
        }

        if (!$this->isCryptoAllowedForSell($crypto)) {
            return response()->json([
                'success' => false,
                'error' => 'Selected crypto is not allowed for selling.',
            ], 422);
        }

        if (!$this->isFiatAllowedForSell($fiat)) {
            return response()->json([
                'success' => false,
                'error' => 'Selected fiat currency is not allowed for receiving.',
            ], 422);
        }

        $cryptoAmount = (float) $data['amount'];
        $orderId = $this->makeOrderId('sell');

        $quote = $this->buildOffRampQuote($crypto, $fiat, $cryptoAmount, $currencyRepository);

        $grossFiatAmount = (float) ($quote['grossFiatAmount'] ?? $quote['fiatAmount'] ?? 0);
        $finalFiatAmount = (float) ($quote['fiatAmount'] ?? $quote['amountOut'] ?? 0);

        $withdrawalFeePercent = $this->getLocalWithdrawalFeePercent();
        $feeCrypto = $cryptoAmount * ($withdrawalFeePercent / 100);
        $feeFiat = $grossFiatAmount * ($withdrawalFeePercent / 100);

        $payoutMethod = $data['payout_method'] ?? 'SEPA';

        $note = [
            'source' => 'local_offramp',
            'order_id' => $orderId,

            'crypto_currency_id' => $crypto->id,
            'crypto_symbol' => $crypto->symbol,
            'crypto_amount' => $cryptoAmount,

            'fiat_currency_id' => $fiat->id,
            'fiat_symbol' => $fiat->symbol,
            'gross_fiat_amount' => $grossFiatAmount,
            'final_fiat_amount' => $finalFiatAmount,

            'price_in_usdt' => $quote['priceInUsdt'] ?? null,
            'price_in_fiat' => $quote['priceInFiat'] ?? null,
            'exchange_rate' => $quote['exchangeRate'] ?? null,
            'usdt_to_fiat_rate' => $quote['usdtToFiatRate'] ?? null,

            'fee_percent' => $withdrawalFeePercent,
            'fee_crypto_amount' => $feeCrypto,
            'fee_fiat_amount' => $feeFiat,

            'payout_method' => $payoutMethod,
            'bank_account_id' => $data['bank_account_id'] ?? null,
            'region' => $data['region'] ?? null,
        ];

        try {
            $withdrawalId = DB::transaction(function () use (
                $user,
                $crypto,
                $fiat,
                $cryptoAmount,
                $feeCrypto,
                $payoutMethod,
                $orderId,
                $note
            ) {
                $wallet = Wallet::query()
                    ->where('user_id', $user->id)
                    ->where('currency_id', $crypto->id)
                    ->lockForUpdate()
                    ->first();

                if (!$wallet) {
                    throw new \RuntimeException('INSUFFICIENT_BALANCE');
                }

                $sourceAccount = null;

                if ((float) $wallet->balance_in_trade >= $cryptoAmount) {
                    $sourceAccount = 'trade';
                } elseif ((float) $wallet->balance_in_wallet >= $cryptoAmount) {
                    $sourceAccount = 'wallet';
                }

                if (!$sourceAccount) {
                    throw new \RuntimeException('INSUFFICIENT_BALANCE');
                }

                $note['locked_from_account'] = $sourceAccount;

                $withdrawalData = $this->filterTableColumns('fiat_withdrawals', [
                    'user_id' => $user->id,

                    /*
                     * 这里存加密货币 ID。
                     * 因为卖出时需要锁定和扣除的是 crypto 钱包余额。
                     */
                    'currency_id' => $crypto->id,

                    /*
                     * amount 记录卖出的加密货币数量。
                     */
                    'amount' => $cryptoAmount,

                    /*
                     * fee 记录 crypto 维度手续费。
                     */
                    'fee' => $feeCrypto,

                    'status' => defined('FIAT_WITHDRAWAL_PENDING') ? FIAT_WITHDRAWAL_PENDING : 0,
                    'type' => $payoutMethod,
                    'withdrawal_id' => $orderId,

                    'account_holder_name' => $user->name ?? $user->email,
                    'account_holder_address' => $payoutMethod,

                    'note' => json_encode($note, JSON_UNESCAPED_UNICODE),

                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $withdrawalId = DB::table('fiat_withdrawals')->insertGetId($withdrawalData);

                $walletService = new WalletService();

                /*
                 * 锁定卖出的 crypto：
                 * 从 trade 或 wallet 转到 withdraw。
                 */
                $walletService->decrease($wallet, $cryptoAmount, $sourceAccount);
                $walletService->increase($wallet, $cryptoAmount, 'withdraw');

                return $withdrawalId;
            });
        } catch (\Throwable $e) {
            Log::error('Local offramp order insert failed', [
                'message' => $e->getMessage(),
                'user_id' => $user->id,
                'order_id' => $orderId,
                'payload' => $data,
                'note' => $note,
            ]);

            if ($e->getMessage() === 'INSUFFICIENT_BALANCE') {
                return response()->json([
                    'success' => false,
                    'error' => 'Insufficient balance. You need at least ' . $cryptoAmount . ' ' . $crypto->symbol . ' to proceed.',
                ], 422);
            }

            return response()->json([
                'success' => false,
                'error' => 'Failed to create fiat withdrawal order.',
                'debug' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }

        return response()->json([
            'success' => true,
            'mode' => 'local',
            'local_checkout' => true,
            'message' => 'Local sell order created successfully.',
            'redirect_url' => route('wallets.deposit.payment.success'),
            'order_id' => $orderId,
            'withdrawal_id' => $withdrawalId,
            'data' => array_merge($quote, [
                'feePercent' => $withdrawalFeePercent,
                'feeCryptoAmount' => math_formatter($feeCrypto, 8, '.', ''),
                'feeFiatAmount' => math_formatter($feeFiat, 2, '.', ''),
                'database_record_created' => true,
            ]),
        ]);
    }

    public function config(Request $request)
    {
        $currencyRepository = new CurrencyRepository();

        $cryptoCollection = $currencyRepository->all(false, false, ['file'], 'coin');
        $fiatCollection = $currencyRepository->all(false, false, ['file'], 'fiat');

        $cryptos = [];
        $fiats = [];

        foreach ($cryptoCollection as $currency) {
            $cryptos[] = $currency->symbol;
        }

        foreach ($fiatCollection as $currency) {
            if ($currency->allowed_buy_fiat || $currency->allowed_sell_fiat) {
                $fiats[] = $currency->symbol;
            }
        }

        return response()->json([
            'success' => true,
            'mode' => 'local',
            'data' => [
                'cryptos' => array_values(array_unique($cryptos)),
                'fiats' => array_values(array_unique($fiats)),
                'payments' => [
                    [
                        'id' => 'LOCAL',
                        'name' => 'Local Payment',
                    ],
                    [
                        'id' => 'BANKCARD',
                        'name' => 'Bank Card',
                    ],
                    [
                        'id' => 'SEPA',
                        'name' => 'SEPA Bank Transfer',
                    ],
                ],
                'rate' => [
                    'USDT' => $this->getLocalExchangeRate(),
                ],
            ],
        ]);
    }

    public function quoteOffRamp(Request $request)
    {
        $data = $request->validate([
            'base_currency_id' => 'required|integer',
            'quote_currency_id' => 'required|integer',
            'amount' => 'required|numeric|min:0.00000001',
            'payment' => 'sometimes|string',
            'region' => 'sometimes|string|size:2',
        ]);

        $request->merge([
            'crypto_currency_id' => $data['base_currency_id'],
            'fiat_currency_id' => $data['quote_currency_id'],
            'payout_method' => $data['payment'] ?? 'SEPA',
        ]);

        return $this->offrampQuote($request);
    }

    public function payout(Request $request)
    {
        $data = $request->validate([
            'base_currency_id' => 'required|integer',
            'quote_currency_id' => 'required|integer',
            'amount' => 'required|numeric|min:0.00000001',
            'payment' => 'sometimes|string',
            'region' => 'sometimes|string|size:2',
        ]);

        $request->merge([
            'crypto_currency_id' => $data['base_currency_id'],
            'fiat_currency_id' => $data['quote_currency_id'],
            'payout_method' => $data['payment'] ?? 'SEPA',
        ]);

        return $this->handleSellCheckout($request);
    }

    public function getOfframpCurrencies(Request $request)
    {
        $currencyRepository = new CurrencyRepository();
        $currencyCollection = $currencyRepository->all(false, false, ['file'], 'coin');

        $currencies = [];

        foreach ($currencyCollection as $currency) {
            if (!$currency->allowed_sell_crypto) {
                continue;
            }

            $priceInUsdt = $this->getCurrencyPriceInUsdt($currency, $currencyRepository);

            if ($priceInUsdt <= 0) {
                continue;
            }

            $currencies[$currency->id] = [
                'name' => $currency->symbol,
                'symbol' => $currency->symbol,
                'id' => $currency->id,
                'logo' => $currency->logo_path,
                'price_in_usdt' => math_formatter($priceInUsdt, 8, '.', ''),
                'price_in_fiat' => math_formatter($this->convertUsdtToFiat($priceInUsdt), 8, '.', ''),
            ];
        }

        return response()->json($currencies);
    }

    public function getOfframpFiatCurrencies(Request $request)
    {
        $currencyRepository = new CurrencyRepository();
        $currencyCollection = $currencyRepository->all(false, false, ['file'], 'fiat');

        $currencies = [];

        foreach ($currencyCollection as $currency) {
            if (!$currency->allowed_sell_fiat) {
                continue;
            }

            $currencies[$currency->id] = [
                'name' => $currency->symbol,
                'symbol' => $currency->symbol,
                'id' => $currency->id,
                'logo' => $currency->logo_path,
                'usdt_to_fiat_rate' => math_formatter($this->getLocalExchangeRate(), 8, '.', ''),
            ];
        }

        return response()->json($currencies);
    }

    public function getPayoutMethods(Request $request)
{
    $methods = [];

    $user = auth()->user();

    if (!$user) {
        return response()->json($methods);
    }

    if (!$this->tableExists('bank_accounts')) {
        return response()->json($methods);
    }

    $userColumn = $this->findBankAccountUserColumn();

    if (!$userColumn) {
        return response()->json($methods);
    }

    $bankAccountsQuery = DB::table('bank_accounts')
        ->where($userColumn, $user->id);

    /*
     * 只显示审核通过的银行卡：
     * status_type = 0 待审核
     * status_type = 1 通过
     * status_type = 2 拒绝
     */
    if ($this->columnExists('bank_accounts', 'status_type')) {
        $bankAccountsQuery->where('status_type', 1);
    } elseif ($this->columnExists('bank_accounts', 'status')) {
        /*
         * 兼容旧字段：
         * 如果没有 status_type，就用原 status 判断启用。
         */
        $bankAccountsQuery->where(function ($query) {
            $query->where('status', 1)
                ->orWhere('status', true)
                ->orWhere('status', '1')
                ->orWhere('status', 'active');
        });
    }

    $bankAccounts = $bankAccountsQuery
        ->orderByDesc('id')
        ->get();

    if ($bankAccounts->isEmpty()) {
        return response()->json($methods);
    }

    $bankMethods = [];

    foreach ($bankAccounts as $account) {
        $iban = $account->iban ?? $account->account_number ?? '';
        $bic = $account->bic ?? $account->swift ?? '';

        $firstName = $account->first_name ?? '';
        $lastName = $account->last_name ?? '';
        $holderName = trim($firstName . ' ' . $lastName);

        if ($holderName === '') {
            $holderName = $account->account_holder_name
                ?? $account->account_name
                ?? $account->name
                ?? '';
        }

        $maskedIban = $this->maskBankNumber($iban);

        $nameParts = [];

        if ($holderName) {
            $nameParts[] = $holderName;
        }

        if ($maskedIban) {
            $nameParts[] = $maskedIban;
        }

        if ($bic) {
            $nameParts[] = $bic;
        }

        $displayName = !empty($nameParts)
            ? implode(' - ', $nameParts)
            : 'Bank Account';

        $bankMethods[] = [
            'id' => 'SEPA',
            'name' => $displayName,
            'bank_account_id' => $account->id ?? null,
            'iban' => $iban,
            'bic' => $bic,
        ];
    }

    return response()->json($bankMethods);
}

    public function offrampQuote(Request $request)
    {
        $data = $request->validate([
            'crypto_currency_id' => 'required|integer',
            'fiat_currency_id' => 'required|integer',
            'amount' => 'required|numeric|min:0.00000001',
            'payout_method' => 'sometimes|string',
            'region' => 'sometimes|string|size:2',
        ]);

        $currencyRepository = new CurrencyRepository();

        $crypto = $currencyRepository->get($data['crypto_currency_id']);
        $fiat = $currencyRepository->get($data['fiat_currency_id']);

        if (!$crypto || !$fiat) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid currency selection.',
            ], 422);
        }

        if (!$this->isCryptoAllowedForSell($crypto)) {
            return response()->json([
                'success' => false,
                'error' => 'Selected crypto is not allowed for selling.',
            ], 422);
        }

        if (!$this->isFiatAllowedForSell($fiat)) {
            return response()->json([
                'success' => false,
                'error' => 'Selected fiat currency is not allowed for receiving.',
            ], 422);
        }

        $quote = $this->buildOffRampQuote($crypto, $fiat, (float) $data['amount'], $currencyRepository);

        return response()->json([
            'success' => true,
            'mode' => 'local',
            'data' => $quote,
        ]);
    }

    public function offrampCheckout(Request $request)
    {
        return $this->handleSellCheckout($request);
    }

    protected function getBankAccountSnapshot($user, $bankAccountId): ?array
    {
        if (!$user || !$bankAccountId) {
            return null;
        }

        if (!$this->tableExists('bank_accounts')) {
            return null;
        }

        $userColumn = $this->findBankAccountUserColumn();

        if (!$userColumn) {
            return null;
        }

        $account = DB::table('bank_accounts')
            ->where('id', $bankAccountId)
            ->where($userColumn, $user->id)
            ->first();

        if (!$account) {
            return null;
        }

        $iban = $account->iban ?? $account->account_number ?? '';
        $bic = $account->bic ?? $account->swift ?? '';

        $firstName = $account->first_name ?? '';
        $lastName = $account->last_name ?? '';
        $holderName = trim($firstName . ' ' . $lastName);

        if ($holderName === '') {
            $holderName = $account->account_holder_name
                ?? $account->account_name
                ?? $account->name
                ?? '';
        }

        $maskedIban = $this->maskBankNumber($iban);

        $nameParts = [];

        if ($holderName) {
            $nameParts[] = $holderName;
        }

        if ($maskedIban) {
            $nameParts[] = $maskedIban;
        }

        if ($bic) {
            $nameParts[] = $bic;
        }

        return [
            'id' => $account->id ?? null,
            'holder_name' => $holderName,
            'iban' => $iban,
            'masked_iban' => $maskedIban,
            'bic' => $bic,
            'display_name' => !empty($nameParts) ? implode(' - ', $nameParts) : 'Bank Account',
        ];
    }

    protected function buildOnRampQuote($crypto, $fiat, float $fiatAmount, CurrencyRepository $currencyRepository): array
    {
        $priceInUsdt = $this->getCurrencyPriceInUsdt($crypto, $currencyRepository);
        $priceInFiat = $this->convertUsdtToFiat($priceInUsdt);

        $feeFiat = $fiatAmount * ($this->getLocalFeePercent() / 100);
        $netFiatAmount = max(0, $fiatAmount - $feeFiat);

        $cryptoAmount = 0;

        if ($priceInFiat > 0) {
            $cryptoAmount = $netFiatAmount / $priceInFiat;
        }

        return [
            'type' => 'onramp',
            'amountIn' => math_formatter($fiatAmount, 2, '.', ''),
            'amountOut' => math_formatter($cryptoAmount, 8, '.', ''),
            'fiatAmount' => math_formatter($netFiatAmount, 2, '.', ''),
            'grossFiatAmount' => math_formatter($fiatAmount, 2, '.', ''),
            'cryptoAmount' => math_formatter($cryptoAmount, 8, '.', ''),

            'crypto' => $crypto->symbol,
            'fiat' => $fiat->symbol,
            'crypto_currency_id' => $crypto->id,
            'fiat_currency_id' => $fiat->id,

            'priceInUsdt' => math_formatter($priceInUsdt, 8, '.', ''),
            'priceInFiat' => math_formatter($priceInFiat, 8, '.', ''),
            'exchangeRate' => math_formatter($priceInFiat, 8, '.', ''),
            'usdtToFiatRate' => math_formatter($this->getLocalExchangeRate(), 8, '.', ''),

            'processingFee' => math_formatter($feeFiat, 2, '.', ''),
            'networkFee' => math_formatter(0, 2, '.', ''),
            'fee' => math_formatter($feeFiat, 2, '.', ''),
            'feePercent' => $this->getLocalFeePercent(),
            'total' => math_formatter($fiatAmount, 2, '.', ''),
        ];
    }

    protected function buildOffRampQuote($crypto, $fiat, float $cryptoAmount, CurrencyRepository $currencyRepository): array
    {
        $priceInUsdt = $this->getCurrencyPriceInUsdt($crypto, $currencyRepository);
        $priceInFiat = $this->convertUsdtToFiat($priceInUsdt);

        $grossFiatAmount = $cryptoAmount * $priceInFiat;
        $withdrawalFeePercent = $this->getLocalWithdrawalFeePercent();
        $feeFiat = $grossFiatAmount * ($withdrawalFeePercent / 100);
        $finalFiatAmount = max(0, $grossFiatAmount - $feeFiat);

        return [
            'type' => 'offramp',
            'amountIn' => math_formatter($cryptoAmount, 8, '.', ''),
            'amountOut' => math_formatter($finalFiatAmount, 2, '.', ''),
            'cryptoAmount' => math_formatter($cryptoAmount, 8, '.', ''),
            'fiatAmount' => math_formatter($finalFiatAmount, 2, '.', ''),
            'grossFiatAmount' => math_formatter($grossFiatAmount, 2, '.', ''),

            'crypto' => $crypto->symbol,
            'fiat' => $fiat->symbol,
            'crypto_currency_id' => $crypto->id,
            'fiat_currency_id' => $fiat->id,

            'priceInUsdt' => math_formatter($priceInUsdt, 8, '.', ''),
            'priceInFiat' => math_formatter($priceInFiat, 8, '.', ''),
            'exchangeRate' => math_formatter($priceInFiat, 8, '.', ''),
            'usdtToFiatRate' => math_formatter($this->getLocalExchangeRate(), 8, '.', ''),

            'processingFee' => math_formatter($feeFiat, 2, '.', ''),
            'networkFee' => math_formatter(0, 2, '.', ''),
            'fee' => math_formatter($feeFiat, 2, '.', ''),
            'feePercent' => $withdrawalFeePercent,
            'total' => math_formatter($finalFiatAmount, 2, '.', ''),
        ];
    }

    protected function getCurrencyPriceInUsdt($currency, CurrencyRepository $currencyRepository): float
    {
        $symbol = strtoupper((string) $currency->symbol);

        if ($symbol === 'USDT') {
            return 1;
        }

        $price = (float) $currencyRepository->currencyPriceInUsd($currency);

        return $price > 0 ? $price : 0;
    }

    protected function convertUsdtToFiat(float $amountInUsdt): float
    {
        return $amountInUsdt * $this->getLocalExchangeRate();
    }

    protected function getAllowedBuyFiatSymbols($fiatCollection): array
    {
        $allowedFiatSymbols = [];

        foreach ($fiatCollection as $fiat) {
            if ($fiat->allowed_buy_fiat) {
                $allowedFiatSymbols[strtoupper((string) $fiat->symbol)] = true;
            }
        }

        return $allowedFiatSymbols;
    }

    protected function normalizeAllowedSymbols($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(function ($symbol) {
            $symbol = strtoupper(trim((string) $symbol));
            return $symbol !== '' ? $symbol : null;
        }, $value))));
    }

    protected function isFiatAllowedForBuy($fiat): bool
    {
        return (bool) ($fiat->allowed_buy_fiat ?? false);
    }

    protected function isFiatAllowedForSell($fiat): bool
    {
        return (bool) ($fiat->allowed_sell_fiat ?? false);
    }

    protected function isCryptoAllowedForBuy($crypto, string $fiatSymbol): bool
    {
        $pairs = $this->normalizeAllowedSymbols($crypto->allowed_buy_fiats ?? []);

        if (empty($pairs)) {
            return false;
        }

        return in_array(strtoupper($fiatSymbol), $pairs, true);
    }

    protected function isCryptoAllowedForSell($crypto): bool
    {
        return (bool) ($crypto->allowed_sell_crypto ?? false);
    }

    protected function makeOrderId(string $type): string
    {
        return now()->timestamp . '-' . $type . '-' . substr(bin2hex(random_bytes(6)), 0, 12);
    }

    protected function tableExists(string $table): bool
    {
        return (bool) Cache::remember('schema:table-exists:'.$table, now()->addMinutes(10), function () use ($table) {
            try {
                return DB::getSchemaBuilder()->hasTable($table);
            } catch (\Throwable $e) {
                return false;
            }
        });
    }

    protected function columnExists(string $table, string $column): bool
    {
        return (bool) Cache::remember('schema:column-exists:'.$table.':'.$column, now()->addMinutes(10), function () use ($table, $column) {
            try {
                return DB::getSchemaBuilder()->hasColumn($table, $column);
            } catch (\Throwable $e) {
                return false;
            }
        });
    }

    protected function filterTableColumns(string $table, array $data): array
    {
        $columns = Cache::remember('schema:columns:'.$table, now()->addMinutes(10), function () use ($table) {
            try {
                return DB::getSchemaBuilder()->getColumnListing($table);
            } catch (\Throwable $e) {
                return [];
            }
        });

        if (empty($columns)) {
            return $data;
        }

        return array_intersect_key($data, array_flip($columns));
    }

    protected function findBankAccountUserColumn(): ?string
    {
        return Cache::remember('bank_accounts:user-column', now()->addMinutes(10), function () {
            if ($this->columnExists('bank_accounts', 'user_id')) {
                return 'user_id';
            }

            if ($this->columnExists('bank_accounts', 'uid')) {
                return 'uid';
            }

            if ($this->columnExists('bank_accounts', 'client_id')) {
                return 'client_id';
            }

            return null;
        });
    }

    protected function maskBankNumber($value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        return strlen($value) > 8
            ? substr($value, 0, 4) . ' **** ' . substr($value, -4)
            : '**** ' . substr($value, -4);
    }
}
