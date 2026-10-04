<?php
namespace App\Console\Commands\Umi;
use Illuminate\Console\Command;
use App\Services\Umi\LegacyImporter;
class ImportLegacy extends Command {
    protected $signature='umi:import {directory} {--commit : 导入已校验的档案；默认只核查}';
    protected $description='导入 UMI 原始档案和关系，不创建可消费余额或密码';
    public function handle(LegacyImporter $importer): int {
        try {$this->line(json_encode($importer->import($this->argument('directory'),$this->option('commit')),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));return self::SUCCESS;}
        catch(\Throwable $e) {$this->error($e->getMessage());return self::FAILURE;}
    }
}
