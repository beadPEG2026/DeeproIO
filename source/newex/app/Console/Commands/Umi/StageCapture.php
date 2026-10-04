<?php
namespace App\Console\Commands\Umi;
use App\Services\Umi\V2\CaptureSnapshots;
use Illuminate\Console\Command;
final class StageCapture extends Command
{
    protected $signature='umi:stage-capture {directory} {--actor= : 实际管理员用户 ID} {--commit : 写入独立暂存表；默认只核查}';
    protected $description='校验采集清单并创建不可覆盖的 UMI 快照批次，不改变身份、余额或收益';
    public function handle(CaptureSnapshots $snapshots): int
    {
        try {
            $capture=$snapshots->read($this->argument('directory'));
            $result=['manifest_sha256'=>$capture['manifest_sha256'],'members'=>count($capture['accounts']),
                'principal_umi'=>$capture['principal_umi'],'staged'=>false];
            if ($this->option('commit')) {
                $batch=$snapshots->stage($capture,(int)$this->option('actor'));
                $result+=['batch_id'=>$batch->id]; $result['staged']=true;
            }
            $this->line(json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)); return self::SUCCESS;
        } catch (\Throwable $e) { $this->error($e->getMessage()); return self::FAILURE; }
    }
}
