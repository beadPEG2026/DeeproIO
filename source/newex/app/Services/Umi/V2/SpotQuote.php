<?php

namespace App\Services\Umi\V2;

use App\Domain\Umi\V2\Decimal;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Use the same committed, real taker fills as Deepro's spot candles. */
final class SpotQuote
{
    public const SOURCE = 'deepro_spot';

    public function read(?string $at = null, bool $record = false): object
    {
        $moment = $at ? CarbonImmutable::parse($at) : CarbonImmutable::now();
        $zone = config('app.timezone', 'UTC');
        $market = DB::table('markets as m')
            ->join('currencies as q', 'q.id', '=', 'm.quote_currency_id')
            ->where('m.base_currency_id', app(FundedWallet::class)->currencyId())
            ->where('q.symbol', 'USDT')->whereNull('q.deleted_at')->where('q.status', true)
            ->whereNull('m.deleted_at')->where('m.status', true)->where('m.trade_status', true)
            ->select('m.id')->get();
        if ($market->count() !== 1) throw new DomainException('Deepro UMI/USDT 现货市场暂不可用。');
        $fill = DB::table('transactions as t')
            ->where('t.market_id', $market[0]->id)->where('t.is_maker', false)->where('t.is_volume', 0)
            ->where('t.price', '>', 0)->where('t.base_currency', '>', 0)
            ->whereExists(function ($q): void {
                $q->selectRaw('1')->from('order_histories as h')->whereColumn('h.id', 't.order_id')
                    ->whereColumn('h.market_id', 't.market_id')->whereColumn('h.user_id', 't.user_id')
                    ->where('h.settlement_domain', 'real');
            })
            ->where('t.created_at', '<=', $moment->setTimezone($zone)->format('Y-m-d H:i:s'))
            ->where('t.created_at', '>=', $moment->subSeconds(300)->setTimezone($zone)->format('Y-m-d H:i:s'))
            ->orderByDesc('t.created_at')->orderByDesc('t.id')->first(['t.id', 't.price', 't.created_at']);
        if (!$fill) throw new DomainException('Deepro UMI/USDT 暂无 5 分钟内的有效现货成交价，请稍后再试。');
        $quote = ['asset' => 'UMI_USDT', 'price' => Decimal::amount((string) $fill->price, true),
            'source' => self::SOURCE, 'source_ref' => 'market:' . $market[0]->id . ':trade:' . $fill->id,
            'observed_at' => FundedTime::database(CarbonImmutable::parse($fill->created_at, $zone))];
        if (!$record) return (object) $quote;
        DB::table('umi_v2_live_quotes')->insertOrIgnore($quote + [
            'approved_by' => null, 'created_at' => FundedTime::database(now()),
        ]);
        $saved = DB::table('umi_v2_live_quotes')->where('asset', 'UMI_USDT')
            ->where('source', self::SOURCE)->where('source_ref', $quote['source_ref'])->first();
        if (!$saved || Decimal::cmp((string) $saved->price, $quote['price']) !== 0
            || !CarbonImmutable::parse($saved->observed_at)->equalTo(CarbonImmutable::parse($quote['observed_at']))) {
            throw new DomainException('现货成交报价记录不一致，请联系运营核对。');
        }
        return $saved;
    }
}
