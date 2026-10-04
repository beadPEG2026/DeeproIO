<?php

namespace App\Domain\Umi\V2;

/** Compare decoded JSON records without depending on object-key insertion order. */
final class Payload
{
    public static function same(mixed $a, mixed $b): bool
    {
        return self::canonical($a) === self::canonical($b);
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonical(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonical($item);
        }
        return $value;
    }
}
