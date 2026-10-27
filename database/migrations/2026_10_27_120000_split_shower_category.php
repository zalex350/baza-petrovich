<?php

use App\Services\CatalogMenu;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «Душевые системы и трапы / Для душевых» (225 товаров вперемешку) — по артикулам, как их
 * теперь раскладывает catalog:import-db (categories.php: split «для душевых»; решение по
 * товарам — docs/catalog/shower.csv). Заодно:
 * - «Душевая система (встраиваемая)» → «Встраиваемые душевые системы» (переименование, адрес тот же);
 * - «Душевая система» (LOFFREY-LF82427) вливается в «Душевые стойки»;
 * - опустевшие «Для душевых» и «Душевая система» удаляются.
 *
 * Идемпотентна: товары, уже стоящие в нужной категории, не трогает; нет товара или
 * категории-цели — пропускает. Отката нет (бэкап перед миграцией).
 */
return new class extends Migration
{
    private const ROOT = 'Душевые системы и трапы';

    private const BUILT_IN_OLD = 'Душевая система (встраиваемая)';

    private const BUILT_IN = 'Встраиваемые душевые системы';

    private const MOVES = [
        'Смесители/Для ванны и душа' => [
            'GERHANS-K12009', 'GERHANS-K12010', 'GERHANS-K12015', 'GERHANS-K12015W', 'GERHANS-K12022', 'GERHANS-K12022T',
            'GERHANS-K12023', 'GERHANS-K12023T', 'GERHANS-K12025', 'GERHANS-K19129', 'LEDEME-L2001', 'LEDEME-L2002B',
            'LEDEME-L2003', 'LEDEME-L2003W', 'LEDEME-L2004', 'LEDEME-L2008', 'LEDEME-L2010', 'LEDEME-L2014',
            'LEDEME-L2014-B', 'LEDEME-L2028', 'LEDEME-L2035', 'LEDEME-L2038-B', 'LEDEME-L2042', 'LEDEME-L2044',
            'LEDEME-L2048', 'LEDEME-L2048C', 'LEDEME-L2049', 'LEDEME-L2051', 'LEDEME-L2053', 'LEDEME-L2053W',
            'LEDEME-L2058', 'LEDEME-L2060', 'LEDEME-L2067', 'LEDEME-L2069', 'LEDEME-L2072', 'LEDEME-L2074',
            'LEDEME-L2074B', 'LEDEME-L2075', 'LEDEME-L2075B', 'LEDEME-L2076', 'LEDEME-L2077', 'LEDEME-L2077W',
            'LEDEME-L2089', 'LEDEME-L72003', 'LEDEME-L72033', 'LEDEME-LL21805-1', 'LOFFREY-LF82002', 'LOFFREY-LF82012',
            'WISENT-W2001', 'WISENT-W20111', 'WISENT-W20112', 'WISENT-W20212', 'WISENT-W2025', 'WISENT-W2049',
            'WISENT-W2065', 'WISENT-WG2003', 'WISENT-WG2069B', 'WISENT-WP20027', 'WISENT-WP20028', 'WISENT-WP20213',
            'WISENT-WP2030', 'WISENT-WP20444',
        ],
        'Душевые системы и трапы/Душевые стойки' => [
            'LOFFREY-LF82427', // из «Душевая система»
            'GERHANS-K19010', 'GERHANS-K19014', 'GERHANS-K19015', 'GERHANS-K19015W', 'GERHANS-K19018', 'GERHANS-K19022',
            'GERHANS-K19022T', 'GERHANS-K19023', 'GERHANS-K19023T', 'GERHANS-K19107D', 'GERHANS-K19114', 'GERHANS-K19114B',
            'GERHANS-K19114D', 'GERHANS-K19127', 'GERHANS-K19127B', 'GERHANS-K19127D', 'GERHANS-K19127W', 'GERHANS-K19127Y',
            'GERHANS-K19430Y', 'LEDEME-72406', 'LEDEME-HU01L72403', 'LEDEME-L2394', 'LEDEME-L2400', 'LEDEME-L2401',
            'LEDEME-L2402', 'LEDEME-L2403', 'LEDEME-L2403-1', 'LEDEME-L2404', 'LEDEME-L2407', 'LEDEME-L2408',
            'LEDEME-L2409', 'LEDEME-L2409-1', 'LEDEME-L2410', 'LEDEME-L2410-1', 'LEDEME-L2411', 'LEDEME-L2412',
            'LEDEME-L2413', 'LEDEME-L2414', 'LEDEME-L2414B', 'LEDEME-L2416', 'LEDEME-L2418', 'LEDEME-L2418D',
            'LEDEME-L2419', 'LEDEME-L2421', 'LEDEME-L2421W', 'LEDEME-L2422', 'LEDEME-L2422B', 'LEDEME-L2424',
            'LEDEME-L2424B', 'LEDEME-L2424D', 'LEDEME-L2424Y', 'LEDEME-L2425B', 'LEDEME-L2425B-1', 'LEDEME-L2426B',
            'LEDEME-L2427', 'LEDEME-L2427B', 'LEDEME-L2427D', 'LEDEME-L2427Y', 'LEDEME-L2433', 'LEDEME-L2433B',
            'LEDEME-L2443', 'LEDEME-L2452A', 'LEDEME-L2458', 'LEDEME-L2461', 'LEDEME-L2473', 'LEDEME-L2473B',
            'LEDEME-L2473G', 'LEDEME-L2473W', 'LEDEME-L2474', 'LEDEME-L2474B', 'LEDEME-L2475', 'LEDEME-L2475B',
            'LEDEME-L2476', 'LEDEME-L2478', 'LEDEME-L2478B', 'LEDEME-L2478D', 'LEDEME-L2478G', 'LEDEME-L2478U',
            'LEDEME-L2478Y', 'LEDEME-L2480', 'LEDEME-L2480B', 'LEDEME-L2480D', 'LEDEME-L2480Y', 'LEDEME-L2481',
            'LEDEME-L2481B', 'LEDEME-L2482', 'LEDEME-L2485', 'LEDEME-L2485SA', 'LEDEME-L2487', 'LEDEME-L2491',
            'LEDEME-L2492', 'LEDEME-L2492B', 'LEDEME-L2492D', 'LEDEME-L2492Y', 'LEDEME-L2494', 'LEDEME-L2494D',
            'LEDEME-L2494SR', 'LEDEME-L2494Y', 'LEDEME-L72400', 'LEDEME-L72400-1', 'LEDEME-L72401', 'LEDEME-L72402',
            'LEDEME-L72403-1', 'LEDEME-L72403-2', 'LEDEME-L72405', 'LEDEME-L72416', 'LEDEME-L72417', 'LEDEME-L72417-1',
            'LEDEME-L72417B-1', 'LEDEME-L72417D-1', 'LEDEME-L72418', 'LEDEME-L72418B', 'LEDEME-L72421', 'LEDEME-L72425',
            'LEDEME-L72425-1', 'LEDEME-L72425B-1', 'LEDEME-L72425D', 'LEDEME-L72425D-1', 'LEDEME-L72425Y', 'LEDEME-L72425Y-1',
            'LEDEME-L72433', 'LEDEME-L72433-1', 'LEDEME-L72433B', 'LEDEME-LL778', 'LEDEME-LL778W', 'LOFFREY-LF82402',
            'LOFFREY-LF82409', 'WISENT-W3501', 'WISENT-W3502', 'WISENT-W3506', 'WISENT-W3506A', 'WISENT-W3506B',
            'WISENT-W35105', 'WISENT-W35111', 'WISENT-W35112', 'WISENT-W3552', 'WISENT-W3552A', 'WISENT-W72416',
            'WISENT-W72417', 'WISENT-W72417B', 'WISENT-W72425', 'WISENT-WG2400B', 'WISENT-WG2433B', 'WISENT-WG2443',
            'WISENT-WG72401', 'WISENT-WP3500-1', 'WISENT-WP3500-2', 'WISENT-WP3500-3', 'WISENT-WP3500-3B', 'WISENT-WP3500BL',
            'WISENT-WP3500S', 'WISENT-WP35028', 'WISENT-WP3520', 'WISENT-WP35226', 'WISENT-WP735-001', 'WISENT-WP735-002',
            'WISENT-WP735-003-2',
        ],
        'Душевые системы и трапы/Встраиваемые душевые системы' => [
            'GERHANS-K19507', 'LEDEME-L2403-2', 'LEDEME-L2478-2', 'LEDEME-LL2478B-2', 'WISENT-WP8577',
        ],
        'Душевые системы и трапы/Лейки для душа' => [
            'LEDEME-L2417',
        ],
    ];

    private const DROP = [
        'Душевые системы и трапы/Для душевых',
        'Душевые системы и трапы/Душевая система',
    ];

    public function up(): void
    {
        $this->renameBuiltIn();

        foreach (self::MOVES as $path => $skus) {
            if ($id = $this->categoryId($path)) {
                // Удалённые товары тоже: иначе старая категория не опустеет
                DB::table('products')->whereIn('sku', $skus)->where(fn ($q) => $q->whereNull('category_id')->orWhere('category_id', '<>', $id))
                    ->update(['category_id' => $id]);
            }
        }

        foreach (self::DROP as $path) {
            $this->dropIfEmpty($this->categoryId($path));
        }

        CatalogMenu::flush();
    }

    public function down(): void
    {
        //
    }

    /**
     * Переименование сохраняет slug (адрес страницы). Если новая категория уже есть —
     * товары старой переходят в неё, старая удаляется.
     */
    private function renameBuiltIn(): void
    {
        $old = $this->categoryId(self::ROOT.'/'.self::BUILT_IN_OLD);

        if (! $old) {
            return;
        }

        $new = $this->categoryId(self::ROOT.'/'.self::BUILT_IN);

        if (! $new) {
            DB::table('categories')->where('id', $old)->update(['name' => self::BUILT_IN]);

            return;
        }

        DB::table('products')->where('category_id', $old)->update(['category_id' => $new]);
        $this->dropIfEmpty($old);
    }

    private function dropIfEmpty(?int $id): void
    {
        if ($id
            && ! DB::table('products')->where('category_id', $id)->exists()
            && ! DB::table('categories')->where('parent_id', $id)->exists()) {
            DB::table('categories')->where('id', $id)->delete();
        }
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
