<?php

namespace App\Console\Commands;

use App\Services\Search\SearchIndex;
use Illuminate\Console\Command;

/**
 * Пересчёт поискового индекса (products.search_text) всех товаров, и удалённых, — после смены
 * правил нормализации (SearchText) или правок в базе мимо модели. Мимо модели (updated_at
 * не трогается), меняются только расходящиеся строки; повторный прогон — «изменено: 0».
 */
class SearchReindex extends Command
{
    protected $signature = 'search:reindex';

    protected $description = 'Пересчитать поисковый индекс товаров (основы слов названий, бренда, категории)';

    public function handle(SearchIndex $index): int
    {
        $started = microtime(true);
        $changed = $index->reindex();

        $this->info(sprintf('Поисковый индекс: изменено товаров — %d (%.1f с)', $changed, microtime(true) - $started));

        return self::SUCCESS;
    }
}
