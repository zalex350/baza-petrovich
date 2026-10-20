<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Характеристики товара (SRS §4.9): разбор строк формы, запись в товар и в черновик,
 * объединение дублей справочника.
 *
 * «Строки» — список [name, value] в порядке показа; «набор» — список
 * [attribute_id, value] (так хранится в черновике).
 */
class ProductAttributes
{
    /**
     * Строки из формы → набор. Пустые строки пропускаются, новые названия добавляются
     * в справочник. Ошибки — ValidationException по полю attributes.
     *
     * @param  array<int, array{name?: ?string, value?: ?string}>  $rows
     * @return list<array{attribute_id: int, value: string}>
     */
    public function fromRows(array $rows): array
    {
        $errors = [];
        $clean = [];
        $seen = [];

        foreach (array_values($rows) as $i => $row) {
            $name = ProductAttribute::cleanName($row['name'] ?? '');
            $value = trim((string) ($row['value'] ?? ''));

            if ($name === '' && $value === '') {
                continue;
            }

            $n = $i + 1;

            if ($name === '') {
                $errors[] = "Строка {$n}: укажите характеристику для значения «{$value}»";

                continue;
            }

            if ($value === '') {
                $errors[] = "Строка {$n}: укажите значение характеристики «{$name}»";

                continue;
            }

            if (mb_strlen($name) > 255 || mb_strlen($value) > 255) {
                $errors[] = "Строка {$n}: название и значение — не длиннее 255 символов";

                continue;
            }

            $key = ProductAttribute::key($name);

            // BR-059: один ключ на товар — «Цвет» и «цвет» считаются одной характеристикой
            if (isset($seen[$key])) {
                $errors[] = "Характеристика «{$name}» указана дважды";

                continue;
            }

            $seen[$key] = true;
            $clean[] = compact('name', 'key', 'value');
        }

        $existing = ProductAttribute::whereIn('name_key', array_column($clean, 'key'))->get()->keyBy('name_key');

        foreach ($clean as $row) {
            $attribute = $existing[$row['key']] ?? null;

            if ($attribute?->isNumber() && ProductAttribute::parseNumber($row['value']) === null) {
                $errors[] = "«{$attribute->name}» — числовая характеристика, значение «{$row['value']}» не число";
            }
        }

        if ($errors) {
            throw ValidationException::withMessages(['attributes' => $errors]);
        }

        return array_map(fn ($row) => [
            'attribute_id' => ($existing[$row['key']] ?? ProductAttribute::findOrCreateByName($row['name']))->id,
            'value'        => $row['value'],
        ], $clean);
    }

    /**
     * Текущий набор товара — для сравнения с черновиком.
     */
    public function current(Product $product): array
    {
        return $product->attributeValues()->get()
            ->map(fn ($v) => ['attribute_id' => (int) $v->attribute_id, 'value' => (string) $v->value])
            ->all();
    }

    /**
     * Записать набор в товар: лишние удаляются, порядок — как в наборе.
     */
    public function sync(Product $product, array $set): void
    {
        $attributes = ProductAttribute::whereIn('id', array_column($set, 'attribute_id'))->get()->keyBy('id');

        // Характеристику могли удалить или объединить, пока набор лежал в черновике
        $set = array_values(array_filter($set, fn ($item) => isset($attributes[$item['attribute_id']])));

        DB::transaction(function () use ($product, $set, $attributes) {
            ProductAttributeValue::where('product_id', $product->id)
                ->whereNotIn('attribute_id', array_column($set, 'attribute_id') ?: [0])
                ->delete();

            foreach ($set as $order => $item) {
                ProductAttributeValue::updateOrCreate(
                    ['product_id' => $product->id, 'attribute_id' => $item['attribute_id']],
                    [
                        'value'        => $item['value'],
                        'value_number' => ProductAttribute::parseNumber($item['value']),
                        'sort_order'   => $order,
                    ]
                );
            }
        });

        $product->unsetRelation('attributeValues');
    }

    /**
     * Набор → несохранённые значения для показа (предпросмотр черновика, форма).
     *
     * @return Collection<int, ProductAttributeValue>
     */
    public function toModels(array $set): Collection
    {
        $attributes = ProductAttribute::whereIn('id', array_column($set, 'attribute_id'))->get()->keyBy('id');

        return collect($set)
            ->filter(fn ($item) => isset($attributes[$item['attribute_id']]))
            ->values()
            ->map(function ($item, $order) use ($attributes) {
                $value = new ProductAttributeValue([
                    'attribute_id' => $item['attribute_id'],
                    'value'        => $item['value'],
                    'sort_order'   => $order,
                ]);

                return $value->setRelation('attribute', $attributes[$item['attribute_id']]);
            });
    }

    /**
     * Объединить дубли: значения $sources переходят к $target, источники удаляются.
     * У товара, где уже есть значение $target, значение источника отбрасывается (BR-059).
     *
     * @return array{moved: int, dropped: int}
     */
    public function merge(ProductAttribute $target, Collection $sources): array
    {
        $sources = $sources->reject(fn ($s) => $s->id === $target->id);
        $sourceIds = $sources->pluck('id')->all();

        if (! $sourceIds) {
            return ['moved' => 0, 'dropped' => 0];
        }

        return DB::transaction(function () use ($target, $sources, $sourceIds) {
            $withTarget = ProductAttributeValue::where('attribute_id', $target->id)->pluck('product_id')->flip();
            $moved = 0;
            $dropped = 0;

            // Порядок источников — как передан: первое значение для товара выигрывает
            foreach ($sources as $source) {
                foreach (ProductAttributeValue::where('attribute_id', $source->id)->get() as $value) {
                    if (isset($withTarget[$value->product_id])) {
                        $value->delete();
                        $dropped++;

                        continue;
                    }

                    $value->update([
                        'attribute_id' => $target->id,
                        'value_number' => ProductAttribute::parseNumber($value->value),
                    ]);
                    $withTarget[$value->product_id] = true;
                    $moved++;
                }
            }

            $this->remapDrafts($sourceIds, $target->id);

            ProductAttribute::whereIn('id', $sourceIds)->delete();

            return compact('moved', 'dropped');
        });
    }

    /**
     * Черновики хранят attribute_id — после объединения переписываются на новый id.
     */
    private function remapDrafts(array $sourceIds, int $targetId): void
    {
        foreach (\App\Models\ProductDraft::all() as $draft) {
            $data = $draft->data;

            if (empty($data['attributes'])) {
                continue;
            }

            $seen = [];
            $set = [];

            foreach ($data['attributes'] as $item) {
                $id = in_array($item['attribute_id'], $sourceIds, true) ? $targetId : $item['attribute_id'];

                if (! isset($seen[$id])) {
                    $seen[$id] = true;
                    $set[] = ['attribute_id' => $id, 'value' => $item['value']];
                }
            }

            if ($set !== $data['attributes']) {
                $data['attributes'] = $set;
                $draft->update(['data' => $data]);
            }
        }
    }
}
