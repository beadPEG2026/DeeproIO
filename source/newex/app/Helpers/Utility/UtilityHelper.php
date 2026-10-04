<?php

const DB_REPEAT_AFTER_DEADLOCK = 5;

// Plain Utility Helper Functions

use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Services\Supervisor\Supervisor;
/*
 * Generate unique uuid
 */
if (!function_exists('generate_uuid')) {
    function generate_uuid()
    {
        return Str::uuid();
    }
}

/*
 * Shorted uuid
 */
if (!function_exists('short_uuid')) {
    function short_uuid($id)
    {
        $short = explode('-', $id);
        return $short[0] . '...';
    }
}


/*
 * Generate unique uuid
 */
if (!function_exists('generate_string')) {
    function generate_string()
    {
        return mb_strtoupper(Str::random(15));
    }
}

/*
 * Get app prefix
 */
if (!function_exists('is_mobile_instance')) {
    function is_mobile_instance()
    {
        return false;
        $agent = new \Laravel\Jetstream\Agent;
        return $agent->isMobile();
    }
}

/*
 * Get EVN Networks
 */
if (!function_exists('get_evm_networks')) {
    function get_evm_networks()
    {
        return [NETWORK_ETH, NETWORK_ERC, NETWORK_BNB, NETWORK_BEP, NETWORK_MATIC, NETWORK_MATIC20, NETWORK_XLAYER, NETWORK_XLAYER20, NETWORK_CUSTOMTOKEN_NETWORK, NETWORK_CUSTOMTOKEN_TOKEN];
    }
}

/*
 * Get Dates
 */
if (!function_exists('get_date_period')) {
    function get_date_period($period)
    {
        $now = Carbon::now();

        $formatStart = "Y-m-d 00:00:01";
        $formatEnd = "Y-m-d 23:23:59";

        $end = $now->format($formatEnd);

        if($period == "week") {
            $start = $now->subWeek()->format($formatStart);
        } elseif($period == "month") {
            $start = $now->subMonth()->format($formatStart);
        } elseif($period == "3months") {
            $start = $now->subMonths(3)->format($formatStart);
        } elseif($period == "6months") {
            $start = $now->subMonths(6)->format($formatStart);
        } elseif($period == "year") {
            $start = $now->subYear()->format($formatStart);
        } else {
            $start = false;
        }

        return [$start, $end];
    }
}

/*
 * Get EVN Networks
 */
if (!function_exists('get_p2p_decimals')) {
    function get_p2p_decimals($symbol)
    {
        if($symbol == "USDT") return 3;
        return 2;
    }
}

/*
 * UUID Valid
 */
if(!function_exists('is_uuid_valid')) {
    function is_uuid_valid($uuid) {
        if (!is_string($uuid) || (preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/i', $uuid) !== 1)) {
            return false;
        }

        return true;
    }
}

/*
 * Date Valid
 */
if(!function_exists('is_date_valid')) {
    function is_date_valid($date, $format = 'Y-m-d') {

        $time = strtotime($date);
        $testDate = date($format, $time);

        if($date == $testDate) {
            return true;
        }

        return false;
    }
}

/*
 * Mask Email
 */
if(!function_exists('mask_nickname')) {
    function mask_nickname($name, $email) {

        if(!$name) {

            $array = explode('.', $email);
            $lastElement = end($array);
            $firstChar = mb_substr($email, 0, 1);

            $email = $firstChar . str_repeat('*', 5) . '.' . $lastElement;

            return [$email, $firstChar];
        }

        return [$name,mb_substr($name, 0, 1)];
    }
}

if (! function_exists('supervisor')) {
    function supervisor(string $prefix = null): Supervisor
    {
        return new Supervisor($prefix);
    }
}
