<?php

namespace Database\Seeders\Settings;

use Illuminate\Database\Seeder;
use Setting;

class DefaultSettingSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        // Activate default settings

        // Activate Dark Theme Switcher
        Setting::set('general.dark_mode_status', true);
        Setting::set('general.default_dark_mode_status', true);

        Setting::save();

        // Activate Language Switcher
        //Setting::set('language_status', true);
    }
}
