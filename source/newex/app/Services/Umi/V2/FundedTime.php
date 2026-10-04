<?php

namespace App\Services\Umi\V2;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/** Keep absolute instants intact even when PHP and PostgreSQL use different timezones. */
final class FundedTime
{
    /** JSON timestamps always carry an offset; never let the browser guess the server zone. */
    public static function forDisplay(mixed $value, string $key = ''): mixed
    {
        if (is_array($value)) return self::mapArray($value);
        if ($value instanceof \Illuminate\Support\Collection) return $value->map(fn($v)=>self::forDisplay($v));
        if ($value instanceof \DateTimeInterface) return \Carbon\CarbonImmutable::instance($value)->toIso8601String();
        if ($value instanceof \stdClass) { $copy=clone $value; foreach ($copy as $k=>$v) $copy->$k=self::forDisplay($v,$k); return $copy; }
        if (is_string($value) && str_ends_with($key,'_at') && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/',$value))
            return \Carbon\CarbonImmutable::parse($value,config('app.timezone'))->toIso8601String();
        return $value;
    }
    private static function mapArray(array $values): array
    {
        foreach ($values as $k=>$v) $values[$k]=self::forDisplay($v,(string)$k);
        return $values;
    }

    public static function database(mixed $value): mixed
    {
        if (!$value instanceof DateTimeInterface) { return $value; }
        // Financial dateTimeTz columns have second precision. Sending fractions
        // makes PostgreSQL round .5+ into the next second (even the next business day).
        // Truncate here while preserving the offset, as the SQLite path already does.
        if (DB::getDriverName()==='pgsql') { return $value->format('Y-m-d\TH:i:sP'); }
        // SQLite test fixtures have no timezone-aware type; keep their wall-clock
        // representation in the same application zone used when reading it.
        return \Carbon\CarbonImmutable::instance($value)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }
}
