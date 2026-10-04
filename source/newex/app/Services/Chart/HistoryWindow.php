<?php
namespace App\Services\Chart;

final class HistoryWindow
{
    public const MAX_BARS = 5000;
    public static function seconds(string $resolution): int
    {
        return match ($resolution) {
            '1S'=>1, 'D','1D'=>86400, '3D'=>259200, 'W','1W'=>604800, 'M','1M'=>2419200,
            '1','3','5','15','30','60','120','240','360','480','720'=>(int)$resolution*60,
            default=>0,
        };
    }
    public static function valid(int $from,int $to,string $resolution): bool
    {
        $seconds=self::seconds($resolution);
        return $seconds>0 && $from>=0 && $to>$from && ($to-$from)<=($seconds*(self::MAX_BARS+2));
    }
    /** Ensure countback bars fit before the exclusive upper bound, even when the
     * chart asks into the next period. Never invent bars or change candle values. */
    public static function start(int $from, int $to, string $resolution, int $countback, int $now): int
    {
        if ($countback < 1 || $countback > 5000 || $to <= 0 || $from < 0 || $from >= $to) return $from;
        $seconds = match ($resolution) {
            '1S' => 1,
            'D', '1D' => 86400,
            '3D' => 259200,
            default => ctype_digit($resolution) && (int) $resolution > 0 ? (int) $resolution * 60 : 0,
        };
        // Calendar months and exchange-aligned weeks retain their original range.
        if (!$seconds) return $from;
        $last = intdiv(min($to - 1, $now), $seconds) * $seconds;
        return max(0, min($from, $last - ($countback - 1) * $seconds));
    }
}
