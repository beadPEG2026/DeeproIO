<?php

// Plain Math PHP Functions

/*
 * Safe math sum operation
 */
if (!function_exists('math_sum')) {
    function math_sum($num1, $num2)
    {
        return bcadd($num1, $num2, math_scale());
    }
}

/*
 * Safe math sub operation
 */
if (!function_exists('math_sub')) {
    function math_sub($num1, $num2)
    {
        return bcsub($num1, $num2, math_scale());
    }
}

/*
 * Safe math multiply operation
 */
if (!function_exists('math_multiply')) {
    function math_multiply($num1, $num2)
    {
        return bcmul($num1, $num2, math_scale());
    }
}

/*
 * Safe math divide operation
 */
if (!function_exists('math_divide')) {
    function math_divide($num1, $num2, $scale = false)
    {
        if(!$scale) {
            $scale = math_scale();
        }

        return bcdiv((string)$num1, (string)$num2, $scale);
    }
}

/*
 * Safe math pow
 */
if (!function_exists('math_pow')) {
    function math_pow($num1, $num2, $pow = 0.1)
    {
        return bcpow($pow, $num1, $num2);
    }
}

/*
 * Safe math percentage
 */
if (!function_exists('math_percentage')) {
    function math_percentage($quantity, $percentage = 100)
    {
        return math_divide(math_multiply($quantity, $percentage), 100);
    }
}

/*
 * Safe math compare
 */
if (!function_exists('math_compare')) {
    function math_compare($number, $number2)
    {
        return bccomp($number, $number2, math_scale());
    }
}

/*
 * Safe math formatter
 */
if (!function_exists('math_formatter')) {
    function math_formatter($number, $digits, $fiat = false, $force = false)
    {

        //$number = sprintf("%.20f", $number);
        $number = (string)$number;

        if($digits >= 21) $digits = 21;

        $coef = 1;

        if($digits == 0) $coef = 0;

        if(mb_strpos($number,'.')!==false) {

            $formatted =  rtrim(rtrim($number,'0'),'.');

            if(mb_strpos($formatted,'.') == false) {
                return $formatted;
            }

            $lr = explode('.', $number);

            if(isset($lr[1])) {
                if(mb_strlen($lr[1]) <= 8 && !$fiat && !$force) {
                    $digits = mb_strlen($lr[1]);
                }
            }

            return mb_substr($formatted, 0, ((mb_strpos($formatted, '.')+$coef) + $digits));

        } else {
            return $number;
        }
    }
}

/*
 * Safe math get scale
 */
if (!function_exists('math_scale')) {
    function math_scale()
    {
        return MATH_SCALE_FULL;
    }
}

/*
 * Safe math percentage between two numbers
 */
if (!function_exists('math_percentage_between')) {
    function math_percentage_between($new, $old)
    {
        if($new == 0 && $old == 0) return 0.00;

        if($old == 0) return 100.00;

        return math_formatter((($new - $old) / ($old) * 100), 2);
    }
}

/*
 * Safe math percentage between two numbers
 */
if (!function_exists('math_percentage_of')) {
    function math_percentage_of($value, $total)
    {
        if($value == 0 || $total == 0) return 0;

        return math_formatter(($value / $total * 100), 2);
    }
}

/*
 * Safe math percentage progress
 */
if (!function_exists('math_percentage_progress')) {
    function math_percentage_progress($min, $max)
    {
        if($min == 0) return 0.00;

        return math_formatter(($min / $max) * 100, 2);
    }
}


/*
 * Safe math get decimal scale
 */
if (!function_exists('math_scale_decimal')) {
    function math_scale_decimal()
    {
        return MATH_SCALE_DECIMALS;
    }
}

/*
 * Safe math get decimal scale
 */
if (!function_exists('math_decimal_validation')) {
    function math_decimal_validation($value, $decimals)
    {
        return preg_match("/^[0-9]+(\.[0-9]{1,$decimals})?$/", $value);
    }
}

/*
 * Safe math get decimal scale
 */
if (!function_exists('math_decimal_scale_count')) {
    function math_decimal_scale_count($value)
    {
        return pow(10, $value);
    }
}
