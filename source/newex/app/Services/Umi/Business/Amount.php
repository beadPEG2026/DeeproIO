<?php
namespace App\Services\Umi\Business;
use Illuminate\Validation\ValidationException;
final class Amount {
    const SCALE=24;
    public static function valid(mixed $n, bool $positive=false):string {
        if(!is_string($n)||!preg_match('/^(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,24})?$/D',$n)||($positive&&self::cmp($n,'0')<=0))throw ValidationException::withMessages(['amount'=>__('请输入有效的十进制金额，最多保留 24 位小数。')]);
        return self::add($n,'0');
    }
    public static function add(string $a,string $b):string{return bcadd($a,$b,self::SCALE);}
    public static function sub(string $a,string $b):string{return bcsub($a,$b,self::SCALE);}
    public static function mul(string $a,string $b):string{return bcmul($a,$b,self::SCALE);}
    public static function div(string $a,string $b):string{return bcdiv($a,$b,self::SCALE);}
    public static function cmp(string $a,string $b):int{return bccomp($a,$b,self::SCALE);}
    public static function min(string $a,string $b):string{return self::cmp($a,$b)<0?$a:$b;}
    public static function max(string $a,string $b):string{return self::cmp($a,$b)>0?$a:$b;}
    public static function sum(iterable $xs):string{$sum='0';foreach($xs as $x)$sum=self::add($sum,(string)$x);return $sum;}
    public static function display(string $n):string{return str_contains($n,'.')?rtrim(rtrim($n,'0'),'.'):$n;}
}
