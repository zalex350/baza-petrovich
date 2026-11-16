<?php

use App\Services\CatalogMenu;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Иконки категорий — набор сантехники plumb (database/data/category_icons.php) вместо Material Symbols
 * и прежних контурных. Заменяются только стартовые значения: иконка категории совпадает с прежним
 * стартовым списком (category_icons_v1.php; у «Термостаты комнатные» — ещё и самая первая
 * material:nest_thermostat). Выбранные в админке вручную не трогаются; категории без иконки
 * получают новую (как fill_category_icons). Отката нет: прежние иконки удалены из проекта.
 */
return new class extends Migration
{
    public function up(): void
    {
        $previous = require database_path('data/category_icons_v1.php');
        $current = require database_path('data/category_icons.php');

        foreach ($current as $name => $icon) {
            $start = array_filter([$previous[$name] ?? null, $name === 'Термостаты комнатные' ? 'material:nest_thermostat' : null]);

            DB::table('categories')
                ->where('name', $name)
                ->where(fn ($q) => $q->whereNull('icon')->orWhereIn('icon', $start))
                ->update(['icon' => $icon, 'icon_svg' => null]);
        }

        CatalogMenu::flush();
    }

    public function down(): void
    {
        // Прежних иконок в проекте нет — откатывать не на что
    }
};
