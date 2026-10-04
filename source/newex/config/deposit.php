<?php

return [
    /*
     * Percentage added to the amount that is actually credited to a user's
     * wallet after the existing deposit/network/fiat fees are deducted.
     *
     * Keep this as a string so BCMath can calculate it without converting
     * cryptocurrency amounts to floating point numbers.
     */
    'credit_bonus_percent' => '0',
];
