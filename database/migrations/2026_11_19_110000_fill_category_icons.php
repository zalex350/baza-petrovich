<?php

use App\Services\CatalogMenu;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Стартовые иконки категорий 1-го и 2-го уровня — по названию из database/data/category_icons.php.
 * Один раз (миграция) и только категориям без иконки: выбранное в админке не перезаписывается.
 * Названия, которых нет в базе, пропускаются. Откат не нужен: колонки удалит предыдущая миграция.
 */
return new class extends Migration
{
    public function up(): void
    {
        $map = require database_path('data/category_icons.php');

        foreach ($map as $name => $icon) {
            DB::table('categories')->where('name', $name)->whereNull('icon')->update(['icon' => $icon]);
        }

        CatalogMenu::flush();
    }

    public function down(): void
    {
        // Иконки остаются: снимать их по списку значило бы стереть и выбранные позже в админке
    }
};
