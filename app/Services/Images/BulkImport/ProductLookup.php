<?php

namespace App\Services\Images\BulkImport;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Товар по имени файла фото (или по коду из CSV): артикул или SKU без учёта регистра — как match_key
 * (Product::normalizeKeyPart: регистр, пробелы, кириллические двойники латиницы, типографские дефисы).
 *
 * Порядок: SKU → артикул (у нескольких товаров, например разных брендов, — неоднозначно) →
 * «БРЕНД_АРТИКУЛ» (match_key). Суффикс номера фото «_1», «_2», «-2» отрезается, только если имя
 * целиком не нашлось: «L4755-2» — сперва артикул L4755-2, потом фото № 2 артикула L4755.
 * Удалённые товары не участвуют. Все товары — в памяти одним запросом.
 */
class ProductLookup
{
    /** @var array<string, list<int>> */
    private array $bySku = [];

    /** @var array<string, list<int>> */
    private array $byArticle = [];

    /** @var array<string, list<int>> */
    private array $byKey = [];

    /** @var array<int, array{id: int, brand: ?string, article: ?string, sku: ?string, name: ?string}> */
    private array $products = [];

    public function __construct()
    {
        DB::table('products')->whereNull('deleted_at')->orderBy('id')
            ->select(['id', 'sku', 'article', 'brand', 'match_key', 'name'])
            ->chunk(5000, function ($rows) {
                foreach ($rows as $row) {
                    $this->products[$row->id] = (array) $row;

                    if (($sku = Product::normalizeKeyPart($row->sku)) !== '') {
                        $this->bySku[$sku][] = $row->id;
                    }
                    if (($article = Product::normalizeKeyPart($row->article)) !== '') {
                        $this->byArticle[$article][] = $row->id;
                    }
                    if ($row->match_key !== null) {
                        $this->byKey[$row->match_key][] = $row->id;
                    }
                }
            });
    }

    /**
     * Имя файла без расширения → товар и номер фото.
     *
     * @return array{product_id: ?int, order: int, ambiguous: list<int>}
     */
    public function forFileName(string $stem): array
    {
        $found = $this->find($stem);

        if ($found['product_id'] === null && $found['ambiguous'] === [] && preg_match('/^(.+?)[_-](\d{1,3})$/u', $stem, $m)) {
            $base = $this->find($m[1]);

            if ($base['product_id'] !== null || $base['ambiguous'] !== []) {
                return $base + ['order' => (int) $m[2]];
            }
        }

        return $found + ['order' => 0];
    }

    /**
     * Код из CSV (SKU, артикул или БРЕНД_АРТИКУЛ) → товар; суффиксы не отрезаются.
     *
     * @return array{product_id: ?int, ambiguous: list<int>}
     */
    public function find(string $code): array
    {
        $code = trim($code);
        $norm = Product::normalizeKeyPart($code);

        if ($norm === '') {
            return ['product_id' => null, 'ambiguous' => []];
        }

        foreach ([$this->bySku[$norm] ?? [], $this->byArticle[$norm] ?? []] as $ids) {
            if ($ids !== []) {
                return $this->result($ids);
            }
        }

        if (str_contains($code, '_')) {
            [$brand, $article] = explode('_', $code, 2);
            $key = Product::matchKey($brand, $article);

            if ($key !== null && isset($this->byKey[$key])) {
                return $this->result($this->byKey[$key]);
            }
        }

        return ['product_id' => null, 'ambiguous' => []];
    }

    /** @return array{id: int, brand: ?string, article: ?string, sku: ?string, name: ?string}|null */
    public function product(int $id): ?array
    {
        return $this->products[$id] ?? null;
    }

    /** @param  list<int>  $ids */
    private function result(array $ids): array
    {
        $ids = array_values(array_unique($ids));

        return count($ids) === 1
            ? ['product_id' => $ids[0], 'ambiguous' => []]
            : ['product_id' => null, 'ambiguous' => $ids];
    }
}
