<?php
return [
    'enabled'=>env('UMI_BUSINESS_LOCAL',false),
    'cloud_read_only'=>env('UMI_BUSINESS_CLOUD_READ_ONLY',false),
    'funded_live'=>env('UMI_BUSINESS_FUNDED_LIVE',false),
    'defaults'=>[
        'price'=>'1.23','purchase_daily_rate'=>'0.008','gift_daily_rate'=>'0.008','referral_rate'=>'0.10',
        'treasure_daily_rate'=>'0.001','treasure_fee'=>'0.30','treasure_fee_source'=>'proceeds',
        'linear_fee'=>'0.30','team_fee'=>'0.30','referral_fee'=>'0','swap_fee'=>'0','transfer_fee'=>'0',
        'unstake_minutes'=>2880,'vip_fee'=>'0','peer_rate'=>'0.10','peer_min_level'=>4,
        'peer_policy'=>'nearest_equal_nonrecursive','timezone'=>'Asia/Shanghai',
        'tiers'=>[['min'=>'100','max'=>'2000','multiplier'=>'3'],['min'=>'2000','max'=>'5000','multiplier'=>'4'],['min'=>'5000','max'=>null,'multiplier'=>'5']],
        'ranks'=>array_map(fn($i,$n)=>['level'=>$i,'personal'=>'100','small'=>(string)$n,'rate'=>bcdiv((string)$i,'10',2)],range(1,9),[500,3000,10000,100000,300000,1000000,3000000,5000000,10000000]),
        'vip_thresholds'=>['10','21','51','101'],
        'sequence'=>['linear','team','interest','peer','deposit','unlock'],
        'assumptions'=>[
            '新单默认日释放 0.8%；历史单必须保留各自比例，不能按新配置覆盖。',
            '平级按最近同级 V4+ 的本次日结线性、团队、宝利息及前一业务日直推合计乘 10%；不递归包含平级。此项仅为本地补全方案，未被原 V4+ 样本验证。',
            '额度不足按线性、团队、宝利息、平级的顺序截断；相同类别按来源 ID 排序。原历史执行顺序尚无完整记录。',
            '宝提取默认从提取额内扣费，可独立切换为储备扣费；储备消费按 1:1 记内部积分，不代表已交付股票或链上销毁。',
            'VIP 档位按最小门槛晋升以处理小数区间，费率仅作用于本模块解押；原外部提币费率不在此覆盖。',
            '业务日期仅用于本地验收推进；没有连接真实链上支付、没有回写或重发原历史权益。',
        ],
    ],
];
