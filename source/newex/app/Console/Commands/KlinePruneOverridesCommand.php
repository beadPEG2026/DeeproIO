<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;

class KlinePruneOverridesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * --days=2：只保留最近 2 天。
     * --market_id=14：只清理指定 market_id，不传则清理全部市场。
     * --dry-run：只预览，不真正写入文件。
     */
    protected $signature = 'kline:prune-overrides
                            {--days=2 : Keep recent N days}
                            {--market_id= : Only clean one market id}
                            {--dry-run : Preview only, do not write files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune local kline override JSON files and keep recent data only';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $marketId = $this->option('market_id');
        $dryRun = (bool) $this->option('dry-run');

        $baseDir = storage_path('app/market_kline_overrides');

        if (!is_dir($baseDir)) {
            $this->info('market_kline_overrides directory does not exist.');
            return self::SUCCESS;
        }

        $cutoffTs = Carbon::now()->subDays($days)->timestamp;
        $cutoffText = Carbon::createFromTimestamp($cutoffTs)->toDateTimeString();

        $marketDirs = [];

        if ($marketId) {
            $dir = $baseDir . DIRECTORY_SEPARATOR . (int) $marketId;

            if (is_dir($dir)) {
                $marketDirs[] = $dir;
            }
        } else {
            $marketDirs = glob($baseDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
        }

        if (empty($marketDirs)) {
            $this->info('No market kline override directories found.');
            return self::SUCCESS;
        }

        $cleanedFiles = 0;
        $removedRows = 0;
        $checkedFiles = 0;

        foreach ($marketDirs as $marketDir) {
            $files = glob($marketDir . DIRECTORY_SEPARATOR . '*.json') ?: [];

            foreach ($files as $file) {
                $filename = basename($file);

                /**
                 * 当前百分比状态文件不能清理。
                 */
                if ($filename === 'percent_state.json') {
                    continue;
                }

                $checkedFiles++;

                if ($filename === 'change_logs.json') {
                    $result = $this->pruneChangeLogs($file, $cutoffTs, $dryRun);
                } else {
                    $resolution = pathinfo($filename, PATHINFO_FILENAME);
                    $result = $this->pruneKlineFile($file, $resolution, $cutoffTs, $dryRun);
                }

                if ($result['changed']) {
                    $cleanedFiles++;
                    $removedRows += $result['removed'];
                }
            }
        }

        $prefix = $dryRun ? '[DRY RUN] ' : '';

        $this->info($prefix . "Kline prune completed. Checked files: {$checkedFiles}, cleaned files: {$cleanedFiles}, removed rows: {$removedRows}, keep days: {$days}, cutoff: {$cutoffText}.");

        return self::SUCCESS;
    }

    protected function pruneKlineFile(string $file, string $resolution, int $cutoffTs, bool $dryRun = false): array
    {
        $rows = $this->readJsonFile($file);

        if (!is_array($rows) || empty($rows)) {
            return [
                'changed' => false,
                'removed' => 0,
            ];
        }

        $beforeCount = count($rows);
        $resolutionSeconds = $this->resolutionToSeconds($resolution);
        $kept = [];

        foreach ($rows as $key => $row) {
            $timestamp = 0;

            if (is_array($row) && !empty($row['t'])) {
                $timestamp = $this->normalizeTimestamp($row['t']);
            } elseif (is_numeric($key)) {
                $timestamp = $this->normalizeTimestamp($key);
            }

            if ($timestamp <= 0) {
                continue;
            }

            /**
             * 只保留最近 N 天仍然覆盖到的 K 线。
             * 例如 1D / 1W 这种大周期，只要这根 K 线结束时间还在 cutoff 后，就保留。
             */
            $barEndTs = $timestamp + $resolutionSeconds;

            if ($barEndTs >= $cutoffTs) {
                $kept[(string) $timestamp] = $row;
            }
        }

        ksort($kept);

        $afterCount = count($kept);
        $removed = $beforeCount - $afterCount;

        if ($removed <= 0) {
            return [
                'changed' => false,
                'removed' => 0,
            ];
        }

        if (!$dryRun) {
            $this->safeWriteJsonFile($file, $kept);
        }

        $this->line(($dryRun ? '[DRY RUN] ' : '') . "Pruned {$file}, removed {$removed} rows.");

        return [
            'changed' => true,
            'removed' => $removed,
        ];
    }

    protected function pruneChangeLogs(string $file, int $cutoffTs, bool $dryRun = false): array
    {
        $logs = $this->readJsonFile($file);

        if (!is_array($logs) || empty($logs)) {
            return [
                'changed' => false,
                'removed' => 0,
            ];
        }

        $beforeCount = count($logs);
        $kept = [];

        foreach ($logs as $log) {
            $startTs = 0;

            if (is_array($log)) {
                $startTs = (int) ($log['start_timestamp'] ?? 0);

                if ($startTs <= 0 && !empty($log['start_time'])) {
                    $startTs = strtotime($log['start_time']) ?: 0;
                }
            }

            if ($startTs >= $cutoffTs) {
                $kept[] = $log;
            }
        }

        $removed = $beforeCount - count($kept);

        if ($removed <= 0) {
            return [
                'changed' => false,
                'removed' => 0,
            ];
        }

        if (!$dryRun) {
            $this->safeWriteJsonFile($file, array_values($kept));
        }

        $this->line(($dryRun ? '[DRY RUN] ' : '') . "Pruned {$file}, removed {$removed} logs.");

        return [
            'changed' => true,
            'removed' => $removed,
        ];
    }

    protected function readJsonFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $content = file_get_contents($path);

        if ($content === false || trim($content) === '') {
            return [];
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : [];
    }

    protected function safeWriteJsonFile(string $path, array $data): void
    {
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (!is_dir($dir) || !is_writable($dir)) {
            $this->error('Directory is not writable: ' . $dir);
            return;
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            $this->error('JSON encode failed: ' . $path . ' - ' . json_last_error_msg());
            return;
        }

        /**
         * 每次使用唯一 tmp 文件，避免和 ChartController 并发写同一个 .tmp。
         */
        $tmpPath = $path . '.' . getmypid() . '.' . uniqid('', true) . '.tmp';

        $bytes = file_put_contents($tmpPath, $json, LOCK_EX);

        if ($bytes === false || !is_file($tmpPath)) {
            $this->error('Temp file write failed: ' . $tmpPath);
            return;
        }

        if (!rename($tmpPath, $path)) {
            @unlink($tmpPath);
            $this->error('Temp file rename failed: ' . $path);
            return;
        }

        @chmod($path, 0644);
    }

    protected function normalizeTimestamp($timestamp): int
    {
        $timestamp = (int) $timestamp;

        if ($timestamp > 20000000000) {
            return (int) floor($timestamp / 1000);
        }

        return $timestamp;
    }

    protected function resolutionToSeconds(string $resolution): int
    {
        $resolution = trim($resolution);

        if ($resolution === '') {
            return 60;
        }

        if (is_numeric($resolution)) {
            return max(1, (int) $resolution) * 60;
        }

        $upper = strtoupper($resolution);

        if ($upper === 'D') {
            return 86400;
        }

        if ($upper === 'W') {
            return 604800;
        }

        if ($upper === 'M') {
            return 2592000;
        }

        if (preg_match('/^([0-9]+)D$/', $upper, $matches)) {
            return max(1, (int) $matches[1]) * 86400;
        }

        if (preg_match('/^([0-9]+)W$/', $upper, $matches)) {
            return max(1, (int) $matches[1]) * 604800;
        }

        if (preg_match('/^([0-9]+)M$/', $upper, $matches)) {
            return max(1, (int) $matches[1]) * 2592000;
        }

        if (preg_match('/^([0-9]+)H$/', $upper, $matches)) {
            return max(1, (int) $matches[1]) * 3600;
        }

        return 60;
    }
}
