<?php

namespace Database\Seeders\Language;

use App\Repositories\Language\LanguageRepository;
use Illuminate\Database\Seeder;

class LanguageSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        // Default English Language

        $language = new LanguageRepository();

        if(!$language->getBySlug('en')) {
            $language->store([
                'name' => 'English',
                'slug' => 'en',
                'status' => true,
                'is_default' => true
            ]);
        }
    }
}
