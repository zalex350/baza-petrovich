<?php

namespace App\Console\Commands;

use App\Services\CatalogImport\CatalogImportApplier;
use App\Services\CatalogImport\CatalogImportPlanner;
use App\Services\CatalogImport\CatalogImportReport;
use App\Services\CatalogImport\CatalogMapping;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Импорт каталога из catalog.db (docs/CATALOG_ANALYSIS.md, «Решения»).
 * --dry-run: план без изменений — отчёт docs/catalog/import-report.md и docs/catalog/uncategorized.csv.
 * Боевой прогон — только с --backup=<дамп БД>, сделанным перед ним (последняя строка «-- Dump completed»).
 */
class CatalogImportDb extends Command
{
    protected $signature = 'catalog:import-db
        {path : catalog.db (SQLite, открывается только на чтение)}
        {--dry-run : только отчёт, база не меняется}
        {--backup= : дамп БД, сделанный перед боевым прогоном (проверяется)}
        {--data= : папка маппингов (по умолчанию database/data/catalog)}
        {--report-dir= : куда писать отчёт и CSV (по умолчанию docs/catalog)}';

    protected $description = 'Импорт каталога из catalog.db: бренды, категории, характеристики, фильтры, дубли (цены и остатки не трогает)';

    public function handle(CatalogImportApplier $applier, CatalogImportReport $report): int
    {
        $path = $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->backupOk()) {
            return self::FAILURE;
        }

        $mapping = CatalogMapping::load($this->option('data') ?: database_path('data/catalog'));

        $this->info('Строю план…');
        $plan = (new CatalogImportPlanner($mapping))->plan($path);

        $this->table(['', ''], [
            ['Строк в catalog.db', $plan->catalogRows],
            ['Товаров изменится', count($plan->products)],
            ['Новых (черновики)', count($plan->newProducts)],
            ['Категорий удалить', count($plan->deleteCategories)],
            ['Без категории в каталоге', count($plan->uncategorized)],
            ['Дубли / тестовые', count($plan->duplicates).' / '.count($plan->deletions)],
        ]);

        if ($dryRun) {
            $dir = $this->option('report-dir') ?: base_path('docs/catalog');
            File::ensureDirectoryExists($dir);
            File::put("{$dir}/import-report.md", $report->markdown($plan, $path));
            File::put("{$dir}/uncategorized.csv", $report->uncategorizedCsv($plan));

            $this->info("Dry-run: база не менялась. Отчёт — {$dir}/import-report.md, список — {$dir}/uncategorized.csv");

            return self::SUCCESS;
        }

        $applier->apply($plan, fn (string $step) => $this->line("— {$step}"));

        $this->info('Импорт выполнен. Повторный dry-run должен показать «Товаров изменится: 0».');

        return self::SUCCESS;
    }

    private function backupOk(): bool
    {
        $backup = $this->option('backup');

        if (! $backup || ! is_file($backup) || filesize($backup) === 0) {
            $this->error('Боевой прогон — только после бэкапа БД: укажите --backup=<файл дампа> (CLAUDE.md, правило бэкапа).');

            return false;
        }

        $tail = trim(substr(file_get_contents($backup, false, null, max(0, filesize($backup) - 200)), -200));

        if (! str_contains($tail, '-- Dump completed')) {
            $this->error("Дамп {$backup} неполный: нет строки «-- Dump completed» в конце.");

            return false;
        }

        return true;
    }
}
