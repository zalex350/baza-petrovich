<?php

namespace App\Services\CatalogImport;

/**
 * Отчёт dry-run (docs/catalog/import-report.md) и список для проверки (docs/catalog/uncategorized.csv).
 */
class CatalogImportReport
{
    public function markdown(CatalogImportPlan $plan, string $source): string
    {
        $count = fn (string $field) => count(array_filter($plan->products, fn ($c) => isset($c[$field])));
        $catActions = collect($plan->categories)->countBy('action');
        $fmt = fn ($n) => number_format((int) $n, 0, ',', ' ');

        $md = [];
        $md[] = '# Импорт каталога — пробный прогон (dry-run)';
        $md[] = '';
        $md[] = '> Сформировано командой `php artisan catalog:import-db '.$source.' --dry-run` '.now()->format('Y-m-d H:i').'.';
        $md[] = '> База **не менялась**. Маппинги — `database/data/catalog/*.php`; решения — `docs/CATALOG_ANALYSIS.md`, «Решения».';
        $md[] = '';
        $md[] = '## Итого';
        $md[] = '';
        $md[] = '| | |';
        $md[] = '|---|---|';
        $md[] = '| Строк в catalog.db | '.$fmt($plan->catalogRows).' |';
        $md[] = '| Товаров изменится | '.$fmt(count($plan->products)).' |';
        $md[] = '| без изменений | '.$fmt($plan->unchanged).' |';
        $md[] = '| Новых товаров (черновики) | '.$fmt(count($plan->newProducts)).' |';
        $md[] = '| — бренд | '.$fmt($count('brand')).' |';
        $md[] = '| — категория | '.$fmt($count('category')).' |';
        $md[] = '| — «Новинка» (is_new) | '.$fmt($count('is_new')).' |';
        $md[] = '| — описание заполнено из каталога | '.$fmt($count('description')).' |';
        $md[] = '| — признак «описание сгенерировано» | '.$fmt($count('description_generated')).' (из них ставится: '.$fmt($plan->stats['description_flagged'] ?? 0).') |';
        $md[] = '| — характеристики | '.$fmt($plan->stats['attributes_products'] ?? 0).' товаров, '.$fmt($plan->stats['attributes_values'] ?? 0).' значений |';
        $md[] = '| Категорий: существующих / переименовать / создать / удалить | '
            .$fmt($catActions['existing'] ?? 0).' / '.$fmt($catActions['rename'] ?? 0).' / '.$fmt($catActions['create'] ?? 0).' / '.$fmt(count($plan->deleteCategories)).' |';
        $md[] = '| Характеристик в справочнике: создать / обновить / без изменений | '
            .collect($plan->attributes)->where('action', 'create')->count().' / '
            .collect($plan->attributes)->where('action', 'update')->count().' / '
            .collect($plan->attributes)->where('action', 'same')->count().' |';
        $md[] = '| Дубли: объединить | '.count($plan->duplicates).' |';
        $md[] = '| Тестовые товары: удалить | '.count($plan->deletions).' |';
        $md[] = '| Цена, НДС, остаток, «под заказ», кратность, артикул, название | **не меняются** |';
        $md[] = '';

        // Бренды
        $md[] = '## Бренды';
        $md[] = '';
        if (empty($plan->stats['brand'])) {
            $md[] = 'Изменений нет.';
        } else {
            $md[] = '| Было → станет | Товаров |';
            $md[] = '|---|---|';
            foreach ($plan->stats['brand'] as $label => $n) {
                $md[] = "| {$label} | {$n} |";
            }
        }
        $md[] = '';

        // Категории
        $md[] = '## Категории';
        $md[] = '';
        $renames = collect($plan->categories)->where('action', 'rename');
        $creates = collect($plan->categories)->where('action', 'create');
        if ($renames->isNotEmpty()) {
            $md[] = '**Переименовать** (адрес страницы сохраняется):';
            $md[] = '';
            $md[] = '| Было | Станет |';
            $md[] = '|---|---|';
            foreach ($renames as $key => $c) {
                $md[] = "| {$c['from']} | {$key} |";
            }
            $md[] = '';
        }
        if ($creates->isNotEmpty()) {
            $md[] = '**Создать:** '.$creates->keys()->map(fn ($k) => '`'.$k.'`'.($plan->categories[$k]['hidden'] ? ' (служебная, скрыта)' : ''))->implode(', ').'.';
            $md[] = '';
        }
        if ($plan->deleteCategories) {
            $md[] = '**Удалить опустевшие** ('.count($plan->deleteCategories).', старые адреса страниц перестанут открываться): '
                .collect($plan->deleteCategories)->map(fn ($l) => '`'.$l.'`')->implode(', ').'.';
            $md[] = '';
        }
        if (! empty($plan->stats['category_moves'])) {
            $md[] = '**Переезды товаров:**';
            $md[] = '';
            $md[] = '| Из → в | Товаров |';
            $md[] = '|---|---|';
            foreach ($plan->stats['category_moves'] as $label => $n) {
                $md[] = "| {$label} | {$n} |";
            }
            $md[] = '';
        }

        // Без категории
        $unc = collect($plan->uncategorized);
        $hidden = $unc->filter(fn ($r) => $r[3] === 'не распознано')->count();
        $md[] = '## Товары без категории в каталоге';
        $md[] = '';
        $md[] = 'Всего '.$unc->count().': распознано по названию / серии — '.($unc->count() - $hidden).', не распознано — '.$hidden
            .' (служебная категория, скрыта из меню и каталога). Список для проверки — `docs/catalog/uncategorized.csv`.';
        $md[] = '';

        // Характеристики
        $md[] = '## Справочник характеристик';
        $md[] = '';
        $md[] = '| Характеристика | Тип | Единица | Блок | Товаров | Действие |';
        $md[] = '|---|---|---|---|---|---|';
        foreach ($plan->attributes as $name => $a) {
            $block = $a['is_admin_only'] ? 'только админка' : ($a['is_logistics'] ? 'упаковка и логистика' : 'характеристики');
            $action = ['create' => 'создать', 'update' => 'обновить', 'same' => '—'][$a['action']];
            $md[] = "| {$name} | {$a['type']} | ".($a['unit'] ?? '').' | '.$block.' | '.$fmt($a['products']).' | '.$action.' |';
        }
        $md[] = '';
        $md[] = '**Не загружаются** (нет в `attributes.php`), самые частые из '.count($plan->droppedKeys).':';
        $md[] = '';
        $md[] = '| Ключ каталога | Товаров |';
        $md[] = '|---|---|';
        foreach (array_slice($plan->droppedKeys, 0, 40, true) as $key => $n) {
            $md[] = '| '.str_replace('|', '\\|', $key)." | {$n} |";
        }
        $md[] = '';

        // Фильтры
        $md[] = '## Фильтры категорий';
        $md[] = '';
        $md[] = '| Категория (и её подкатегории) | Фильтры |';
        $md[] = '|---|---|';
        foreach ($plan->filters as $path => $keys) {
            $md[] = "| {$path} | ".implode(', ', array_map(fn ($k) => ['brand' => 'производитель', 'price' => 'цена', 'stock' => 'наличие'][$k] ?? $k, $keys)).' |';
        }
        $md[] = '';

        // Дубли и тестовые
        $md[] = '## Дубли';
        $md[] = '';
        if (! $plan->duplicates) {
            $md[] = 'Нечего объединять.';
        } else {
            $md[] = '| Оставить | Удалить | Перенести поля | + характеристик | «Новинка» | Удаление |';
            $md[] = '|---|---|---|---|---|---|';
            foreach ($plan->duplicates as $d) {
                $md[] = "| {$d['keep_sku']} | {$d['remove_sku']} | ".(implode(', ', array_keys($d['fill'])) ?: '—').' | '.count($d['attributes'])
                    .' | '.($d['is_new'] ? 'да' : '—').' | '.($d['soft'] ? 'мягко (есть в заказах)' : 'совсем').' |';
            }
        }
        $md[] = '';
        $md[] = '## Тестовые товары';
        $md[] = '';
        if (! $plan->deletions) {
            $md[] = 'Нет.';
        } else {
            $md[] = '| sku | Название | Удаление |';
            $md[] = '|---|---|---|';
            foreach ($plan->deletions as $d) {
                $md[] = "| {$d['sku']} | {$d['name']} | ".['force' => 'совсем', 'soft' => 'мягко (есть в заказах)', 'already' => 'уже удалён мягко (есть в заказах)'][$d['action']].' |';
            }
        }
        $md[] = '';

        return implode("\n", $md);
    }

    /**
     * CSV для Excel: «;», UTF-8 с BOM.
     */
    public function uncategorizedCsv(CatalogImportPlan $plan): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\u{FEFF}");
        fputcsv($out, ['sku', 'название', 'предложенная категория', 'правило'], ';');

        foreach ($plan->uncategorized as $row) {
            fputcsv($out, $row, ';');
        }

        rewind($out);

        return stream_get_contents($out);
    }
}
