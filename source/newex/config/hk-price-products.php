<?php

return [
    // Approved 2026-10-01: own HK quotes use existing per-market/default credit limits.
    'platform_quotes' => ['enabled' => true],
    // Data access does not enable issuance, custody, or trading.
    'trading_enabled' => (bool) env('HK_PRICE_PRODUCTS_TRADING_ENABLED', false),
    // Reference freshness is separate from local user-to-user USDT order matching.
    'quote_max_age' => 1200,
    'fx_max_age' => 90,
    // Verified native minute bars, plus the existing daily fallback.
    'chart_default_resolution' => '1D',
    'chart_resolutions' => ['1','5','15','60','1D'],
    // Optional authorized native depth endpoint; absent means no automatic HK reference fills.
    'execution_depth' => [
        'url' => env('HK_EXECUTION_DEPTH_URL'),
        'permission_confirmed' => (bool) env('HK_EXECUTION_DEPTH_PERMISSION_CONFIRMED', false),
    ],
    // Public website access does not confer redistribution permission. Disabled means no request.
    'external_depth' => [
        'enabled' => (bool) env('HK_EXTERNAL_DEPTH_ENABLED', false),
        'source_permission_confirmed' => (bool) env('HK_EXTERNAL_DEPTH_PERMISSION_CONFIRMED', false),
        'provider' => env('HK_EXTERNAL_DEPTH_PROVIDER', 'longbridge_public'),
        'max_age_seconds' => 1200,
        'closed_snapshot_max_age_seconds' => 604800,
    ],
    // An unknown calendar year fails closed. Dates are Hong Kong local dates.
    // HKEX circulars CT/075/25 and CT/077/26. Unknown years remain closed until reviewed.
    'calendar_years' => [2026, 2027],
    'holidays' => ['2026-01-01','2026-02-17','2026-02-18','2026-02-19','2026-04-03','2026-04-06','2026-04-07','2026-05-01','2026-05-25','2026-06-19','2026-07-01','2026-10-01','2026-10-19','2026-12-25',
        '2027-01-01','2027-02-08','2027-02-09','2027-03-26','2027-03-29','2027-04-05','2027-05-13','2027-06-09','2027-07-01','2027-09-16','2027-10-01','2027-10-08','2027-12-27'],
    'half_days' => ['2026-02-16','2026-12-24','2026-12-31','2027-02-05','2027-12-24','2027-12-31'],
    'assets' => [
        'HK00700' => [
            'instrumentType'=>'equity_price_reference', 'marketSource'=>'eastmoney-public',
            'securityCode'=>'00700', 'ticker'=>'00700.HK', 'exchange'=>'HKEX',
            'name'=>'腾讯控股', 'referenceCurrency'=>'HKD', 'region'=>'HK', 'unitRatio'=>'1',
            'tradingEnabled'=>(bool) env('HK00700_TRADING_ENABLED', env('HK_PRICE_PRODUCTS_TRADING_ENABLED', false)),
            'suspended'=>(bool) env('HK00700_SUSPENDED', false),
        ],
        'HK09988' => [
            'instrumentType'=>'equity_price_reference', 'marketSource'=>'eastmoney-public',
            'securityCode'=>'09988', 'ticker'=>'09988.HK', 'exchange'=>'HKEX',
            'name'=>'阿里巴巴-W', 'referenceCurrency'=>'HKD', 'region'=>'HK', 'unitRatio'=>'1',
            'tradingEnabled'=>(bool) env('HK09988_TRADING_ENABLED', env('HK_PRICE_PRODUCTS_TRADING_ENABLED', false)),
            'suspended'=>(bool) env('HK09988_SUSPENDED', false),
        ],
        'HK03690' => [
            'instrumentType'=>'equity_price_reference', 'marketSource'=>'eastmoney-public',
            'securityCode'=>'03690', 'ticker'=>'03690.HK', 'exchange'=>'HKEX',
            'name'=>'美团-W', 'referenceCurrency'=>'HKD', 'region'=>'HK', 'unitRatio'=>'1',
            'tradingEnabled'=>(bool) env('HK03690_TRADING_ENABLED', env('HK_PRICE_PRODUCTS_TRADING_ENABLED', false)),
            'suspended'=>(bool) env('HK03690_SUSPENDED', false),
        ],
        'HK01810' => [
            'instrumentType'=>'equity_price_reference', 'marketSource'=>'eastmoney-public',
            'securityCode'=>'01810', 'ticker'=>'01810.HK', 'exchange'=>'HKEX',
            'name'=>'小米集团-W', 'referenceCurrency'=>'HKD', 'region'=>'HK', 'unitRatio'=>'1',
            'tradingEnabled'=>(bool) env('HK01810_TRADING_ENABLED', env('HK_PRICE_PRODUCTS_TRADING_ENABLED', false)),
            'suspended'=>(bool) env('HK01810_SUSPENDED', false),
        ],
        'HK09618' => [
            'instrumentType'=>'equity_price_reference', 'marketSource'=>'eastmoney-public',
            'securityCode'=>'09618', 'ticker'=>'09618.HK', 'exchange'=>'HKEX',
            'name'=>'京东集团-SW', 'referenceCurrency'=>'HKD', 'region'=>'HK', 'unitRatio'=>'1',
            'tradingEnabled'=>(bool) env('HK09618_TRADING_ENABLED', env('HK_PRICE_PRODUCTS_TRADING_ENABLED', false)),
            'suspended'=>(bool) env('HK09618_SUSPENDED', false),
        ],
        'HK09999' => [
            'instrumentType'=>'equity_price_reference', 'marketSource'=>'eastmoney-public',
            'securityCode'=>'09999', 'ticker'=>'09999.HK', 'exchange'=>'HKEX',
            'name'=>'网易-S', 'referenceCurrency'=>'HKD', 'region'=>'HK', 'unitRatio'=>'1',
            'tradingEnabled'=>(bool) env('HK09999_TRADING_ENABLED', env('HK_PRICE_PRODUCTS_TRADING_ENABLED', false)),
            'suspended'=>(bool) env('HK09999_SUSPENDED', false),
        ],
        'HK01024' => [
            'instrumentType'=>'equity_price_reference', 'marketSource'=>'eastmoney-public',
            'securityCode'=>'01024', 'ticker'=>'01024.HK', 'exchange'=>'HKEX',
            'name'=>'快手-W', 'referenceCurrency'=>'HKD', 'region'=>'HK', 'unitRatio'=>'1',
            'tradingEnabled'=>(bool) env('HK01024_TRADING_ENABLED', env('HK_PRICE_PRODUCTS_TRADING_ENABLED', false)),
            'suspended'=>(bool) env('HK01024_SUSPENDED', false),
        ],
        'HK01211' => [
            'instrumentType'=>'equity_price_reference', 'marketSource'=>'eastmoney-public',
            'securityCode'=>'01211', 'ticker'=>'01211.HK', 'exchange'=>'HKEX',
            'name'=>'比亚迪股份', 'referenceCurrency'=>'HKD', 'region'=>'HK', 'unitRatio'=>'1',
            'tradingEnabled'=>(bool) env('HK01211_TRADING_ENABLED', env('HK_PRICE_PRODUCTS_TRADING_ENABLED', false)),
            'suspended'=>(bool) env('HK01211_SUSPENDED', false),
        ],
        'HK00981' => [
            'instrumentType'=>'equity_price_reference', 'marketSource'=>'eastmoney-public',
            'securityCode'=>'00981', 'ticker'=>'00981.HK', 'exchange'=>'HKEX',
            'name'=>'中芯国际', 'referenceCurrency'=>'HKD', 'region'=>'HK', 'unitRatio'=>'1',
            'tradingEnabled'=>(bool) env('HK00981_TRADING_ENABLED', env('HK_PRICE_PRODUCTS_TRADING_ENABLED', false)),
            'suspended'=>(bool) env('HK00981_SUSPENDED', false),
        ],
        'HK00388' => [
            'instrumentType'=>'equity_price_reference', 'marketSource'=>'eastmoney-public',
            'securityCode'=>'00388', 'ticker'=>'00388.HK', 'exchange'=>'HKEX',
            'name'=>'香港交易所', 'referenceCurrency'=>'HKD', 'region'=>'HK', 'unitRatio'=>'1',
            'tradingEnabled'=>(bool) env('HK00388_TRADING_ENABLED', env('HK_PRICE_PRODUCTS_TRADING_ENABLED', false)),
            'suspended'=>(bool) env('HK00388_SUSPENDED', false),
        ],
        'HK01299' => [
            'instrumentType'=>'equity_price_reference', 'marketSource'=>'eastmoney-public',
            'securityCode'=>'01299', 'ticker'=>'01299.HK', 'exchange'=>'HKEX',
            'name'=>'友邦保险', 'referenceCurrency'=>'HKD', 'region'=>'HK', 'unitRatio'=>'1',
            'tradingEnabled'=>(bool) env('HK01299_TRADING_ENABLED', env('HK_PRICE_PRODUCTS_TRADING_ENABLED', false)),
            'suspended'=>(bool) env('HK01299_SUSPENDED', false),
        ],
        'HK00941' => [
            'instrumentType'=>'equity_price_reference', 'marketSource'=>'eastmoney-public',
            'securityCode'=>'00941', 'ticker'=>'00941.HK', 'exchange'=>'HKEX',
            'name'=>'中国移动', 'referenceCurrency'=>'HKD', 'region'=>'HK', 'unitRatio'=>'1',
            'tradingEnabled'=>(bool) env('HK00941_TRADING_ENABLED', env('HK_PRICE_PRODUCTS_TRADING_ENABLED', false)),
            'suspended'=>(bool) env('HK00941_SUSPENDED', false),
        ],
        'HK08379' => [
            'instrumentType' => 'equity_price_reference', 'marketSource' => 'eastmoney-public',
            'securityCode' => '08379', 'ticker' => '08379.HK', 'exchange' => 'HKEX',
            'name' => '盈证国际', 'referenceCurrency' => 'HKD', 'region' => 'HK',
            'unitRatio' => '1', 'tradingEnabled' => (bool) env('HK08379_TRADING_ENABLED', false),
            'suspended' => (bool) env('HK08379_SUSPENDED', false),
        ],
    ],
];
