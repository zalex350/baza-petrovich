<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Пересчёт products.match_key у всех товаров (и удалённых) по текущему Product::matchKey —
 * после смены правил нормализации. Мимо модели (updated_at не трогается), пачками,
 * меняются только расходящиеся строки; повторный прогон — «изменится: 0».
 * Перед боевым запуском — бэкап БД (правило в CLAUDE.md).
 */
class CatalogMatchKeys extends Command
{
    protected $signature = 'catalog:match-keys {--dry-run : только посчитать, база не меняется}';

    protected $description = 'Пересчитать ключ сопоставления с 1С (бренд + артикул) у всех товаров';

    public function handle(): int
    {
        $total = 0;
        $changes = [];

        DB::table('products')->orderBy('id')->select(['id', 'brand', 'article', 'match_key'])
            ->chunk(1000, function ($rows) use (&$total, &$changes) {
                foreach ($rows as $row) {
                    $total++;
                    $key = Product::matchKey($row->brand, $row->article);

                    if ($key !== $row->match_key) {
                        $changes[$row->id] = [$row->match_key, $key];
                    }
                }
            });

        $this->info("Товаров: {$total}, изменится ключей: ".count($changes));

        foreach (array_slice($changes, 0, 10, true) as $id => [$old, $new]) {
            $this->line("  #{$id}: ".($old ?? '—').' → '.($new ?? '—'));
        }

        if ($this->option('dry-run') || ! $changes) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($changes) {
            foreach ($changes as $id => [, $key]) {
                DB::table('products')->where('id', $id)->update(['match_key' => $key]);
            }
        });

        $this->info('Готово: обновлено '.count($changes));

        return self::SUCCESS;
    }
}
