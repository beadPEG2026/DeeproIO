<?php
namespace App\Console\Commands;

use App\Models\Language\Language;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncUiCatalogues extends Command
{
    protected $signature = 'deepro:sync-ui-catalogues {--apply : Import the validated catalogues} {--backup= : Required rollback JSON path when applying}';
    protected $description = 'Validate and synchronize UI catalogues without changing language settings or business records';

    private function containsTerm(string $text, string $term): bool
    {
        // ASCII token boundaries retain names next to Chinese text without matching identifiers.
        return preg_match('/(?<![A-Za-z0-9_])'.preg_quote($term, '/').'(?![A-Za-z0-9_])/u', $text) === 1;
    }

    public function handle(): int
    {
        $catalogues = [];
        $terminology = json_decode(file_get_contents(resource_path('lang/terminology/chinese.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach (Language::orderBy('id')->get() as $language) {
            $path = resource_path('lang/'.$language->slug.'.json');
            if (!is_file($path)) { $this->error('Missing catalogue: '.$language->slug); return self::FAILURE; }
            $values = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            foreach ($values as $key => $value) {
                if (!is_string($value) || trim($value) === '' || mb_strlen($key) > 512) {
                    $this->error('Invalid catalogue entry: '.$language->slug); return self::FAILURE;
                }
            }
            if (in_array($language->slug, $terminology['locales'], true)) {
                foreach ($values as $key => $value) {
                    foreach ($terminology['terms'] as $term) {
                        if (in_array($key, $term['exceptKeys'] ?? [], true)) continue;
                        foreach ($term['sources'] as $source) {
                            if ($this->containsTerm($key, $source) && !$this->containsTerm($value, $term['name'])) {
                                $this->error('Protected terminology missing: '.$language->slug.' / '.$key.' / '.$term['name']);
                                return self::FAILURE;
                            }
                        }
                    }
                }
            }
            $catalogues[$language->id] = $values;
            $this->line($language->slug.': '.count($values).' entries');
        }
        if (!$this->option('apply')) return self::SUCCESS;
        $path = $this->option('backup');
        if (!$path || file_exists($path) || !is_writable(dirname($path))) {
            $this->error('Provide a new backup path in a writable directory.'); return self::FAILURE;
        }
        DB::transaction(function () use ($catalogues, $path) {
            $before = DB::table('language_translations')->whereIn('language_id', array_keys($catalogues))->lockForUpdate()->get();
            $backup = ['rows' => $before, 'keys' => array_map('array_keys', $catalogues)];
            if (file_put_contents($path, json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new \RuntimeException('Cannot save translation rollback file');
            chmod($path, 0600);
            foreach ($catalogues as $id => $values) {
                $existing = $before->where('language_id', $id)->groupBy('key');
                $updates = []; $inserts = [];
                foreach ($values as $key => $content) {
                    $matches = $existing->get($key);
                    if ($matches) {
                        foreach ($matches as $row) if ($row->content !== $content) $updates[] = ['id' => $row->id, 'language_id' => $id, 'key' => $key, 'content' => $content, 'created_at' => $row->created_at, 'updated_at' => now()];
                    } else $inserts[] = ['language_id' => $id, 'key' => $key, 'content' => $content, 'created_at' => now(), 'updated_at' => now()];
                }
                foreach (array_chunk($updates, 200) as $chunk) DB::table('language_translations')->upsert($chunk, ['id'], ['content', 'updated_at']);
                foreach (array_chunk($inserts, 200) as $chunk) DB::table('language_translations')->insert($chunk);
            }
        });
        $this->info('UI catalogues synchronized. Language settings and business records retained.');
        return self::SUCCESS;
    }
}
