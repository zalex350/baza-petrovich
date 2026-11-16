<?php

namespace App\Console\Commands;

use App\Models\ImageImport;
use App\Services\Images\BulkImport\BulkImages;
use Illuminate\Console\Command;

/**
 * Массовая загрузка фото с сервера (BulkImages): папка (со вложенными), ZIP или CSV «код;ссылка».
 * Без --apply — предпросмотр (база не меняется, запись журнала — «не применён»); с --apply — загрузка
 * сразу, в этом процессе, с прогрессом; запись — в журнале админки «Товары → Загрузить фото».
 */
class ImagesImport extends Command
{
    protected $signature = 'images:import
        {path : Папка с фото, ZIP или CSV «код;ссылка» на сервере}
        {--mode=append : append — добавить к существующим, replace — заменить фото товара}
        {--apply : Загрузить (без опции — только предпросмотр)}';

    protected $description = 'Массовая загрузка фото товаров: имя файла — артикул или SKU';

    private const LIST_LIMIT = 50;

    public function handle(BulkImages $bulk): int
    {
        $mode = (string) $this->option('mode');
        if (! isset(ImageImport::MODES[$mode])) {
            $this->error('--mode: append или replace');

            return self::INVALID;
        }

        $import = $bulk->draftFromPath((string) $this->argument('path'));

        if ($import->status === ImageImport::FAILED) {
            $this->error($import->message);

            return self::FAILURE;
        }

        $report = $import->report;
        $this->table(['', ''], [
            ['Товаров найдено', $import->products_total],
            ['Фото к загрузке', $import->files_total],
            ['Не найдено', count($report['not_found'])],
            ['Неоднозначно (нужен БРЕНД_АРТИКУЛ)', count($report['ambiguous'])],
            ['Пропущено (не картинки, строки без ссылки)', count($report['skipped'])],
        ]);
        $this->list('Не найдено', array_column($report['not_found'], 'name'));
        $this->list('Неоднозначно', array_column($report['ambiguous'], 'name'));

        if (! $this->option('apply')) {
            $bulk->discard($import);
            $this->line('Предпросмотр, база не менялась. Загрузить: --apply'.($mode === ImageImport::MODE_APPEND ? '' : " --mode={$mode}"));

            return self::SUCCESS;
        }

        $bulk->apply($import, $mode, queue: false);
        $bar = $this->output->createProgressBar($import->files_total);
        $bulk->runBatch($import, null, fn ($i) => $bar->setProgress($i->processed));
        $bar->finish();
        $this->newLine();

        $import->refresh();
        $this->info($import->message);
        foreach (array_slice($import->report['errors'] ?? [], 0, self::LIST_LIMIT) as $error) {
            $this->line("  {$error['name']}: {$error['message']}");
        }

        return $import->failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function list(string $title, array $names): void
    {
        if ($names === []) {
            return;
        }

        $this->line("{$title}:");
        foreach (array_slice($names, 0, self::LIST_LIMIT) as $name) {
            $this->line("  {$name}");
        }
        if (count($names) > self::LIST_LIMIT) {
            $this->line('  … ещё '.(count($names) - self::LIST_LIMIT));
        }
    }
}
