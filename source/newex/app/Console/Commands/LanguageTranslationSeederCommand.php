<?php

namespace App\Console\Commands;

use App\Models\Language\LanguageTranslation;
use Illuminate\Console\Command;

class LanguageTranslationSeederCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'language:translation-seeder {language}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $path = resource_path('lang/default.json');

        $translations = json_decode(file_get_contents($path), true);

        $batchInsert = [];

        foreach ($translations as $key=>$translation) {
            $batchInsert[] = [
                'language_id' => $this->argument('language'),
                'key' => $key,
                'content' => $translation
            ];
        }

        LanguageTranslation::insert($batchInsert);
    }
}
