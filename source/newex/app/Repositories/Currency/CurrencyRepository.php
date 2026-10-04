<?php

namespace App\Repositories\Currency;

use App\Interfaces\Currency\CurrencyRepositoryInterface;
use App\Models\Currency\Currency;
use App\Models\Market\Market;
use App\Models\User\User;
use App\Models\Wallet\Wallet;
use App\Models\Withdrawal\FiatWithdrawal;
use App\Models\Withdrawal\Withdrawal;
use App\Services\PaymentGateways\Coin\Bnb\Api\BnbGateway;
use App\Services\PaymentGateways\Coin\Coinpayments\Model\CoinpaymentsCurrency;
use App\Services\PaymentGateways\Coin\Ethereum\Api\EthereumGateway;
use Auth;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CurrencyRepository implements CurrencyRepositoryInterface
{
    /**
     * @var Currency
     */
    protected $currency;

    /**
     * Cache USD rates during the current PHP request to avoid repeated market lookups
     * while serializing wallet and currency resource collections.
     */
    protected static $usdPriceCache = [];

    /**
     * Request-level cache for quote currencies used when converting wallet values to USD.
     */
    protected static $usdQuoteCurrencyIdsCache = null;

    /**
     * Request-level market price map keyed by base/quote currency ids.
     */
    protected static $usdMarketPriceMapCache = [];

    protected static $runtimeCurrenciesCache = null;

    protected static $runtimeMarketPairsCache = null;

    /**
     * CurrencyRepository constructor.
     *
     */
    public function __construct()
    {
        $this->currency = new Currency();
    }

    public function get($id, $trashed = false, $dashboard = false, $relations = null) {

        $currency = Currency::whereId($id);

        if($relations === null) {
            $currency->with(['networks', 'file']);
        } else {
            $currency->with($relations);
        }

        if(!$dashboard) {
            $currency->active();
        }

        if($trashed) {
            $currency->withTrashed();
        }

        return $currency->first();
    }

    public function getCurrencyBySymbol($symbol, $type = false, $active = true, $relations = null) {

        $currency = Currency::query();

        $currency->where(function($query) use ($symbol){
            $query->where('alt_symbol', $symbol);
            $query->orWhere('symbol', $symbol);
        });

        if(!$relations) {
            $relations = ['networks'];
        }

        $currency->with($relations);

        if($type) {
            $currency->whereType($type);
        }

        if($active) {
            $currency->active();
        }

        return $currency->first();
    }

    public function getCurrencyBySymbolForNetwork($symbol, int $networkId, $type = false, $active = true, $relations = null)
    {
        $symbol = trim((string) $symbol);

        if ($symbol === '' || $networkId <= 0) {
            return null;
        }

        $currency = Currency::query();

        $currency->where(function ($query) use ($symbol) {
            $query->whereRaw('LOWER(symbol) = ?', [mb_strtolower($symbol)])
                ->orWhereRaw('LOWER(alt_symbol) = ?', [mb_strtolower($symbol)]);
        });

        $currency->whereHas('networks', function ($query) use ($networkId) {
            $query->where('networks.id', $networkId);
        });

        if (!$relations) {
            $relations = ['networks'];
        }

        $currency->with($relations);

        if ($type) {
            $currency->whereType($type);
        }

        if ($active) {
            $currency->active();
        }

        return $currency->first();
    }

    public function getCurrencyByName($name, $type = false, $active = true, $relations = null) {

        $currency = Currency::query();

        $currency->where('name', $name);

        if(!$relations) {
            $relations = ['networks'];
        }

        $currency->with($relations);

        if($type) {
            $currency->whereType($type);
        }

        if($active) {
            $currency->active();
        }

        return $currency->first();
    }

    public function getCurrencyByContract($contract, $type = false, $active = true) {

        $contract = trim((string) $contract);

        if ($contract === '') {
            return null;
        }

        $currency = Currency::query();

        $currency->where(function ($query) use ($contract) {
            $query->where('contract', "ILIKE", $contract)
                ->orWhere('bep_contract', "ILIKE", $contract)
                ->orWhere('trc_contract', "ILIKE", $contract)
                ->orWhere('sol_contract', "ILIKE", $contract)
                ->orWhere('matic_contract', "ILIKE", $contract)
                ->orWhere('custom_contract', "ILIKE", $contract);
        });

        $currency->with('networks');

        if($type) {
            $currency->whereType($type);
        }

        if($active) {
            $currency->active();
        }

        return $currency->first();
    }

    public function getCurrencyByBepContract($contract, $type = false, $active = true)
    {
        $contract = trim((string) $contract);

        if ($contract === '') {
            return null;
        }

        $currency = Currency::query();

        $currency->where('bep_contract', 'ILIKE', $contract);
        $currency->with('networks');

        if ($type) {
            $currency->whereType($type);
        }

        if ($active) {
            $currency->active();
        }

        return $currency->first();
    }

    public function getCurrencyByContractForNetwork($contract, int $networkId, $type = false, $active = true)
    {
        $contract = trim((string) $contract);

        if ($contract === '' || $networkId <= 0) {
            return null;
        }

        $currency = Currency::query();

        $currency->where(function ($query) use ($contract) {
            $query->where('contract', 'ILIKE', $contract)
                ->orWhere('bep_contract', 'ILIKE', $contract)
                ->orWhere('trc_contract', 'ILIKE', $contract)
                ->orWhere('sol_contract', 'ILIKE', $contract)
                ->orWhere('matic_contract', 'ILIKE', $contract)
                ->orWhere('custom_contract', 'ILIKE', $contract);
        });

        $currency->whereHas('networks', function ($query) use ($networkId) {
            $query->where('networks.id', $networkId);
        });

        $currency->with('networks');

        if ($type) {
            $currency->whereType($type);
        }

        if ($active) {
            $currency->active();
        }

        return $currency->first();
    }

    public function all($paginate, $dashboard = false, $relations = ['file'], $type = false, $isPeer = false) {

        $currencies = Currency::filter(request()->only(['search', 'trashed', 'type']))->orderByLatest();

        if(!$dashboard) {
            $currencies->active();
        }

        if($type) {
            $currencies->type($type);
        }

        if($isPeer) {
            $currencies->where('is_p2p', true);
        }

        $currencies->with($relations);

        if($paginate) {
            return $currencies->paginate($dashboard ? 10 : 30)->withQueryString();
        } else {
            if (empty(array_filter(request()->only(['search', 'trashed', 'type'])))) {
                $cacheKey = 'currencies:all:' . md5(json_encode([
                    'dashboard' => (bool) $dashboard,
                    'relations' => $relations,
                    'type' => $type,
                    'is_peer' => (bool) $isPeer,
                ]));

                return Cache::store(
                    (string) config('performance.cache_store', 'redis')
                )->remember($cacheKey, now()->addSeconds(60), function () use ($currencies) {
                    return $currencies->get();
                });
            }

            return $currencies->get();
        }
    }

    public function onlyUsdt($paginate, $dashboard = false, $relations = ['file'], $type = false, $isPeer = false) {

        $currencies = Currency::filter(request()->only(['search', 'trashed', 'type']))->orderByLatest();

        if(!$dashboard) {
            $currencies->active();
        }

        if($type) {
            $currencies->type($type);
        }

        if($isPeer) {
            $currencies->where('is_p2p', true);
        }

        $currencies->where('symbol', 'USDT');

        $currencies->with($relations);

        if($paginate) {
            return $currencies->paginate($dashboard ? 10 : 30)->withQueryString();
        } else {
            return $currencies->get();
        }
    }

    public function count() {
        $currency = Currency::query();
        return $currency->count();
    }

    public function store($data) {

        \App\Services\Market\AssetProfile::validateEdit(null, $data);

        if(in_array(NETWORK_COINPAYMENTS, $data['networks'])) {
            $coinpaymentCurrency = CoinpaymentsCurrency::where('symbol', $data['alt_symbol'])->first();
            $data['txn_explorer'] = $coinpaymentCurrency->blockchain_url;
            $data['has_payment_id'] = $coinpaymentCurrency && $coinpaymentCurrency->has_payment_id;
        }

        if(in_array(NETWORK_ETH, $data['networks']) || in_array(NETWORK_BNB, $data['networks'])) {
            $data['decimals'] = 8;
        }

        if(in_array(NETWORK_ERC, $data['networks']) || in_array(NETWORK_ETH, $data['networks'])) {
            $data['txn_explorer'] = 'https://etherscan.io/tx/%txid%';
        }

        if(in_array(NETWORK_BEP, $data['networks']) || in_array(NETWORK_BNB, $data['networks'])) {
            $data['txn_explorer'] = 'https://bscscan.com/tx/%txid%';
        }

        if(in_array(NETWORK_MATIC, $data['networks']) || in_array(NETWORK_MATIC20, $data['networks'])) {
            $data['txn_explorer'] = 'https://polygonscan.com/tx/%txid%';
        }

        if(in_array(NETWORK_SOL, $data['networks']) || in_array(NETWORK_SOL_SPL, $data['networks'])) {
            $data['txn_explorer'] = 'https://solscan.io/tx/%txid%';
        }

        if(in_array(NETWORK_TRX, $data['networks']) || in_array(NETWORK_TRC, $data['networks'])) {
            $data['txn_explorer'] = 'https://tronscan.org/#/transaction/%txid%';
        }

        if(in_array(NETWORK_BTC, $data['networks']) || in_array(NETWORK_BRC20, $data['networks'])) {
            $data['txn_explorer'] = 'https://www.blockchain.com/btc/tx/%txid%';
        }

        if (in_array(NETWORK_XLAYER,$data['networks']) || in_array(NETWORK_XLAYER20,$data['networks'])) $data['txn_explorer']='https://www.okx.com/web3/explorer/xlayer/tx/%txid%';

        $currency = $this->currency->create($data);
        $currency->networks()->sync($data['networks']);
        app(\App\Services\Custody\AssetAutomation::class)->draft($currency->refresh());

        return $currency->fresh();
    }

    public function update($id, $data) {

        return DB::transaction(function () use ($id, $data) {
            $currency = Currency::withTrashed()->lockForUpdate()->findOrFail($id);
            \App\Services\Market\AssetProfile::validateEdit($currency, $data);
            $currency->update($data);
            $currency->networks()->sync($data['networks']);
            app(\App\Services\Custody\AssetAutomation::class)->draft($currency->refresh());
            return $currency->fresh();
        });
    }

    public function delete($id) {

        $currency = Currency::find($id);
        $currency->symbol = $currency->symbol . time();
        $currency->alt_symbol = $currency->alt_symbol . time();
        $currency->save();
        $currency->delete();

        return true;
    }

    public function restore($id) {

        $currency = Currency::withTrashed()->find($id);
        $currency->restore();

        return true;
    }

    public function getQuoteCurrencies() {
        return DB::table('currencies')->select('symbol')->whereRaw('id IN (SELECT quote_currency_id FROM markets WHERE deleted_at IS NULL GROUP BY quote_currency_id)')->orderBy('symbol','asc')->pluck('symbol');
    }

    public function getReport($filters = [], $pagination = true) {

        $currency = Currency::query();

        $currency->with(['networks']);

        $currency->filter($filters)->orderBy('name', 'asc');

        if(!$pagination) {
            return $currency->get();
        }

        return $currency->paginate(10)->withQueryString();
    }

    public function getDailyAvailableWithdrawal(Currency $currency, User $user) {

        $dailyLimit = $this->getDailyWithdrawalLimit();

        $price = $this->currencyPriceInUsd($currency);

        $limit = $dailyLimit['verified'];

        if(!$user->kyc_verified_at && $dailyLimit['unverified']) {
            $limit = $dailyLimit['unverified'];
        }

        $enabled = (float) $price > 0 && $limit > 0;

        if($enabled) {
            $finalAmount = (float) $limit > 0 ? math_divide($limit, $price) : 0;
        } else {
            $finalAmount = 0;
        }

        $withdrawalModel = $currency->type == "coin" ? Withdrawal::query() : FiatWithdrawal::query();

        $userLimit = $withdrawalModel->where('user_id', $user->id)->whereNotIn('status', [WITHDRAWAL_REJECTED, WITHDRAWAL_REJECTED])->where('updated_at', '>=', Carbon::now()->subDay()->toDateTimeString())->sum('inusd');

        $remainingAvailableAmount = math_sub($finalAmount, $enabled ? math_divide($userLimit, $price) : 0);

        $available = $enabled && $remainingAvailableAmount > 0 ? $remainingAvailableAmount : 0;

        return [
            'status' => $enabled,
            'available' => math_formatter($available, $currency->decimals),
            'total' => math_formatter($finalAmount, $currency->decimals)
        ];
    }

    public function getDailyWithdrawalLimit() {

        $verified = setting('general.withdrawal_limit', 0);
        $unverified = setting('general.withdrawal_limit_kyc', 0);

        return [
            'verified' => $verified,
            'unverified' => $unverified
        ];
    }

    public function currencyPriceInUsd($currency, $usdtCurrency = false, $usdCurrency = false, $market = false) {

        $usdtSymbol = 'USDT';
        $usdSymbol = 'USD';
        $symbol = strtoupper(trim((string) ($currency->symbol ?? '')));
        $stableSymbols = ['USDT', 'USD', 'USDC', 'BUSD', 'DAI', 'TUSD', 'USDP', 'USDD'];
        $currencyId = (int) ($currency->id ?? 0);
        $marketId = is_object($market) ? (int) ($market->id ?? 0) : (int) $market;
        $cacheKey = implode(':', [
            $currencyId,
            $symbol,
            is_object($usdtCurrency) ? (int) ($usdtCurrency->id ?? 0) : (int) $usdtCurrency,
            is_object($usdCurrency) ? (int) ($usdCurrency->id ?? 0) : (int) $usdCurrency,
            $marketId,
        ]);

        if(isset(static::$usdPriceCache[$cacheKey])) {
            return static::$usdPriceCache[$cacheKey];
        }

        // Check if this is a stable coin
        if($currency->is_stable || in_array($symbol, $stableSymbols, true)) {
            return static::$usdPriceCache[$cacheKey] = 1;
        }

        // Check if this currency is USDT or USD
        if($symbol === $usdtSymbol || $symbol === $usdSymbol) {
            return static::$usdPriceCache[$cacheKey] = 1;
        }

        if (static::$runtimeCurrenciesCache === null) {
            try {
                static::$runtimeCurrenciesCache = Cache::store(
                    (string) config('performance.cache_store', 'redis')
                )->get('currencies') ?: [];
            } catch (\Throwable $e) {
                static::$runtimeCurrenciesCache = [];
            }
        }

        $currenciesCache = static::$runtimeCurrenciesCache;

        // Quote currencies to calculate the price from any coin pair with this currency
        if(!$usdtCurrency) {
            $usdtCurrency = $currenciesCache[$usdtSymbol] ?? false;
        } else {
            $usdtCurrency = $usdtCurrency->id;
        }

        if(!$usdCurrency) {
            $usdCurrency = $currenciesCache[$usdSymbol] ?? false;
        } else {
            $usdCurrency = $usdCurrency->id;
        }

        $usdtCurrencyId = is_object($usdtCurrency) ? (int) ($usdtCurrency->id ?? 0) : (int) $usdtCurrency;
        $usdCurrencyId = is_object($usdCurrency) ? (int) ($usdCurrency->id ?? 0) : (int) $usdCurrency;

        if($usdtCurrencyId && $usdtCurrencyId == $currency->id) {
            return static::$usdPriceCache[$cacheKey] = 1;
        }

        if($usdCurrencyId && $usdCurrencyId == $currency->id) {
            return static::$usdPriceCache[$cacheKey] = 1;
        }

        // Try to get price from passed market
        if($market) {
            $price = market_get_stats($market, 'last');
            if($price > 0) {
                return static::$usdPriceCache[$cacheKey] = $price;
            }
        }

        // Try to get price from market pairs cache (uses symbol, not currency ID)
        if (static::$runtimeMarketPairsCache === null) {
            try {
                static::$runtimeMarketPairsCache = Cache::store(
                    (string) config('performance.cache_store', 'redis')
                )->get('marketPairs') ?: [];
            } catch (\Throwable $e) {
                static::$runtimeMarketPairsCache = [];
            }
        }

        $marketsCache = static::$runtimeMarketPairsCache;

        if($marketsCache && isset($marketsCache[$symbol . $usdtSymbol])) {
            $price = market_get_stats($marketsCache[$symbol . $usdtSymbol], 'last');
            if($price > 0) {
                return static::$usdPriceCache[$cacheKey] = $price;
            }
        }

        $quoteCurrencyIds = array_filter(array_unique([
            $usdtCurrencyId,
            $usdCurrencyId,
        ]));

        if(empty($quoteCurrencyIds)) {
            $quoteCurrencyIds = $this->getUsdQuoteCurrencyIds($usdtSymbol, $usdSymbol);
        }

        if(!empty($quoteCurrencyIds) && isset($currency->id)) {
            $marketPriceMap = $this->getUsdMarketPriceMap($quoteCurrencyIds);
            $baseCurrencyId = (int) $currency->id;

            foreach($quoteCurrencyIds as $quoteCurrencyId) {
                $mapKey = $baseCurrencyId . ':' . (int) $quoteCurrencyId;

                if(isset($marketPriceMap[$mapKey]) && $marketPriceMap[$mapKey] > 0) {
                    return static::$usdPriceCache[$cacheKey] = $marketPriceMap[$mapKey];
                }
            }

            foreach($quoteCurrencyIds as $baseQuoteCurrencyId) {
                $mapKey = (int) $baseQuoteCurrencyId . ':' . $baseCurrencyId;

                if(isset($marketPriceMap[$mapKey]) && $marketPriceMap[$mapKey] > 0) {
                    return static::$usdPriceCache[$cacheKey] = math_divide('1', (string) $marketPriceMap[$mapKey], 18);
                }
            }
        }

        // Fallback to currency's rate field if no market price available
        if(isset($currency->rate) && $currency->rate > 0) {
            return static::$usdPriceCache[$cacheKey] = $currency->rate;
        }

        return static::$usdPriceCache[$cacheKey] = 0;
    }

    protected function getUsdQuoteCurrencyIds(string $usdtSymbol, string $usdSymbol): array
    {
        if(static::$usdQuoteCurrencyIdsCache !== null) {
            return static::$usdQuoteCurrencyIdsCache;
        }

        $cacheKey = 'currency:usd_quote_ids:' . strtoupper($usdtSymbol) . ':' . strtoupper($usdSymbol);
        $resolver = function () use ($usdtSymbol, $usdSymbol) {
            return Currency::whereRaw('UPPER(symbol) IN (?, ?)', [$usdtSymbol, $usdSymbol])
                ->pluck('id')
                ->map(function ($id) {
                    return (int) $id;
                })
                ->all();
        };

        try {
            static::$usdQuoteCurrencyIdsCache = Cache::store(
                (string) config('performance.cache_store', 'redis')
            )->remember($cacheKey, now()->addDay(), $resolver);
        } catch (\Throwable $e) {
            static::$usdQuoteCurrencyIdsCache = $resolver();
        }

        return static::$usdQuoteCurrencyIdsCache;
    }

    protected function getUsdMarketPriceMap(array $quoteCurrencyIds): array
    {
        $quoteCurrencyIds = array_values(array_unique(array_map('intval', array_filter($quoteCurrencyIds))));

        if(empty($quoteCurrencyIds)) {
            return [];
        }

        $cacheKey = implode(':', $quoteCurrencyIds);

        if(isset(static::$usdMarketPriceMapCache[$cacheKey])) {
            return static::$usdMarketPriceMapCache[$cacheKey];
        }

        $resolver = function () use ($quoteCurrencyIds) {
            $markets = Market::query()
                ->select(['base_currency_id', 'quote_currency_id', 'last'])
                ->whereNotNull('last')
                ->where('last', '>', 0)
                ->where(function ($query) use ($quoteCurrencyIds) {
                    $query->whereIn('quote_currency_id', $quoteCurrencyIds)
                        ->orWhereIn('base_currency_id', $quoteCurrencyIds);
                })
                ->get();

            $map = [];

            foreach($markets as $market) {
                $baseCurrencyId = (int) $market->base_currency_id;
                $quoteCurrencyId = (int) $market->quote_currency_id;

                if($baseCurrencyId <= 0 || $quoteCurrencyId <= 0 || $market->last <= 0) {
                    continue;
                }

                $map[$baseCurrencyId . ':' . $quoteCurrencyId] = $market->last;
            }

            return $map;
        };

        try {
            $map = Cache::store(
                (string) config('performance.cache_store', 'redis')
            )->remember(
                'currency:usd-market-map:v1:' . config('database.default') . ':' . sha1($cacheKey),
                5,
                $resolver
            );
        } catch (\Throwable $e) {
            $map = $resolver();
        }

        return static::$usdMarketPriceMapCache[$cacheKey] = $map;
    }

    public function calculateAmountInUsd($currency, $amount, $decimals = 8) {

        $rateInUsd = $this->currencyPriceInUsd($currency);

        if($rateInUsd == 0) {
            return 0;
        }

        return math_formatter(math_multiply((string) $amount, (string) $rateInUsd), $decimals);
    }
}
