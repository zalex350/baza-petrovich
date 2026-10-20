<?php

namespace App\Console\Commands;

use App\Services\SpecificationsImporter;
use Illuminate\Console\Command;

class ImportSpecifications extends Command
{
    protected $signature = 'attributes:from-specifications {--dry-run : Только отчёт, без записи}';

    protected $description = 'Перенести характеристики из текстового поля specifications в справочник характеристик';

    public function handle(SpecificationsImporter $importer): int
    {
        $report = $importer->run((bool) $this->option('dry-run'));

        $this->info(($this->option('dry-run') ? 'Проверка без записи. ' : '')
            . "Товаров с характеристиками: {$report['products']}, значений: {$report['values']}, ключей: " . count($report['keys']));

        if ($report['skipped_duplicates']) {
            $this->warn("Повторы ключа в тексте одного товара оставлены в тексте: {$report['skipped_duplicates']}");
        }

        if ($report['keys']) {
            $this->table(
                ['Характеристика', 'Товаров', 'Слились варианты'],
                collect($report['keys'])->map(fn ($k) => [
                    $k['name'],
                    $k['count'],
                    count($k['variants']) > 1 ? implode(' | ', $k['variants']) : '—',
                ])->values()->all()
            );
        }

        return self::SUCCESS;
    }
}
