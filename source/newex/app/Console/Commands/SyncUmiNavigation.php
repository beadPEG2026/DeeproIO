<?php

namespace App\Console\Commands;

use App\Models\Language\{Language, LanguageTranslation};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncUmiNavigation extends Command
{
    protected $signature = 'deepro:sync-umi-navigation';
    protected $description = 'Sync UMI navigation labels for existing languages without changing language preferences';

    public function handle(): int
    {
        $keys = ['UMI Ecosystem', 'UMI Ecosystem Management', 'UMI Business Management'];
        $languages = Language::all();
        // Validate all catalogues before writing any label.
        $catalogues = [];
        foreach ($languages as $language) {
            $path = resource_path('lang/'.$language->slug.'.json');
            if (!is_file($path)) {
                $this->error('Missing catalogue for '.$language->slug);
                return self::FAILURE;
            }
            $catalogue = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            foreach ($keys as $key) {
                if (empty($catalogue[$key])) {
                    $this->error('Missing navigation label for '.$language->slug);
                    return self::FAILURE;
                }
            }
            $catalogues[$language->id] = $catalogue;
        }
        DB::transaction(function () use ($languages, $keys, $catalogues) {
            foreach ($languages as $language) {
                foreach ($keys as $key) {
                    LanguageTranslation::updateOrCreate(
                        ['language_id' => $language->id, 'key' => $key],
                        ['content' => $catalogues[$language->id][$key]]
                    );
                }
            }
        });
        $this->info('UMI navigation synced for '.$languages->count().' languages.');
        return self::SUCCESS;
    }
}
