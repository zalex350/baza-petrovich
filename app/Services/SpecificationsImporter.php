<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use Illuminate\Support\Facades\DB;

/**
 * Перенос характеристик из прежнего текстового поля products.specifications
 * в справочник и значения. Разбираются строки «Название: значение»
 * (также «—», «–», табуляция); остальной текст остаётся в specifications.
 * Названия нормализуются (ProductAttribute::key): «Цвет», «цвет », «ЦВЕТ:» — один ключ.
 */
class SpecificationsImporter
{
    private const LINE = '/^\s*([^:\t—–]{1,100}?)\s*(?::|\t|\s[—–-]\s)\s*(.+?)\s*$/u';

    /**
     * @return array{products: int, values: int, keys: array<string, array{name: string, count: int, variants: list<string>}>, skipped_duplicates: int}
     */
    public function run(bool $dryRun = false): array
    {
        $report = ['products' => 0, 'values' => 0, 'keys' => [], 'skipped_duplicates' => 0];

        $run = function () use (&$report, $dryRun) {
            Product::withTrashed()
                ->whereNotNull('specifications')
                ->where('specifications', '<>', '')
                ->orderBy('id')
                ->chunkById(500, function ($products) use (&$report, $dryRun) {
                    foreach ($products as $product) {
                        $this->importProduct($product, $report, $dryRun);
                    }
                });
        };

        $dryRun ? $run() : DB::transaction($run);

        ksort($report['keys']);

        return $report;
    }

    private function importProduct(Product $product, array &$report, bool $dryRun): void
    {
        $rest = [];
        $pairs = [];
        $seen = ProductAttributeValue::where('product_id', $product->id)
            ->join('attributes', 'attributes.id', '=', 'product_attribute_values.attribute_id')
            ->pluck('attributes.name_key')->flip()->all();

        foreach (preg_split('/\R/u', (string) $product->specifications) as $line) {
            if (! preg_match(self::LINE, $line, $m) || ProductAttribute::cleanName($m[1]) === '') {
                $rest[] = $line;

                continue;
            }

            $name = ProductAttribute::cleanName($m[1]);
            $key = ProductAttribute::key($name);

            // Один ключ на товар: повтор в тексте не переносится, но и не теряется
            if (isset($seen[$key])) {
                $report['skipped_duplicates']++;
                $rest[] = $line;

                continue;
            }

            $seen[$key] = true;
            $pairs[] = [$name, $key, $m[2]];

            $report['keys'][$key] ??= ['name' => $name, 'count' => 0, 'variants' => []];
            $report['keys'][$key]['count']++;

            if (! in_array($name, $report['keys'][$key]['variants'], true)) {
                $report['keys'][$key]['variants'][] = $name;
            }
        }

        if (! $pairs) {
            return;
        }

        $report['products']++;
        $report['values'] += count($pairs);

        if ($dryRun) {
            return;
        }

        $order = (int) ProductAttributeValue::where('product_id', $product->id)->max('sort_order') + 1;

        foreach ($pairs as [$name, $key, $value]) {
            $attribute = ProductAttribute::firstOrCreate(['name_key' => $key], ['name' => $report['keys'][$key]['name']]);

            ProductAttributeValue::create([
                'product_id'   => $product->id,
                'attribute_id' => $attribute->id,
                'value'        => mb_substr($value, 0, 255),
                'value_number' => ProductAttribute::parseNumber($value),
                'sort_order'   => $order++,
            ]);
        }

        $rest = trim(implode("\n", $rest));
        $product->forceFill(['specifications' => $rest === '' ? null : $rest])->saveQuietly();
    }
}
