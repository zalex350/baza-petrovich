<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;

/**
 * products.article — «чистый» артикул без бренда из catalog.db.article (сопоставление по sku
 * без учёта регистра) и ключ сопоставления с 1С products.match_key (Product::matchKey).
 * Идемпотентна: меняет только расходящиеся строки, повторный прогон — «Изменится: 0».
 * Отчёт (docs/catalog/articles-report.md) пишется всегда: «нестандартные» строки каталога
 * (sku ≠ «БРЕНД-артикул»), товары сайта без пары в каталоге и без ключа, одинаковые ключи.
 * Группы с одинаковым ключом — ещё и docs/catalog/duplicate-keys.csv для заказчика
 * (группа; ключ; sku; название; бренд; категория; опубликован), UTF-8 с BOM, разделитель «;».
 * Группы: «бренд + артикул» — одинаковый match_key; «различие в пробелах» — sku товаров
 * отличаются только пробелами или регистром (цены 1С по SKU таким товарам не сопоставить).
 */
class CatalogFillArticles extends Command
{
    protected $signature = 'catalog:fill-articles
        {path : catalog.db (SQLite, открывается только на чтение)}
        {--dry-run : только отчёт, база не меняется}
        {--report-dir= : куда писать отчёт (по умолчанию docs/catalog)}';

    protected $description = 'Артикулы без бренда из catalog.db и ключ сопоставления с 1С (бренд + артикул)';

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("Файл не найден: {$path}");

            return self::FAILURE;
        }

        $catalog = $this->catalogRows($path);
        $ours = DB::table('products')->orderBy('id')->get(['id', 'sku', 'brand', 'article', 'match_key', 'deleted_at']);

        $updates = [];
        $matched = [];
        $withoutPair = [];

        foreach ($ours as $product) {
            $row = $catalog[self::skuKey($product->sku)] ?? null;
            $article = $row ? $row['article'] : $product->article;

            if ($row) {
                $matched[self::skuKey($product->sku)] = true;
            } elseif ($product->deleted_at === null) {
                $withoutPair[] = $product;
            }

            $key = Product::matchKey($product->brand, $article);

            if ($article !== $product->article || $key !== $product->match_key) {
                $updates[$product->id] = ['article' => $article, 'match_key' => $key];
            }

            $product->article = $article;
            $product->match_key = $key;
        }

        $live = $ours->whereNull('deleted_at');
        $nonStandard = array_filter($catalog, fn (array $row) => ! $row['standard']);
        $duplicates = $live->whereNotNull('match_key')->groupBy('match_key')->filter(fn ($group) => $group->count() > 1);
        $notOnSite = array_diff_key($catalog, $matched);

        $this->table(['', ''], [
            ['Строк в catalog.db', count($catalog)],
            ['Товаров сайта (без удалённых)', $live->count()],
            ['Сопоставлено по sku', count($matched)],
            ['Изменится (article / match_key)', count($updates)],
            ['Нестандартных строк каталога', count($nonStandard)],
            ['Товаров сайта без ключа сопоставления', $live->whereNull('match_key')->count()],
            ['Одинаковых ключей (групп)', $duplicates->count()],
        ]);

        $dir = $this->option('report-dir') ?: base_path('docs/catalog');
        File::ensureDirectoryExists($dir);
        File::put("{$dir}/articles-report.md", $this->report($path, $catalog, $live, $updates, $nonStandard, $withoutPair, $notOnSite, $duplicates));
        File::put("{$dir}/duplicate-keys.csv", $this->duplicatesCsv($duplicates, $this->spacingDuplicates($live)));

        if ($this->option('dry-run')) {
            $this->info("Dry-run: база не менялась. Отчёт — {$dir}/articles-report.md, одинаковые ключи — {$dir}/duplicate-keys.csv");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($updates) {
            foreach ($updates as $id => $values) {
                // Мимо модели: updated_at не трогаем — содержимое карточки не менялось
                DB::table('products')->where('id', $id)->update($values);
            }
        });

        $this->info('Готово: обновлено '.count($updates).". Отчёт — {$dir}/articles-report.md");

        return self::SUCCESS;
    }

    /**
     * @return array<string, array{sku: string, article: ?string, brand: ?string, standard: bool}>
     */
    private function catalogRows(string $path): array
    {
        $pdo = new PDO('sqlite:file:'.$path.'?mode=ro', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $rows = [];

        foreach ($pdo->query('SELECT sku, article, brand FROM products ORDER BY id') as $row) {
            $sku = trim((string) $row['sku']);
            $article = trim((string) $row['article']);
            $brand = trim((string) $row['brand']);

            // Первая строка с таким sku — как у catalog:import-db (дубли sku каталог не содержит)
            $rows[self::skuKey($sku)] ??= [
                'sku' => $sku,
                'article' => $article !== '' ? $article : null,
                'brand' => $brand !== '' ? $brand : null,
                // Регистр — в PHP: UPPER в SQLite не знает кириллицы
                'standard' => $brand !== '' && $article !== '' && mb_strtoupper($sku) === mb_strtoupper("{$brand}-{$article}"),
            ];
        }

        return $rows;
    }

    /**
     * Группы с одинаковым ключом: строка на товар, группы — по ключу, внутри — по sku.
     */
    private function duplicatesCsv($duplicates, $spacing): string
    {
        $groups = ['бренд + артикул' => $duplicates, 'различие в пробелах' => $spacing];
        $ids = collect($groups)->flatMap(fn ($group) => $group->flatten(1)->pluck('id'));
        $products = Product::with('category.parent')->whereIn('id', $ids)->get()->keyBy('id');

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\u{FEFF}");
        fputcsv($out, ['группа', 'ключ', 'sku', 'название', 'бренд', 'категория', 'опубликован'], ';');

        foreach ($groups as $label => $set) {
            foreach ($set->sortKeys() as $key => $group) {
                foreach ($group->sortBy('sku') as $row) {
                    $product = $products->get($row->id);
                    $category = $product?->category;

                    fputcsv($out, [
                        $label,
                        $key,
                        $row->sku,
                        $product?->title(),
                        $row->brand,
                        $category ? ($category->parent ? $category->parent->name.' / ' : '').$category->name : '',
                        $product?->is_published ? 'да' : 'нет',
                    ], ';');
                }
            }
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * Товары, чьи sku отличаются только пробелами или регистром: ключ — sku без пробелов
     * в верхнем регистре, группа — больше одного разного sku.
     */
    private function spacingDuplicates($live)
    {
        return $live->filter(fn ($p) => trim((string) $p->sku) !== '')
            ->groupBy(fn ($p) => mb_strtoupper((string) preg_replace('/[\s\p{Z}]+/u', '', (string) $p->sku)))
            ->filter(fn ($group) => $group->pluck('sku')->unique()->count() > 1);
    }

    private static function skuKey(?string $sku): string
    {
        return mb_strtoupper(trim((string) $sku));
    }

    private function report(string $path, array $catalog, $live, array $updates, array $nonStandard, array $withoutPair, array $notOnSite, $duplicates): string
    {
        $esc = fn (?string $v) => $v === null || $v === '' ? '—' : str_replace('|', '\\|', $v);
        $siteBySku = $live->keyBy(fn ($p) => self::skuKey($p->sku));

        $md = [
            '# Артикулы и ключ сопоставления с 1С',
            '',
            'Команда `catalog:fill-articles` ('.basename($path).', '.now()->format('d.m.Y H:i').'). '
                .'Ключ — `Product::matchKey`: бренд сайта + артикул без регистра, пробелов и дефисов '
                .'(docs/1C_EXCHANGE_SPEC.md, раздел 4).',
            '',
            '| | |',
            '| --- | --- |',
            '| Строк в catalog.db | '.count($catalog).' |',
            '| Товаров сайта (без удалённых) | '.$live->count().' |',
            '| Изменится / изменено (article, match_key) | '.count($updates).' |',
            '| Товаров сайта с ключом | '.$live->whereNotNull('match_key')->count().' |',
            '| Товаров сайта без ключа (нет бренда или артикула) | '.$live->whereNull('match_key')->count().' |',
            '| Нестандартных строк каталога (sku ≠ «БРЕНД-артикул») | '.count($nonStandard).' |',
            '| Товаров сайта без пары в catalog.db | '.count($withoutPair).' |',
            '| Строк catalog.db без товара на сайте | '.count($notOnSite).' |',
            '| Групп с одинаковым ключом | '.$duplicates->count().' |',
            '',
            '## Нестандартные строки каталога',
            '',
            'В `sku` нет префикса «БРЕНД-»: у строки нет бренда или артикула, либо sku собран иначе. '
                .'Без бренда на сайте ключа нет — такие товары 1С по артикулу не сопоставит, '
                .'нужен бренд (на сайте или в 1С) или ручное сопоставление.',
            '',
            '| sku | бренд catalog.db | артикул catalog.db | бренд сайта | ключ |',
            '| --- | --- | --- | --- | --- |',
        ];

        foreach ($nonStandard as $skuKey => $row) {
            $site = $siteBySku->get($skuKey);
            $md[] = '| '.$esc($row['sku']).' | '.$esc($row['brand']).' | '.$esc($row['article']).' | '
                .($site ? $esc($site->brand) : 'нет на сайте').' | '.($site ? $esc($site->match_key) : '—').' |';
        }

        $md[] = '';
        $md[] = '## Одинаковые ключи';
        $md[] = '';
        $md[] = 'Первичное сопоставление по такому ключу неоднозначно — 1С сопоставит его вручную или по GUID.';
        $md[] = '';
        $md[] = '| ключ | товары (sku) |';
        $md[] = '| --- | --- |';

        foreach ($duplicates as $key => $group) {
            $md[] = '| '.$esc($key).' | '.$esc($group->pluck('sku')->implode(', ')).' |';
        }

        $md[] = '';
        $md[] = '## Товары сайта без пары в catalog.db';
        $md[] = '';
        $md[] = '| id | sku | бренд | артикул |';
        $md[] = '| --- | --- | --- | --- |';

        foreach ($withoutPair as $p) {
            $md[] = "| {$p->id} | ".$esc($p->sku).' | '.$esc($p->brand).' | '.$esc($p->article).' |';
        }

        return implode("\n", $md)."\n";
    }
}
