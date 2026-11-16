<?php

namespace App\Console\Commands;

use App\Services\CatalogMenu;
use Illuminate\Console\Command;

/**
 * Пересчёт дерева меню каталога: счётчики опубликованных товаров и порядок категорий (CategoryOrder) —
 * кэш сбрасывается и собирается заново. Ночью по расписанию (routes/console.php): изменения товаров
 * мимо моделей (массовые обновления) за день попадают в порядок категорий.
 */
class CatalogMenuRefresh extends Command
{
    protected $signature = 'catalog:menu-refresh';

    protected $description = 'Пересчитать меню каталога: количество товаров и порядок категорий';

    public function handle(CatalogMenu $menu): int
    {
        CatalogMenu::flush();
        $roots = count($menu->tree());

        $this->info("Меню каталога пересчитано: разделов верхнего уровня — {$roots}");

        return self::SUCCESS;
    }
}
