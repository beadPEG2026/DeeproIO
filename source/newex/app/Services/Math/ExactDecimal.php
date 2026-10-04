<?php
namespace App\Services\Math;
final class ExactDecimal {
    public static function normalize($value, int $scale=18): string {
        if ($value===null || $value==='') $value='0';
        $value=trim((string)$value);
        if ($scale<0 || $scale>36 || !preg_match('/^([+-]?(?:\d+(?:\.\d*)?|\.\d+))(?:[eE]([+-]?\d{1,3}))?$/D',$value,$parts)) throw new \InvalidArgumentException('Invalid decimal amount.');
        $exponent=(int)($parts[2]??0);
        if (abs($exponent)>100 || strlen($parts[1])>120) throw new \InvalidArgumentException('Decimal amount is out of range.');
        $plain=$parts[1];
        if ($exponent>0) return bcmul($plain,'1'.str_repeat('0',$exponent),$scale);
        if ($exponent<0) return bcdiv($plain,'1'.str_repeat('0',-$exponent),$scale);
        return bcadd($plain,'0',$scale);
    }
}
