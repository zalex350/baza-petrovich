<?php

namespace App\Console\Commands;

use App\Support\CategoryName;
use Illuminate\Console\Command;

/**
 * Названия категорий — с заглавной первой буквы (CategoryName). Адреса не меняются,
 * повторный запуск ничего не меняет. Та же правка — миграцией capitalize_category_names.
 */
class CapitalizeCategories extends Command
{
    protected $signature = 'categories:capitalize {--dry-run : только показать, что изменится}';

    protected $description = 'Названия категорий — с заглавной первой буквы (адреса сохраняются)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $changes = CategoryName::capitalizeAll($dryRun);

        if (! $changes) {
            $this->info('Все названия категорий уже с заглавной буквы.');

            return self::SUCCESS;
        }

        $this->table(['id', 'было', 'стало'], array_map(fn ($c) => [$c['id'], $c['from'], $c['to']], $changes));
        $this->info(($dryRun ? 'Будет переименовано: ' : 'Переименовано: ').count($changes));

        return self::SUCCESS;
    }
}
