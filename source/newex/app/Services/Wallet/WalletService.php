<?php

namespace App\Services\Wallet;

use App\Mail\Withdrawals\AdminWithdrawalReceived;
use App\Models\Currency\Currency;
use App\Models\User\User;
use App\Models\Wallet\Wallet;
use App\Models\Withdrawal\Withdrawal;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Network\NetworkRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Repositories\Withdrawal\WithdrawalRepository;
use App\Services\Currency\CurrencyService;
use App\Services\Withdrawal\WithdrawalFeeService;
use DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Setting;

class WalletService {

    private $walletRepository;

    public function __construct(?WalletRepository $walletRepository = null)
    {
        $this->walletRepository = $walletRepository ?? new WalletRepository();
    }

    public $reflectField = [
      'order' => 'balance_in_order',
      'wallet' => 'balance_in_wallet',
      'withdraw' => 'balance_in_withdraw',
      'trade' => 'balance_in_trade',
      'lc' => 'balance_in_lc'
    ];

    public function increase(Wallet $wallet, $quantity, $field = 'wallet') {

        $originalField = $this->reflectField[$field];

        // Security: Validate quantity is numeric to prevent SQL injection
        $quantity = $this->sanitizeQuantity($quantity);

        // Use parameterized query for safety
        DB::statement(
            "UPDATE wallets SET $originalField = $originalField + ?, updated_at = ? WHERE id = ?",
            [$quantity, Carbon::now(), $wallet->id]
        );
    }

    public function decrease(Wallet $wallet, $quantity, $field = 'order') {

        $originalField = $this->reflectField[$field];

        // Security: Use parameterized query to prevent SQL injection
        $quantity = $this->sanitizeQuantity($quantity);

        if (bccomp($quantity, '0', 24) < 0) throw new \InvalidArgumentException('Quantity must be nonnegative');
        if (DB::update(
            "UPDATE wallets SET $originalField = $originalField - ?, updated_at = ? WHERE id = ? AND $originalField >= ?",
            [$quantity, Carbon::now(), $wallet->id, $quantity]
        ) !== 1) throw \Illuminate\Validation\ValidationException::withMessages(['amount' => __('Insufficient balance')]);
    }

    /**
     * Sanitize quantity to prevent SQL injection
     */
    private function sanitizeQuantity($quantity): string
    {
        // Ensure quantity is a valid numeric string
        if (!is_numeric($quantity)) {
            throw new \InvalidArgumentException('Quantity must be numeric');
        }
        // Return as string to preserve precision for bcmath operations
        return (string) $quantity;
    }

    public function revert(Wallet $wallet, $quantity, $field = 'order', $fee = 0) {

        $walletField = $this->reflectField['trade'];
        $reflectedField = $this->reflectField[$field];

        // Security: Validate quantities are numeric
        $quantity = $this->sanitizeQuantity($quantity);
        $fee = $this->sanitizeQuantity($fee);
        $totalAdd = bcadd($quantity, $fee, 18);

        // Use parameterized query for safety
        DB::statement(
            "UPDATE wallets SET $reflectedField = $reflectedField - ?, $walletField = $walletField + ?, updated_at = ? WHERE id = ?",
            [$quantity, $totalAdd, Carbon::now(), $wallet->id]
        );
    }

    public function createWalletsForUser(User $user) {

       $currencies = Currency::get();

       foreach ($currencies as $currency) {
           $this->assignWallet($currency, [$user]);
       }
    }

    public function createWalletsForCurrency(Currency $currency) {
        $this->assignWallet($currency, User::get());
    }

    public function assignWallet(Currency $currency, $users) {
        foreach ($users as $user) {
            DB::transaction(function () use ($user, $currency) {
                DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
                if (!Wallet::where('user_id', $user->id)->where('currency_id', $currency->id)->exists()) {
                    $wallet = new Wallet();
                    $wallet->user_id = $user->id; $wallet->currency_id = $currency->id; $wallet->save();
                }
            });
        }
    }

    public function getWallets($user_id = false, bool $fresh = false) {

        // If user not defined then take from current user
        if(!$user_id)
            $user_id = auth()->user()->id;

        return $this->walletRepository->getWallets($user_id, $fresh);
    }

    public function getUsdtWallets($user_id = false) {

        // If user not defined then take from current user
        if(!$user_id)
            $user_id = auth()->user()->id;

        return $this->walletRepository->getUsdtWallets($user_id);
    }

    public function getWallet($id) {
        return $this->walletRepository->getWallet($id);
    }

    public function getWalletByCurrency($user_id, $currency) {
        return $this->walletRepository->getWalletByCurrency($user_id, $currency);
    }

    public function getWalletAddress($wallet, $currency, $network) {
        return $this->walletRepository->getWalletAddress($wallet, $currency, $network);
    }

    public function getWalletData($currency, $user_id) {
        return $this->getWalletByCurrency($user_id, $currency);
    }

   public function generateWalletAddress($symbol, $user_id = false, $network = false)
{
    if (!$user_id) {
        $user_id = auth()->user()->id;
    }

    $currency = (new CurrencyService())->getCurrencyBySymbol($symbol);

    if (!$currency) {
        return [
            'data' => [
                'success' => false,
                'message' => 'currency_not_found',
            ],
            'status' => STATUS_VALIDATION_ERROR,
        ];
    }

    try {
        $wallet = \Illuminate\Support\Facades\DB::transaction(function () use ($user_id, $currency) {
            \Illuminate\Support\Facades\DB::table('users')->where('id', $user_id)->lockForUpdate()->first();
            $wallet = $this->getWalletByCurrency($user_id, $currency->id);
            if (!$wallet) {
                $wallet = new Wallet();
                $wallet->user_id = $user_id;
                $wallet->currency_id = $currency->id;
                $wallet->save();
            }
            return $wallet;
        });
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('Deposit wallet allocation failed', ['user_id'=>$user_id,'error_class'=>get_class($e)]);
        return ['data'=>['success'=>false,'message'=>'wallet_create_failed'],'status'=>STATUS_VALIDATION_ERROR];
    }

    if (!$wallet) {
        return [
            'data' => [
                'success' => false,
                'message' => 'wallet_create_failed',
            ],
            'status' => STATUS_VALIDATION_ERROR,
        ];
    }

    $networkModel = null;

    if ($network) {
        $networkModel = (new NetworkRepository())->getById($network);

        if (!$networkModel) {
            return [
                'data' => [
                    'success' => false,
                    'message' => 'network_not_found',
                ],
                'status' => STATUS_VALIDATION_ERROR,
            ];
        }
    }

    $walletAddress = $this->getWalletAddress($wallet, $currency, $networkModel);

    if (!$walletAddress) {
        return [
            'data' => [
                'success' => false,
                'message' => 'address_was_not_generated',
            ],
            'status' => STATUS_VALIDATION_ERROR,
        ];
    }

    return [
        'data' => [
            'success' => true,
            'address' => $walletAddress->address,
            'paymentId' => $walletAddress->payment_id,
        ],
        'status' => STATUS_OK,
    ];
}

    public function withdrawCrypto($symbol, $address, $amount, $user_id = false, $network = false, $payment_id = null) {
        if (in_array((int)$network, [NETWORK_XLAYER, NETWORK_XLAYER20], true)) $address = XLayerAddress::normalize($address);

        if(!$user_id)
            $user_id = auth()->user()->id;

        $currency = (new CurrencyService())->getCurrencyBySymbol($symbol);

        app(WithdrawalNetworkPolicy::class)->assertSupported((int)$currency->id, (int)$network);

        $wallet = $this->getWalletByCurrency($user_id, $currency->id);

        $internal_id = null;
        $isInternal = (new NetworkRepository())->getInternalWallet($address);

        if($isInternal) {

            $feeAmount = 0;
            $network = (new NetworkRepository())->getById(NETWORK_INTERNAL);
            $internal_id = generate_uuid();

        } else {

            $network = (new NetworkRepository())->getById($network);

            $feeAmount = (new WithdrawalFeeService())->calculateCryptoFee(
                $currency,
                $amount,
                $network->slug
            );

        }

        $extraStatus = '';

        if($network->slug == "brc20") {
            $extraStatus = WITHDRAWAL_INSCRIBING;
        }

        $id = generate_uuid();

        $data = [
            'withdrawal_id' => $id,
            'txn' => null,
            'source_id' => null,
            'currency_id' => $currency->id,
            'type' => 'coin',
            'network_id' => $network->id,
            'amount' => $amount,
            'fee' => $feeAmount,
            'address' => $address,
            'payment_id' => $payment_id,
            'user_id' => $wallet->user_id,
            'confirms' => 0,
            'status' => WITHDRAWAL_WAITING_APPROVAL,
            'extra_status' => $extraStatus,
            'initial_raw' => null,
            'internal_id' => $internal_id,
            'inusd' => math_multiply($amount, (new CurrencyRepository())->currencyPriceInUsd($currency))
        ];

        DB::transaction(function () use ($data, $wallet, $amount) {
            (new WithdrawalRepository())->store($data);

            $this->reserveWithdrawalBalance(
                $wallet,
                $amount,
                $this->isVirtualUserId((int) $wallet->user_id)
            );
        }, DB_REPEAT_AFTER_DEADLOCK);

        try {

            // Admin Email Notification
            $adminEmail = Setting::get('notification.admin_email', false);
            $notificationAllowed = Setting::get('notification.crypto_withdrawals', false);

            if ($adminEmail && $notificationAllowed) {
                $model = Withdrawal::where('withdrawal_id', $id)->first();
                $route = route('admin.reports.withdrawals') . "?search=" . $model->withdrawal_id;
                Mail::to($adminEmail)->queue(new AdminWithdrawalReceived($model->amount, $model->currency->symbol, $route));
            }
            // END Admin Email Notification

        } catch (\Exception $e) {

        }

        return true;
    }

    protected function reserveWithdrawalBalance(Wallet $wallet, $amount, bool $allowVirtual): void
    {
        $remaining = $this->sanitizeQuantity($amount);

        if (math_compare($remaining, 0) <= 0) {
            return;
        }

        $freshWallet = Wallet::query()
            ->where('id', $wallet->id)
            ->lockForUpdate()
            ->first();

        if (!$freshWallet) {
            throw new \Exception('Wallet not found');
        }

        $pairs = $this->getWithdrawalReservePairs($allowVirtual);

        foreach ($pairs as $pair) {
            if (!$this->walletColumnExists($pair['from']) || !$this->walletColumnExists($pair['to'])) {
                continue;
            }

            $freshWallet->refresh();
            $available = $this->normalizeDecimal($freshWallet->{$pair['from']} ?? 0);

            if (math_compare($available, 0) <= 0) {
                continue;
            }

            $deduct = math_compare($available, $remaining) >= 0 ? $remaining : $available;

            DB::statement(
                "UPDATE wallets
                 SET {$pair['from']} = GREATEST(COALESCE({$pair['from']}, 0) - ?, 0),
                     {$pair['to']} = COALESCE({$pair['to']}, 0) + ?,
                     updated_at = ?
                 WHERE id = ?",
                [$deduct, $deduct, Carbon::now(), $freshWallet->id]
            );

            $remaining = math_sub($remaining, $deduct);

            if (math_compare($remaining, 0) <= 0) {
                return;
            }
        }

        throw new \Exception('Insufficient balance');
    }

    protected function getWithdrawalReservePairs(bool $allowVirtual): array
    {
        $pairs = [];

        if ($allowVirtual) {
            $virtualWithdrawTarget = $this->walletColumnExists('balance_in_virtual_withdraw')
                ? 'balance_in_virtual_withdraw'
                : ($this->walletColumnExists('balance_in_withdraw') ? 'balance_in_withdraw' : null);

            if ($virtualWithdrawTarget && $this->walletColumnExists('balance_in_virtual_wallet')) {
                $pairs[] = [
                    'from' => 'balance_in_virtual_wallet',
                    'to' => $virtualWithdrawTarget,
                ];
            }
        }

        if ($this->walletColumnExists('balance_in_wallet') && $this->walletColumnExists('balance_in_withdraw')) {
            $pairs[] = [
                'from' => 'balance_in_wallet',
                'to' => 'balance_in_withdraw',
            ];
        }

        return $pairs;
    }

    protected function isVirtualUserId(int $userId): bool
    {
        $columns = ['id'];

        if ($this->userColumnExists('is_xn')) {
            $columns[] = 'is_xn';
        }

        if ($this->userColumnExists('is_xm')) {
            $columns[] = 'is_xm';
        }

        $user = User::query()
            ->select($columns)
            ->where('id', $userId)
            ->first();

        if (!$user) {
            return false;
        }

        return filter_var($user->is_xn ?? false, FILTER_VALIDATE_BOOLEAN)
            || filter_var($user->is_xm ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    protected function walletColumnExists(string $column): bool
    {
        try {
            return Schema::hasColumn('wallets', $column);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function userColumnExists(string $column): bool
    {
        try {
            return Schema::hasColumn('users', $column);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function normalizeDecimal($value): string
    {
        $value = str_replace(',', '', (string) ($value ?? 0));

        return is_numeric($value) ? $value : '0';
    }

    public function withdrawSystem($currency, $network, $address, $amount) {

        $data = [
            'withdrawal_id' => generate_uuid(),
            'txn' => null,
            'source_id' => 'system',
            'fund_origin' => 'treasury',
            'currency_id' => $currency,
            'type' => 'coin',
            'network_id' => $network,
            'amount' => $amount,
            'fee' => 0,
            'address' => $address,
            'payment_id' => null,
            'user_id' => 1,
            'confirms' => 0,
            'status' => WITHDRAWAL_WAITING_APPROVAL,
            'initial_raw' => null,
        ];

        return (new WithdrawalRepository())->store($data);
    }

    public function withdrawCryptoConfirmed($withdrawal, $txn = false) {
        if (!$txn && $withdrawal->type === 'coin' && !$withdrawal->internal_id && isset(\App\Services\Custody\CustodyNetwork::MAP[$withdrawal->network->slug ?? ''])) {
            return app(\App\Services\Custody\CustodyService::class)->withdrawal($withdrawal);
        }
        return $this->walletRepository->withdrawCrypto($withdrawal, $txn);
    }

    public function internalTransfer($withdrawal) {
        return [
            'status' => STATUS_OK,
            'source' => 'internal',
            'message' => [],
        ];
    }
}
