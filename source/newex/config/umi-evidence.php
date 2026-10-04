<?php
// Evidence classification only. Does not alter earning rates, balances or settlement.
return [
    'version'=>'legacy-evidence-20260918-v3',
    'observed_on'=>'2026-09-16',
    'reviewed_on'=>'2026-09-18',
    'sources'=>[
        ['id'=>'review','label'=>'2026-09-18 规则补齐复核','reference'=>'development/2026-09-18-umi-review/规则补齐复核报告.md','scope'=>'原档案高精度复算与公开编译代码；候选不覆盖原余额和历史订单'],
        ['id'=>'reverse','label'=>'2026-09-17 原机制逆推与逐户复算','reference'=>'docs/2026-09-17-UMI原机制逆推/01-原机制逆推报告.md','scope'=>'353 个账户；当日匹配不证明全历史或原服务器代码一致'],
        ['id'=>'export','label'=>'原后台导出及伞下 CSV','reference'=>'umi_source_files / umi_legacy_accounts / umi_legacy_records / umi_legacy_summaries','scope'=>'353 个用户、351 个完整 CSV 汇总；不是完整数据库'],
        ['id'=>'h5','label'=>'原 H5 与后台公开编译代码、接口观察','reference'=>'161 个 JS / 209 个请求定义','scope'=>'管理端页面代码不是原服务端结算源码；13 类关键接口未取得内容'],
        ['id'=>'materials','label'=>'UMI 补充资料正文和配图','reference'=>'UMI 补充资料与项目介绍','scope'=>'材料约定，与运行记录分开核验'],
    ],
    'rules'=>[
        ['id'=>'relationships','name'=>'原关系与等级','status'=>'sample_reconstructed','known'=>'353 户关系、个人/团队/小区业绩和当前等级均匹配。个人业绩按 burn_type=1；CSV 购买/赠送来源按 source 分类，两个维度不能混用。','implementation'=>'原 UID、父 UID、等级及排除标记保留；绑定不会改变原上级。','missing'=>'范围外父节点 365170、历史等级轨迹及手动等级实际执行样本缺失。','sources'=>['export','review','reverse'],'execution'=>false],
        ['id'=>'burn_quota','name'=>'燃烧与额度','status'=>'historical_verified','known'=>'268 条原记录按原数量、倍数和价格可复算。122 项差异是 90 项额度来源差异和 32 项档位例外；32 项全部为管理员创建。','implementation'=>'按快照总额度、已用额度接续，保存原订单与差异；不按新档位重算。','missing'=>'67 个正差仅与数量截断范围相容，不能证实原因；重复赠送、删除与调整需要原操作凭证。','sources'=>['export','review'],'execution'=>false],
        ['id'=>'quota_profit','name'=>'收益与额度消耗','status'=>'historical_verified','known'=>'351 户已用额度等于线性、团队、直推、宝利息累计之和；可提资产的五项构成亦逐户吻合。','implementation'=>'共享剩余额度封顶，日结唯一键与账本事务已实现；新版本明确按线性、团队、宝利息、平级处理。','missing'=>'原历史跨单分摊、额度紧张时顺序、回滚和调整凭证仍缺。新版本顺序不是原站已验证顺序。','sources'=>['export','reverse'],'execution'=>false],
        ['id'=>'daily_release','name'=>'日收益释放','status'=>'sample_reconstructed','known'=>'70 个干净单订单样本支持 0.8%/1%。扩展到 192 个有完成订单且有 CSV 的账户，在 0、0.8%、1% 候选中：188 个唯一解释、3 个多解、1 个无解；不是恢复了原计划表。','implementation'=>'历史接续采用 9 月 16 日原 CSV 日释放量及剩余额度；不重新激活全部历史完成订单。','missing'=>'0 日量不能区分暂停、完成或替换；候选唯一性只限已测试比例。UID 369641 需加入删除记录才可拟合，不能据此恢复该订单。','sources'=>['export','h5','review'],'execution'=>false],
        ['id'=>'referral','name'=>'直推与团队返佣','status'=>'sample_reconstructed','known'=>'直推按购买量 10%、事件时点资格与额度封顶候选，353 户累计 UMI 和 USDT 均匹配；当日团队级差及购买/赠送分项匹配汇总。','implementation'=>'按保留的关系树运行接续；原累计收益继承，不把旧购买重放为新直推事件。','missing'=>'当前等级倒推历史团队只匹配 3/29 个有收益账户；V4+ 没有实际样本，最近同级不递归属于新版本补全规则。','sources'=>['export','h5','reverse'],'execution'=>false],
        ['id'=>'earnings_transfer','name'=>'收益划转费率','status'=>'conflicting','known'=>'9 月 16 日 H5：线性 30%、团队 30%、直推 0%。30 个独立历史直推提取样本中，17 个符合 30%、11 个为零、2 个混合。','implementation'=>'按现行分类型费率处理新领取；费率版本、毛额、费用、净额分别记账，原历史不重新扣费。','missing'=>'旧费率变化时间、逐笔费用用途、真实回购与销毁凭证不能由累计数唯一恢复。','sources'=>['h5','export','reverse'],'execution'=>false],
        ['id'=>'treasure','name'=>'UMI 宝','status'=>'sample_reconstructed','known'=>'85 个干净样本符合日初本金 × 0.1%，然后自动存入线性与团队；347/351 户累计自动存入与线性加团队一致。','implementation'=>'继承原宝本金；新日按期初本金计息，自动存入不重复算收益。提取费来源作为版本参数保存。','missing'=>'36 个有提取费账户中 35 个符合推导毛额的 30%；费用内扣还是另扣储备及原历史调整，不能由汇总唯一确定。','sources'=>['export','h5','reverse'],'execution'=>false],
        ['id'=>'vip','name'=>'VIP 质押','status'=>'client_verified','known'=>'VIP1–4 与团队 V1–V9 分开。H5 显示解押等待 48 小时；管理端明确等待时间变更只影响新解押。','implementation'=>'质押、解押等待、到期释放与费用快照已实现；原质押订单缺失时不伪造历史订单。','missing'=>'原实际锁仓单、逐笔 unlock_at、完整费率版本及到账记录尚缺。客户端默认值不是数据库真实参数。','sources'=>['h5','review'],'execution'=>false],
        ['id'=>'reserve','name'=>'储备金与股票积分','status'=>'client_verified','known'=>'H5 储备页提交 UMI 存入数量，余额读 burn_umi_balance；股票积分读累计 burn_umi_consumed。宝提取管理表保留本金、主余额、储备金前后值。','implementation'=>'原储备余额独立保存；新版本储备消费与内部积分记账，不代表已交付股份。','missing'=>'无实际消费分录时，费用扣款来源、积分撤销和股票兑换比率/履约不能唯一恢复。','sources'=>['export','h5','materials'],'execution'=>false],
        ['id'=>'terminal','name'=>'2100 万枚终局机制','status'=>'unidentifiable','known'=>'材料描述停止销毁、关闭动态模式及映射独立公链；现有业务导出和公开客户端没有足以核实执行的证据。','implementation'=>'保留材料说明；没有将推测阈值或映射行为接入自动结算。','missing'=>'需原执行合约或服务端实现、供应口径、跨阈值订单处理和映射协议；现有汇总无法推出这些内容。','sources'=>['materials','h5'],'execution'=>false],
    ],
];
