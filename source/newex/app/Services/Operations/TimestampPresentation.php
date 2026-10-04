<?php
namespace App\Services\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Collection;

/** Convert database timestamps to explicit instants without changing stored records. */
final class TimestampPresentation
{
    public static function normalize(mixed $value, ?string $key = null): mixed
    {
        if (is_string($value) && $key !== null && str_ends_with($key, '_at')
            && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $value)) {
            try {
                return CarbonImmutable::parse($value, config('app.timezone'))->toISOString();
            } catch (\Throwable) {
                return $value;
            }
        }
        if ($value instanceof AbstractPaginator) {
            $copy = clone $value;
            return $copy->setCollection(self::normalize($value->getCollection()));
        }
        if ($value instanceof Collection) {
            return $value->map(fn ($item, $itemKey) => self::normalize($item, (string) $itemKey));
        }
        if (is_array($value)) {
            foreach ($value as $itemKey => $item) $value[$itemKey] = self::normalize($item, (string) $itemKey);
        } elseif ($value instanceof \stdClass) {
            $value = clone $value;
            foreach ($value as $itemKey => $item) $value->{$itemKey} = self::normalize($item, (string) $itemKey);
        }
        return $value;
    }
}
