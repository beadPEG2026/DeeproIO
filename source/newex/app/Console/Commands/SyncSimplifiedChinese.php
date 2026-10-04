<?php

namespace App\Console\Commands;

use App\Models\Language\{Language, LanguageTranslation};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncSimplifiedChinese extends Command
{
    protected $signature = 'deepro:sync-simplified-chinese';
    protected $description = 'Register Simplified Chinese and import its versioned translation catalogue';

    public function handle(): int
    {
        $catalogue = json_decode(file_get_contents(resource_path('lang/zh-cn.json')), true, 512, JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($catalogue) {
            $language = Language::firstOrCreate(['slug' => 'zh-cn'], ['name' => '简体中文', 'status' => true, 'is_default' => false]);
            foreach ($catalogue as $key => $value) {
                LanguageTranslation::updateOrCreate(['language_id' => $language->id, 'key' => $key], ['content' => $value]);
            }
        });
        $this->info('Simplified Chinese catalogue registered: '.count($catalogue).' entries. Existing default language retained.');
        return self::SUCCESS;
    }
}
