<?php

use App\Services\CatalogMenu;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Товары не в своей категории (docs/catalog/fix-client-test-1.md, п. 6) — по артикулам,
 * как их теперь раскладывает catalog:import-db (database/data/catalog/categories.php).
 * Опустевшие «Кольца», «Омыватель бокалов», «Шпильки» удаляются.
 *
 * Идемпотентна: товары, уже стоящие в нужной категории, не трогает; нет товара или
 * категории-цели — пропускает. Отката нет (бэкап перед миграцией).
 */
return new class extends Migration
{
    private const MOVES = [
        'Смесители/Сенсорные' => ['LEDEME-L1655-2', 'LEDEME-L1655A-2'],
        'Душевые системы и трапы/Душевые стойки' => [
            'L24101SR', 'L72406', 'L72420', 'LEDEME-L24201', 'LEDEME-L24301', 'LEDEME-L72425-I',
            'LEDEME-L72425B-I', 'LEDEME-L74225D', 'LEDEME-L74225D-1', 'LEDEME-L7425SY', 'LEDEME-L7425SY-I',
            // Были в «Для душевых»
            'FMARK-FS8645-1', 'SAVOL-S-SUS5003H', 'SAVOL-SK-H3145H', 'SAVOL-SK-H3245A', 'SAVOL-SK-H3345A', 'SAVOL-SK-H3445H',
        ],
        'Краны и вентили/Кран угловой' => ['L70507C', 'L70507H'],
        'Аксессуары для ванной/Держатели и стойки' => ['LEDEME-L926', 'L830.', 'L831.', 'L832.'],
        'Смесители/Для ванны и душа' => ['LOFFREY-LFB2270', 'LOFFREY-LFB82224'],
        'Душевые системы и трапы/Шланги для душа' => [
            'SAVOL-S-FXRG003A', 'SAVOL-S-FXRG003L', 'SAVOL-S-FXRG003Q', 'SAVOL-S-FXRG004A',
            'SAVOL-S-FXRG004C', 'SAVOL-S-FXRG004H', 'SAVOL-S-FXRG004L', 'SAVOL-S-FXRG004Q',
        ],
        'Аксессуары для ванной/Ёршики' => ['SAVOL-S-MTB33', 'SAVOL-S-TCMTB'],
        'Мойки и умывальники/Аксессуары для моек' => [
            'SAVOL-S-10-1', 'SAVOL-S-ZY001H', 'SAVOL-S-ZY001L', 'SAVOL-S-ZY002B', 'SAVOL-S-ZY002H', 'SAVOL-S-ZY002L',
            'SAVOL-S-SC-C333', 'SAVOL-S-SC-L333', 'SAVOL-S-SC-Q333', 'SAVOL-S-SC-W333',
        ],
        'Аксессуары для ванной/Шторы для ванной' => [
            'SAVOL-S-YLK002B', 'SAVOL-S-YLK002G', 'SAVOL-S-YLK002P', 'SAVOL-S-YLK002R', 'SAVOL-S-YLK003', 'SAVOL-S-YLK004',
        ],
        'Смесители/Запчасти' => ['SAVOL-S09'],
    ];

    private const DROP = [
        'Трубы и фитинги/Кольца',
        'Аксессуары для ванной/Омыватель бокалов',
        'Крепёж и инструмент/Шпильки',
    ];

    public function up(): void
    {
        foreach (self::MOVES as $path => $skus) {
            if ($id = $this->categoryId($path)) {
                // Удалённые товары тоже: иначе старая категория не опустеет
                DB::table('products')->whereIn('sku', $skus)->where(fn ($q) => $q->whereNull('category_id')->orWhere('category_id', '<>', $id))
                    ->update(['category_id' => $id]);
            }
        }

        foreach (self::DROP as $path) {
            $id = $this->categoryId($path);

            if ($id
                && ! DB::table('products')->where('category_id', $id)->exists()
                && ! DB::table('categories')->where('parent_id', $id)->exists()) {
                DB::table('categories')->where('id', $id)->delete();
            }
        }

        CatalogMenu::flush();
    }

    public function down(): void
    {
        //
    }

    private function categoryId(string $path): ?int
    {
        $id = null;

        foreach (explode('/', $path) as $name) {
            $id = DB::table('categories')->where('parent_id', $id)->where('name', $name)->value('id');

            if (! $id) {
                return null;
            }
        }

        return $id;
    }
};
