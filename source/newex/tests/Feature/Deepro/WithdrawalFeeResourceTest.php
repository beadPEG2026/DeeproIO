<?php

namespace Tests\Feature\Deepro;

use App\Http\Resources\Currency\Currency as CurrencyResource;
use App\Models\Currency\Currency;
use App\Repositories\Currency\CurrencyRepository;
use App\Services\Wallet\NetworkRules;
use App\Services\Withdrawal\WithdrawalFeeService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use Tests\TestCase;

final class WithdrawalFeeResourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('deepro_test', DB::connection()->getDatabaseName());
        config(['app.fees_in_usd' => false]);
    }

    private function currency(array $overrides = []): Currency
    {
        $fields = [
            'id' => 99999977, 'name' => 'Fee resource fixture', 'symbol' => 'FEE-QA',
            'type' => 'coin', 'decimals' => 8, 'min_deposit' => '1', 'max_deposit' => '0',
            'min_withdraw' => '1', 'max_withdraw' => '0',
        ];
        foreach (['', '_erc', '_bep', '_trc', '_matic', '_sol', '_xlayer'] as $index => $suffix) {
            $fields['withdraw_fee' . $suffix] = '0';
            $fields['withdraw_fee' . $suffix . '_fixed'] = (string) ($index + 1);
        }
        $currency = new Currency();
        $currency->forceFill(array_replace($fields, $overrides));
        $currency->setRelation('file', null);
        $currency->setRelation('networks', new Collection());
        return $currency;
    }

    private function serialize(Currency $currency): array
    {
        return (new CurrencyResource($currency))->toArray(Request::create('/wallets/withdraw/crypto/FEE-QA'));
    }

    private function assertMatchesFeeService(Currency $currency, array $rates): void
    {
        $service = new WithdrawalFeeService();
        foreach ($rates as $slug => $rate) {
            foreach (['1', '20', '123.456789'] as $amount) {
                $fromResource = math_compare($rate['percent'], '0') > 0
                    ? math_percentage($amount, $rate['percent'])
                    : $rate['fixed'];
                $fromService = $service->calculateCryptoFee($currency, $amount, $slug === 'default' ? null : $slug);
                $this->assertSame(0, math_compare($fromResource, $fromService), $slug . ' rate differs from execution fee');
            }
        }
    }

    public function test_all_coin_denominated_network_rates_match_execution_service(): void
    {
        $currency = $this->currency();
        $rates = $this->serialize($currency)['withdrawal_fee_rates'];
        $this->assertSame(['default', 'erc20', 'bep20', 'trc20', 'matic20', 'solspl', 'xlayer20', 'internal'], array_keys($rates));
        $this->assertSame(['percent' => '0', 'fixed' => '7'], $rates['xlayer20']);
        $this->assertSame(['percent' => '0', 'fixed' => '0'], $rates['internal']);
        $this->assertMatchesFeeService($currency, $rates);
    }

    public function test_xlayer_token_fee_uses_its_own_fields_and_percentage_precedence(): void
    {
        $currency = $this->currency(['withdraw_fee_xlayer' => '0.25', 'withdraw_fee_xlayer_fixed' => '9']);
        $rates = $this->serialize($currency)['withdrawal_fee_rates'];
        $this->assertSame(['percent' => '0.25', 'fixed' => '9'], $rates['xlayer20']);
        $network25 = NetworkRules::withdrawal($currency, 25);
        $this->assertSame($network25['fee_percent'], $rates['xlayer20']['percent']);
        $this->assertSame($network25['fee_fixed'], $rates['xlayer20']['fixed']);
        $this->assertSame('2', $rates['erc20']['fixed']);
        $this->assertSame('1', $rates['default']['fixed']);
        $this->assertMatchesFeeService($currency, $rates);
    }

    public function test_legacy_usd_display_conversion_never_converts_coin_denominated_rates(): void
    {
        $currency = $this->currency();
        $before = $this->serialize($currency);
        // Seed only the request-local price cache; no market, database or remote requests are needed.
        $cache = new ReflectionProperty(CurrencyRepository::class, 'usdPriceCache');
        $previous = $cache->getValue();
        $cache->setValue(null, $previous + ['99999977:FEE-QA:0:0:0' => '3']);
        try {
            config(['app.fees_in_usd' => true]);
            $after = $this->serialize($currency);
            $this->assertSame('3', $after['withdraw_fee_fixed']);
            $this->assertSame('6', $after['withdraw_fee_erc_fixed']);
            $this->assertSame($before['withdrawal_fee_rates'], $after['withdrawal_fee_rates']);
            $this->assertMatchesFeeService($currency, $after['withdrawal_fee_rates']);
        } finally {
            $cache->setValue(null, $previous);
        }
    }

    public function test_empty_and_non_positive_fields_match_service_zero_fee_fallback(): void
    {
        $currency = $this->currency(['withdraw_fee_xlayer' => null, 'withdraw_fee_xlayer_fixed' => '', 'withdraw_fee_erc' => '-2', 'withdraw_fee_erc_fixed' => '-1']);
        $rates = $this->serialize($currency)['withdrawal_fee_rates'];
        $this->assertSame(['percent' => '0', 'fixed' => '0'], $rates['xlayer20']);
        $this->assertSame(['percent' => '0', 'fixed' => '0'], $rates['erc20']);
        $this->assertMatchesFeeService($currency, $rates);
    }
}
