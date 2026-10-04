<?php
namespace App\Services\Umi\V2;

use App\Domain\Umi\V2\Decimal;
use App\Models\Market\Market;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Current business quotes use ask one; historical events use immutable recorded quotes. */
final class BestAskQuote
{
    public const SOURCE = 'deepro_best_ask';
    public const MAX_HISTORY_AGE = 20;

    public function read(bool $record = false): object
    {
        $markets = Market::where('base_currency_id', app(FundedWallet::class)->currencyId())
            ->whereHas('quoteCurrency', fn($q) => $q->where('symbol', 'USDT')->where('status', true))
            ->where('status', true)->where('trade_status', true)->get();
        if ($markets->count() !== 1) throw new DomainException('UMI/USDT 盘口暂不可用，请稍后重试。');
        $market = $markets->first();
        $book = app(BestAskBook::class)->read($market);
        $best = null; $bid = null;
        foreach (['asks', 'bids'] as $side) foreach ($book[$side] ?? [] as $row) {
            try { $price = Decimal::amount((string) ($row['price'] ?? ''), true);
                $quantity = Decimal::amount((string) ($row['quantity'] ?? ''), true);
            } catch (\InvalidArgumentException) { throw new DomainException('UMI/USDT 盘口数据异常，请稍后重试。'); }
            if ($side === 'asks' && ($best === null || Decimal::cmp($price, $best) < 0)) $best = $price;
            if ($side === 'bids' && ($bid === null || Decimal::cmp($price, $bid) > 0)) $bid = $price;
        }
        if ($best === null || ($bid !== null && Decimal::cmp($bid, $best) >= 0))
            throw new DomainException('UMI/USDT 卖一报价暂不可用，请稍后重试。');
        $time = FundedTime::database(now());
        $quote = ['asset' => 'UMI_USDT', 'price' => $best, 'source' => self::SOURCE,
            'source_ref' => 'market:'.$market->id.':ask:'.hash('sha256', $best.'|'.$time), 'observed_at' => $time];
        if (!$record) return (object) $quote;
        DB::table('umi_v2_live_quotes')->insertOrIgnore($quote + ['approved_by' => null, 'created_at' => $time]);
        $saved = DB::table('umi_v2_live_quotes')->where('asset', 'UMI_USDT')->where('source', self::SOURCE)
            ->where('source_ref', $quote['source_ref'])->first();
        if (!$saved || Decimal::cmp((string)$saved->price, $best) !== 0 || !CarbonImmutable::parse($saved->observed_at)->equalTo(CarbonImmutable::parse($time)))
            throw new DomainException('卖一报价凭证不一致，请联系运营核对。');
        return $saved;
    }

    public function at(string $time): object
    {
        $at = CarbonImmutable::parse($time);
        $q = DB::table('umi_v2_live_quotes')->where('asset', 'UMI_USDT')->where('source', self::SOURCE);
        $first = (clone $q)->min('observed_at');
        // Preserve evidence for events before this pricing policy was first recorded.
        if ($first && $at->lt(CarbonImmutable::parse($first))) return app(SpotQuote::class)->read($time, true);
        $quote = $q->where('observed_at', '<=', FundedTime::database($at))
            ->where('observed_at', '>=', FundedTime::database($at->subSeconds(self::MAX_HISTORY_AGE)))
            ->orderByDesc('observed_at')->orderByDesc('id')->first();
        if (!$quote || Decimal::cmp((string)$quote->price, '0') <= 0)
            throw new DomainException('销毁时点的卖一报价凭证尚未齐全，请等待核对。');
        return $quote;
    }

    public function status(): array
    {
        try { return ['available' => true, 'quote' => $this->read()]; }
        catch (DomainException $e) { return ['available' => false, 'message' => $e->getMessage()]; }
    }
}
