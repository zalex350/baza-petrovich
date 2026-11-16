<?php

use App\Services\CatalogMenu;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «Термостаты комнатные»: стартовая иконка material:nest_thermostat → material:thermostat
 * (database/data/category_icons.php). Меняется только стартовая: иконку, выбранную в админке
 * вручную (любую другую), не трогает. На базе, где fill_category_icons ещё не запускалась,
 * она сразу ставит новую иконку — здесь ничего не меняется.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->swap('material:nest_thermostat', 'material:thermostat');
    }

    public function down(): void
    {
        // Не откатывается: material:thermostat мог быть выбран в админке вручную
    }

    private function swap(string $from, string $to): void
    {
        DB::table('categories')->where('name', 'Термостаты комнатные')->where('icon', $from)->update(['icon' => $to]);

        CatalogMenu::flush();
    }
};
