<?php

namespace App\Repositories\Wallet;

use App\Interfaces\Wallet\WalletRepositoryInterface;
use App\Models\Currency\Currency;
use App\Models\User\User;
use App\Models\Wallet\Wallet;
use App\Models\Wallet\WalletAddress;
use App\Models\Withdrawal\Withdrawal;
use App\Repositories\Deposit\DepositRepository;
use App\Services\Fireblocks\FireblocksService;
use App\Services\PaymentGateways\Coin\Bitcoin\Api\BitcoinGateway;
use App\Services\PaymentGateways\Coin\Bnb\Api\BnbGateway;
use App\Services\PaymentGateways\Coin\Coinpayments\Api\CoinpaymentsGateway;
use App\Services\PaymentGateways\Coin\Customtoken\Api\CustomtokenGateway;
use App\Services\PaymentGateways\Coin\Ethereum\Api\EthereumGateway;
use App\Services\PaymentGateways\Coin\Ripple\Services\RippleService;
use App\Services\PaymentGateways\Coin\Solana\Api\SolanaGateway;
use App\Services\PaymentGateways\Coin\Ton\Services\TonService;
use App\Services\PaymentGateways\Coin\Tron\Api\TronGateway;
use App\Services\Performance\ReadModelCacheService;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\Log;
use Setting;

class WalletRepository implements WalletRepositoryInterface
{
    public function getWallets($user_id, bool $fresh = false)
    {
        $userId = (int) $user_id;

        $resolve = function () use ($userId) {
            $wallet = Wallet::query();

            $wallet->with(['address']);
            $wallet->with(['currency.file']);

            $wallet->whereHas('currency', function ($query) {
                $query->where('status', true)
                    ->where('type', 'coin');
            });

            $wallet->where('user_id', $userId);

            return $wallet->get();
        };

        return $fresh ? $resolve() : app(ReadModelCacheService::class)->rememberWallets($userId, $resolve);
    }

    public function getUsdtWallets($user_id)
    {
        $wallet = Wallet::query();

        $wallet->with(['address']);
        $wallet->with(['currency.file']);

        $wallet->whereHas('currency', function ($query) {
            $query->where('status', true);
        });

        $wallet->has('currency');
        $wallet->where('user_id', $user_id);
        $wallet->where('currency_id', Currency::where('symbol', 'USDT')->first()->id);

        return $wallet->get();
    }

    public function getWallet($id)
    {
        return Wallet::find($id);
    }

    public function getWalletByCurrency($user_id, $currency, $lock = true)
    {
        $wallet = Wallet::query();

        $wallet->where('user_id', $user_id)
            ->where('currency_id', $currency);

        if ($lock) {
            $wallet->lockForUpdate();
        }

        return $wallet->first();
    }

    public function getWalletByAddress($address, $payment_id, $network, $currency)
    {
        $walletAddress = WalletAddress::query();

        $walletAddress->whereAddress($address);

        if (is_array($network)) {
            $walletAddress->whereIn('network_id', $network);
        } else {
            $walletAddress->where('network_id', $network);
        }

        if ($payment_id) {
            $walletAddress->where('payment_id', $payment_id);
        }

        if (!$walletAddress->exists() && !is_array($network)) {
            $networkModel = \App\Models\Network\Network::find($network);

            if ($networkModel && $this->isEvmNetwork($networkModel)) {
                $walletAddress = WalletAddress::query()
                    ->whereAddress($address)
                    ->whereIn('network_id', $this->getEvmNetworkIds());

                if ($payment_id) {
                    $walletAddress->where('payment_id', $payment_id);
                }
            }
        }

        if (!$walletAddress->exists() && !is_array($network)) {
            $networkModel = \App\Models\Network\Network::find($network);

            if ($networkModel && $this->isTronNetwork($networkModel)) {
                $walletAddress = WalletAddress::query()
                    ->whereAddress($address)
                    ->whereIn('network_id', $this->getTronNetworkIds());

                if ($payment_id) {
                    $walletAddress->where('payment_id', $payment_id);
                }
            }
        }

        if (!$walletAddress->exists()) {
            return null;
        }

        $foundWalletAddress = $walletAddress->first();

        $wallet = Wallet::query();
        $wallet->whereId($foundWalletAddress->wallet_id);

        if ($currency) {
            $wallet->where('currency_id', $currency);
        }

        $foundWallet = $wallet->first();

        if (!$foundWallet && $currency) {
            return Wallet::where('user_id', $foundWalletAddress->user_id)
                ->where('currency_id', $currency)
                ->first();
        }

        return $foundWallet;
    }

    public function getWalletAddress($wallet, $currency, $network)
    {
        // Serialize all network allocations for this user, including the first
        // EVM address shared by several currencies. Refreshes never rotate keys.
        return \Illuminate\Support\Facades\DB::transaction(function () use ($wallet, $currency, $network) {
            \Illuminate\Support\Facades\DB::table('users')->where('id', $wallet->user_id)->lockForUpdate()->first();
            return $this->allocateWalletAddress($wallet, $currency, $network);
        });
    }

    private function allocateWalletAddress($wallet, $currency, $network)
    {
        try {
            $walletAddress = null;
            $coinpaymentsNetworks = [NETWORK_COINPAYMENTS];

            if (in_array($network->id, $coinpaymentsNetworks)) {
                $walletAddress = WalletAddress::whereIn('network_id', $coinpaymentsNetworks)
                    ->where('user_id', $wallet->user_id)
                    ->where('currency_id', $currency->id)
                    ->first();
            } elseif ($this->isEvmNetwork($network)) {
                $evmNetworkIds = $this->getEvmNetworkIds();

                $evmBaseAddress = WalletAddress::whereIn('network_id', $evmNetworkIds)
                    ->where('user_id', $wallet->user_id)
                    ->whereNotNull('address')
                    ->where('address', '!=', '')
                    ->orderBy('id', 'asc')
                    ->first();

                $walletAddress = WalletAddress::where('network_id', $network->id)
                    ->where('user_id', $wallet->user_id)
                    ->first();

                if ($evmBaseAddress && !$walletAddress) {
                    $walletAddress = new WalletAddress();
                    $walletAddress->address = $evmBaseAddress->address;
                    $walletAddress->payment_id = $evmBaseAddress->payment_id;
                    $walletAddress->wallet_id = $wallet->id;
                    $walletAddress->user_id = $wallet->user_id;
                    $walletAddress->private_key = $evmBaseAddress->private_key;
                    $walletAddress->network_id = $network->id;
                    $walletAddress->save();

                    return $walletAddress;
                }

                if ($walletAddress) return $walletAddress;
            } elseif ($this->isTronNetwork($network)) {
                $tronNetworkIds = $this->getTronNetworkIds();

                $tronBaseAddress = WalletAddress::whereIn('network_id', $tronNetworkIds)
                    ->where('user_id', $wallet->user_id)
                    ->whereNotNull('address')
                    ->where('address', '!=', '')
                    ->orderBy('id', 'asc')
                    ->first();

                $walletAddress = WalletAddress::where('network_id', $network->id)
                    ->where('user_id', $wallet->user_id)
                    ->first();

                if ($tronBaseAddress && !$walletAddress) {
                    $walletAddress = new WalletAddress();
                    $walletAddress->address = $tronBaseAddress->address;
                    $walletAddress->payment_id = $tronBaseAddress->payment_id;
                    $walletAddress->wallet_id = $wallet->id;
                    $walletAddress->user_id = $wallet->user_id;
                    $walletAddress->private_key = $tronBaseAddress->private_key;
                    $walletAddress->network_id = $network->id;
                    $walletAddress->save();

                    return $walletAddress;
                }

                if ($walletAddress) return $walletAddress;
            } else {
                $walletAddress = WalletAddress::where('network_id', $network->id)
                    ->where('user_id', $wallet->user_id)
                    ->first();
            }

            $address = null;
            $paymentId = null;
            $private_key = null;

            if (!$walletAddress) {
                if (config('app.fireblocks_enabled')) {
                    $generatedAddress = (new FireblocksService())->createAddress($wallet->user, $network, $currency->symbol);

                    if (!$generatedAddress) {
                        return false;
                    }

                    $address = $generatedAddress['address'];
                    $paymentId = $generatedAddress['dest_tag'];
                    $private_key = null;
                } else {
                    switch ($network->slug) {
                        case "customtoken":
                        case "customtoken20":
                        case "eth":
                        case "erc20":
                        case "bnb":
                        case "bep20":
                        case "matic":
                        case "matic20":
                        case "xlayer":
                        case "xlayer20":
                            $generatedAddress = (new EthereumGateway())->createEthAddress();
                            $address = $generatedAddress['address'];
                            $private_key = $generatedAddress['private_key'];
                            break;

                        case "coinpayments":
                            $generatedAddress = (new CoinpaymentsGateway())->createAddress($currency->alt_symbol, $wallet->user_id);
                            $address = $generatedAddress['address'];
                            $paymentId = $generatedAddress['dest_tag'];
                            $private_key = null;
                            break;

                        case "trx":
                            $generatedAddress = (new TronGateway())->createTrcAddress();
                            $address = $generatedAddress['address'];
                            $private_key = $generatedAddress['private_key'];
                            break;

                        case "trc20":
                            $generatedAddress = (new TronGateway())->createTrxAddress();
                            $address = $generatedAddress['address'];
                            $private_key = $generatedAddress['private_key'];
                            break;

                        case "sol":
                        case "solspl":
                            $generatedAddress = (new SolanaGateway())->createSolAddress();
                            $address = $generatedAddress['address'];
                            $private_key = $generatedAddress['private_key'];
                            break;

                        case "xrp":
                            $generatedAddress = (new RippleService())->getSystemWallet();
                            $address = $generatedAddress['address'];
                            $private_key = '';
                            $paymentId = (new RippleService())->generateMemo();
                            break;

                        case "ton":
                            $generatedAddress = (new TonService())->getSystemWallet();
                            $address = $generatedAddress['address'];
                            $private_key = '';
                            $paymentId = (new TonService())->generateMemo();
                            break;

                        case "brc20":
                            $generatedAddress = (new BitcoinGateway())->createBitcoinAddress();
                            $address = $generatedAddress;
                            $private_key = bitcoind()->dumpprivkey($generatedAddress);
                            break;

                        case "btc":
                            // Core holds the wallet keys and signs withdrawals. Descriptor
                            // wallets cannot export a single key via the legacy dumpprivkey RPC.
                            $address = (new BitcoinGateway())->createOwnedBitcoinAddress();
                            $private_key = null;
                            break;
                    }
                }

                if (!$address) {
                    Log::error('Wallet address was not generated', [
                        'user_id' => $wallet->user_id,
                        'wallet_id' => $wallet->id,
                        'currency_id' => $currency->id,
                        'network_id' => $network->id,
                        'network_slug' => $network->slug,
                    ]);

                    return false;
                }

                $walletAddress = new WalletAddress();
                $walletAddress->address = $address;
                $walletAddress->payment_id = $paymentId;
                $walletAddress->wallet_id = $wallet->id;
                $walletAddress->user_id = $wallet->user_id;
                $walletAddress->private_key = $private_key;
                $walletAddress->network_id = $network->id;

                if ($network->id == NETWORK_COINPAYMENTS) {
                    $walletAddress->currency_id = $currency->id;
                }

                $walletAddress->save();
            }

            return $walletAddress;
        } catch (\Throwable $e) {
            Log::error('Wallet address allocation failed', ['user_id'=>$wallet->user_id,'network_id'=>$network?->id,'error_class'=>get_class($e)]);
            return false;
        }
    }

    private function getEvmNetworkSlugs(): array
    {
        return [
            'customtoken',
            'customtoken20',
            'eth',
            'erc20',
            'bnb',
            'bep20',
            'matic',
            'matic20',
            'xlayer',
            'xlayer20',
        ];
    }

    private function isEvmNetwork($network): bool
    {
        if (!$network || empty($network->slug)) {
            return false;
        }

        return in_array(strtolower($network->slug), $this->getEvmNetworkSlugs(), true);
    }

    private function getEvmNetworkIds(): array
    {
        return \App\Models\Network\Network::whereIn('slug', $this->getEvmNetworkSlugs())
            ->pluck('id')
            ->toArray();
    }

    private function getTronNetworkSlugs(): array
    {
        return [
            'trx',
            'trc20',
        ];
    }

    private function isTronNetwork($network): bool
    {
        if (!$network || empty($network->slug)) {
            return false;
        }

        return in_array(strtolower($network->slug), $this->getTronNetworkSlugs(), true);
    }

    private function getTronNetworkIds(): array
    {
        return \App\Models\Network\Network::whereIn('slug', $this->getTronNetworkSlugs())
            ->pluck('id')
            ->toArray();
    }

    public function withdrawCrypto(Withdrawal $withdrawal, $txn = false)
    {
        app(\App\Services\Wallet\WithdrawalNetworkPolicy::class)->assertSupported((int)$withdrawal->currency_id,(int)$withdrawal->network_id,(bool)$withdrawal->internal_id);

        $response = null;

        $amountAfterFee = math_sub($withdrawal->amount, $withdrawal->fee);

        $address = Setting::get('customtoken.wallet');
        $privateKey = Setting::get('customtoken.private_key');

        try {
            if (config('app.fireblocks_enabled')) {
                return (new FireblocksService())->handleWithdraw($withdrawal, $amountAfterFee);
            }

            switch ($withdrawal->network->slug) {
                case "internal":
                    $response = (new WalletService())->internalTransfer($withdrawal);
                    break;

                case "customtoken":
                    $response = (new CustomtokenGateway())->transfer($address, $withdrawal->address, $amountAfterFee, $privateKey);
                    break;

                case "customtoken20":
                    $response = (new CustomtokenGateway())->transferToken($withdrawal->id, $withdrawal->address, $amountAfterFee, $withdrawal->currency->custom_contract);
                    break;

                case "coinpayments":
                    $response = (new CoinpaymentsGateway())->withdraw($withdrawal->address, $withdrawal->payment_id, $amountAfterFee, $withdrawal->currency);
                    break;

                case "eth":
                    $response = (new EthereumGateway())->withdraw('eth', $withdrawal->id, $withdrawal->address, $amountAfterFee);
                    break;

                case "erc20":
                    $response = (new EthereumGateway())->withdraw('erc', $withdrawal->id, $withdrawal->address, $amountAfterFee, $withdrawal->currency->contract);
                    break;

                case "bnb":
                    $response = (new BnbGateway())->withdraw('bnb', $withdrawal->id, $withdrawal->address, $amountAfterFee);
                    break;

                case "bep20":
                    $response = (new BnbGateway())->withdraw('bep', $withdrawal->id, $withdrawal->address, $amountAfterFee, $withdrawal->currency->bep_contract);
                    break;

                case "matic":
                    $response = (new \App\Services\PaymentGateways\Coin\Polygon\Api\PolygonGateway())->withdraw('matic', $withdrawal->id, $withdrawal->address, $amountAfterFee);
                    break;

                case "matic20":
                    $response = (new \App\Services\PaymentGateways\Coin\Polygon\Api\PolygonGateway())->withdraw('matic20', $withdrawal->id, $withdrawal->address, $amountAfterFee, $withdrawal->currency->matic_contract);
                    break;

                case "trx":
                    $response = (new TronGateway())->withdraw('trx', $withdrawal->id, $withdrawal->address, $amountAfterFee);
                    break;

                case "trc20":
                    $response = (new TronGateway())->withdraw('trc', $withdrawal->id, $withdrawal->address, $amountAfterFee, $withdrawal->currency->trc_contract);
                    break;

                case "sol":
                    $response = (new SolanaGateway())->withdraw('sol', $withdrawal->id, $withdrawal->address, $amountAfterFee);
                    break;

                case "solspl":
                    $response = (new SolanaGateway())->withdraw('spl', $withdrawal->id, $withdrawal->address, $amountAfterFee, $withdrawal->currency->sol_contract);
                    break;

                case "xrp":
                    $response = (new RippleService())->withdraw($withdrawal->id, $withdrawal->address, $amountAfterFee, $withdrawal->payment_id);
                    break;

                case "ton":
                    $response = (new TonService())->withdraw($withdrawal->id, $withdrawal->address, $amountAfterFee, $withdrawal->payment_id);
                    break;

                case "btc":
                    $response = (new BitcoinGateway())->withdraw('btc', $withdrawal, $withdrawal->address, $amountAfterFee);
                    break;

                case "brc20":
                    $response = (new BitcoinGateway())->withdrawBrc($withdrawal, $txn);
                    break;
            }

            return $response;
        } catch (\Exception $e) {
            Log::error($e);

            return [
                'source' => null,
                'status' => STATUS_VALIDATION_ERROR,
                'message' => 'withdraw_failed',
            ];
        }
    }

    public function store($data)
    {
        return Wallet::insert($data);
    }

    public function getReport()
    {
        $wallet = Wallet::query();

        $wallet->filter(request()->only(['search', 'type', 'referrer', 'user']))
            ->orderByLatest();

        $wallet->with(['currency.networks', 'user', 'address']);

        $wallet->has('currency')->has('user');

        $this->applyAdminTeamScope($wallet, 'wallets.user_id');

        $wallets = $wallet->paginate(10)->withQueryString();

        $this->ensureSharedAddressesForWallets($wallets->getCollection());

        return $wallets;
    }

    protected function ensureSharedAddressesForWallets($wallets): void
    {
        if ($wallets->isEmpty()) {
            return;
        }

        $evmNetworkIds = $this->getEvmNetworkIds();
        $tronNetworkIds = $this->getTronNetworkIds();

        foreach ($wallets as $wallet) {
            if ($wallet->address || !$wallet->currency || $wallet->currency->type === 'fiat') {
                continue;
            }

            $currencyNetworkIds = $this->getCurrencyNetworkIds($wallet->currency);

            $evmTargetNetworkIds = array_values(array_intersect($currencyNetworkIds, $evmNetworkIds));

            if (!empty($evmTargetNetworkIds) || $this->currencyHasEvmContract($wallet->currency)) {
                $walletAddress = $this->copySharedAddressForNetworkFamily(
                    $wallet,
                    $evmNetworkIds,
                    $evmTargetNetworkIds[0] ?? null
                );

                if ($walletAddress) {
                    $wallet->setRelation('address', $walletAddress);
                    continue;
                }
            }

            $tronTargetNetworkIds = array_values(array_intersect($currencyNetworkIds, $tronNetworkIds));

            if (!empty($tronTargetNetworkIds) || $this->currencyHasTronContract($wallet->currency)) {
                $walletAddress = $this->copySharedAddressForNetworkFamily(
                    $wallet,
                    $tronNetworkIds,
                    $tronTargetNetworkIds[0] ?? null
                );

                if ($walletAddress) {
                    $wallet->setRelation('address', $walletAddress);
                }
            }
        }
    }

    protected function getCurrencyNetworkIds($currency): array
    {
        if (!$currency || !$currency->relationLoaded('networks')) {
            return [];
        }

        return $currency->networks
            ->pluck('id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->toArray();
    }

    protected function currencyHasEvmContract($currency): bool
    {
        if (!$currency) {
            return false;
        }

        foreach (['contract', 'bep_contract', 'matic_contract', 'custom_contract'] as $field) {
            if (trim((string) ($currency->{$field} ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    protected function currencyHasTronContract($currency): bool
    {
        return $currency && trim((string) ($currency->trc_contract ?? '')) !== '';
    }

    protected function copySharedAddressForNetworkFamily(Wallet $wallet, array $networkIds, ?int $targetNetworkId = null): ?WalletAddress
    {
        if (empty($networkIds)) {
            return null;
        }

        $existingWalletAddress = WalletAddress::where('wallet_id', $wallet->id)
            ->whereIn('network_id', $networkIds)
            ->whereNotNull('address')
            ->where('address', '!=', '')
            ->orderBy('id', 'asc')
            ->first();

        if ($existingWalletAddress) {
            return $existingWalletAddress;
        }

        $baseAddress = WalletAddress::whereIn('network_id', $networkIds)
            ->where('user_id', $wallet->user_id)
            ->whereNotNull('address')
            ->where('address', '!=', '')
            ->orderBy('id', 'asc')
            ->first();

        if (!$baseAddress) {
            return null;
        }

        $targetNetworkId = $targetNetworkId ?: (int) $baseAddress->network_id;

        $walletAddress = WalletAddress::where('wallet_id', $wallet->id)
            ->where('network_id', $targetNetworkId)
            ->first();

        if (!$walletAddress) {
            $walletAddress = new WalletAddress();
            $walletAddress->wallet_id = $wallet->id;
            $walletAddress->user_id = $wallet->user_id;
            $walletAddress->network_id = $targetNetworkId;
        }

        $walletAddress->address = $baseAddress->address;
        $walletAddress->payment_id = $baseAddress->payment_id;

        try {
            if (!empty($baseAddress->private_key)) {
                $walletAddress->private_key = $baseAddress->private_key;
            }
        } catch (\Throwable $e) {
            Log::warning('Shared wallet address private key copy skipped', [
                'wallet_id' => $wallet->id,
                'base_wallet_address_id' => $baseAddress->id,
                'message' => $e->getMessage(),
            ]);
        }

        $walletAddress->save();

        return $walletAddress;
    }

    protected function getCurrentRoleIds(): array
    {
        $currentUser = auth()->user();

        if (!$currentUser) {
            return [];
        }

        $currentUser->loadMissing('roles');

        return $currentUser->roles
            ->pluck('id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->toArray();
    }

    protected function getCurrentRoleNames(): array
    {
        $currentUser = auth()->user();

        if (!$currentUser) {
            return [];
        }

        $currentUser->loadMissing('roles');

        return $currentUser->roles
            ->pluck('name')
            ->filter()
            ->values()
            ->toArray();
    }

    protected function isSuperAdmin(): bool
    {
        $roleNames = $this->getCurrentRoleNames();

        if (in_array('superadmin', $roleNames, true)) {
            return true;
        }

        return (auth()->user()?->hasRole('superadmin') ?? false);
    }

    protected function hasTeamDataScope(): bool
    {
        $roleNames = $this->getCurrentRoleNames();

        $teamScopeRoles = [
            'admin',
            'user_leader',
            'salesman',
            'user_editor',
            'perm_users',
        ];

        if (count(array_intersect($roleNames, $teamScopeRoles)) > 0) {
            return true;
        }

        return (auth()->user()?->hasRole('admin') ?? false);
    }

    protected function applyAdminTeamScope($query, string $userColumn)
    {
        app(\App\Services\Admin\AdminGroupFilterService::class)
            ->applyToQuery($query, $userColumn);

        $currentUser = auth()->user();

        if (!$currentUser) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->isSuperAdmin()) {
            return $query;
        }

        if ($this->hasTeamDataScope()) {
            $teamUserIds = $this->getAllTeamUserIds((int) $currentUser->id);

            if (empty($teamUserIds)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereIn($userColumn, $teamUserIds);
        }

        return $query->whereRaw('1 = 0');
    }

    protected function getAllTeamUserIds(int $userId): array
    {
        $allIds = [$userId];
        $pendingIds = [$userId];

        while (!empty($pendingIds)) {
            $children = User::query()
                ->whereIn('referral_id', $pendingIds)
                ->pluck('id')
                ->map(function ($id) {
                    return (int) $id;
                })
                ->toArray();

            $children = array_values(array_diff($children, $allIds));

            if (empty($children)) {
                break;
            }

            $allIds = array_merge($allIds, $children);
            $pendingIds = $children;
        }

        return array_values(array_unique(array_map('intval', $allIds)));
    }

    public function depositInternal($wallet, $amount, $meta = null)
    {
        $depositRepository = new DepositRepository();
        $metaData = is_array($meta) ? $meta : [];

        if ((float) $amount < 0) {
            (new WalletService())->decrease($wallet, abs($amount), 'wallet');
            return;
        }

        $raw = !empty($metaData) ? json_encode($metaData, JSON_UNESCAPED_UNICODE) : null;

        $depositRepository->store([
            'deposit_id' => $metaData['deposit_id'] ?? generate_uuid(),
            'txn' => array_key_exists('txn', $metaData) ? $metaData['txn'] : null,
            'source_id' => $metaData['source_id'] ?? null,
            'currency_id' => $wallet->currency->id,
            'type' => 'coin',
            'network_id' => $metaData['network_id'] ?? NETWORK_INTERNAL,
            'amount' => $amount,
            'full_amount' => $amount,
            'network_fee' => 0,
            'address' => $metaData['address'] ?? 'Internal',
            'user_id' => $wallet->user_id,
            'confirms' => $metaData['confirms'] ?? 1,
            'status' => $metaData['status'] ?? DEPOSIT_CONFIRMED,
            'internal_id' => $metaData['internal_id'] ?? generate_uuid(),
            'initial_raw' => $raw,
            'raw' => $raw,
            'wallet_transfer_status' => $metaData['wallet_transfer_status'] ?? 'processed',
        ]);

        (new WalletService())->increase($wallet, $amount);
    }

    public function calculatePeerFee($amount, $currency, $side, $isMaker = true, $feeRate = false)
    {
        if ($feeRate) {
            if ($feeRate == 0) {
                return 0;
            }

            return math_percentage($amount, trim($feeRate));
        }

        $feeField = 'p2p_' . ($isMaker ? 'maker' : 'taker') . '_' . $side . '_fee';

        if ((float) $currency->{$feeField} > 0) {
            return math_percentage($amount, $currency->{$feeField});
        }

        return 0;
    }

    public function getPeerFeeRate($currency, $side, $isMaker = true)
    {
        $feeField = 'p2p_' . ($isMaker ? 'maker' : 'taker') . '_' . $side . '_fee';

        return $currency->{$feeField};
    }
}
