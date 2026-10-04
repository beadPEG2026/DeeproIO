<?php
namespace App\Console\Commands\Umi;
use Illuminate\Console\Command;
use App\Services\Umi\LegacyReconciliation;

final class ReconcileLegacy extends Command {
    protected $signature='umi:reconcile {--account= : 原 UID} {--summary : 只输出汇总，不含逐项结果}';
    protected $description='只读复算 UMI 原额度、收益、资产构成及重叠流水，不改变原资料或资金';
    public function handle(LegacyReconciliation $service):int {
        $id=$this->option('account');if($id!==null&&(!ctype_digit($id)||(int)$id<1)){$this->error('原 UID 无效。');return self::FAILURE;}
        $report=$service->report($id!==null?(int)$id:null);if($this->option('summary'))unset($report['checks']);
        $this->line(json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        return $report['counts']['invalid']>0?self::FAILURE:self::SUCCESS;
    }
}
