<?php
return [
    'asset' => ['symbol' => 'UMI', 'name' => 'Umi Token', 'chain' => 'BSC', 'chain_id' => 56,
        'contract' => '0xa1bc94946fc3479fe5602be67c7b554335423a4a', 'decimals' => 18,
        'explorer' => 'https://bscscan.com/token/0xa1bc94946fc3479fe5602be67c7b554335423a4a',
        'deposits_enabled' => env('UMI_BSC_DEPOSITS_ENABLED', false),
        'withdrawals_enabled' => env('UMI_BSC_WITHDRAWALS_ENABLED', false)],
    // Historical settings are evidence, never a license to run a new settlement job.
    'observed_transfer_rates' => ['1' => '0.30', '2' => '0.30', '3' => '0.00'],
    'settlement_enabled' => false,
];
