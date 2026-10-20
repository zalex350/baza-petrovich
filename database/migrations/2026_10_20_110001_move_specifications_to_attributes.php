<?php

use App\Services\SpecificationsImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Перенос характеристик из products.specifications («Название: значение» по строкам)
 * в справочник. Отчёт — в лог; повторно и с отчётом в консоли:
 * php artisan attributes:from-specifications --dry-run
 */
return new class extends Migration
{
    public function up(): void
    {
        $report = app(SpecificationsImporter::class)->run();

        Log::info('Перенос характеристик из specifications', [
            'products' => $report['products'],
            'values'   => $report['values'],
            'keys'     => count($report['keys']),
            'merged'   => collect($report['keys'])->filter(fn ($k) => count($k['variants']) > 1)->map->variants->all(),
        ]);
    }

    public function down(): void
    {
        // Перенос не откатывается: значения остаются в product_attribute_values
    }
};
