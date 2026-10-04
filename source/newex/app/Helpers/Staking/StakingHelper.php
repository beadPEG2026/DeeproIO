<?php
/*
 * Get APY by given days
 * 
 * @param int|string $days The staking duration in days
 * @param string $allowed_days Comma-separated list of allowed days (e.g., "7,14,30,60,90")
 * @param string $rewards Comma-separated list of APY percentages (e.g., "1,2,5,10,15")
 * @return string|null The APY percentage for the given days, or null if not found
 */
if (!function_exists('get_apy_by_day')) {
    function get_apy_by_day($days, $allowed_days, $rewards)
    {
        $daysArray = explode(',', $allowed_days);
        $apysArray = explode(',', $rewards);
        
        // Find the index of the requested days
        $key = array_search((string)$days, array_map('trim', $daysArray));
        
        // Validate that the days value was found and APY exists for it
        if ($key === false) {
            // Days not found in allowed_days
            return null;
        }
        
        if (!isset($apysArray[$key])) {
            // No corresponding APY for this days value
            return null;
        }

        return trim($apysArray[$key]);
    }
}
