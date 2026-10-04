<?php
namespace App\Support;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
final class Decimal
{
    /** Normalize monetary text without a binary floating-point round trip. */
    public static function normalize($value, int $scale = 18): string
    {
        if ($value === null || $value === '') return '0';
        $value = trim(str_replace(',', '', (string)$value));
        if (!is_numeric($value)) return '0';
        try { $result = (string) BigDecimal::of($value)->toScale($scale, RoundingMode::DOWN); }
        catch (\Brick\Math\Exception\MathException $e) { return '0'; }
        if (str_contains($result,'.')) $result=rtrim(rtrim($result,'0'),'.');
        return $result === '-0' || $result === '' ? '0' : $result;
    }
}
