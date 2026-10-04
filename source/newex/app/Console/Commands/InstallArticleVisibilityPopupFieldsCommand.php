<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Install the article audience and homepage-popup columns without touching
 * Laravel's migrations table. This is useful for deployments where the
 * migrations table sequence is out of sync with its existing rows.
 */
class InstallArticleVisibilityPopupFieldsCommand extends Command
{
    protected $signature = 'articles:install-visibility-popup-fields
                            {--check-only : Only report missing columns}';

    protected $description = 'Add missing article visibility and homepage popup fields';

    public function handle(): int
    {
        if (!Schema::hasTable('articles')) {
            $this->error('The articles table does not exist.');

            return self::FAILURE;
        }

        $columns = [
            'visibility_country',
            'visibility_referral_user_id',
            'homepage_popup_enabled',
            'homepage_popup_excluded_countries',
        ];

        $missing = array_values(array_filter(
            $columns,
            static fn (string $column): bool => !Schema::hasColumn('articles', $column)
        ));

        if ($missing === []) {
            $this->info('Article visibility and homepage popup fields are already installed.');

            return self::SUCCESS;
        }

        $this->line('Missing columns: ' . implode(', ', $missing));

        if ($this->option('check-only')) {
            return self::SUCCESS;
        }

        foreach ($missing as $column) {
            Schema::table('articles', function (Blueprint $table) use ($column): void {
                switch ($column) {
                    case 'visibility_country':
                        $table->string('visibility_country', 2)
                            ->nullable()
                            ->index();
                        break;

                    case 'visibility_referral_user_id':
                        $table->unsignedBigInteger('visibility_referral_user_id')
                            ->nullable()
                            ->index();
                        break;

                    case 'homepage_popup_enabled':
                        $table->boolean('homepage_popup_enabled')
                            ->default(false)
                            ->index();
                        break;

                    case 'homepage_popup_excluded_countries':
                        $table->text('homepage_popup_excluded_countries')->nullable();
                        break;
                }
            });

            $this->info('Added ' . $column . '.');
        }

        $this->info('Article visibility and homepage popup fields are ready.');

        return self::SUCCESS;
    }
}
