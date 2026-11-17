<?php

namespace App\Services\Pricing;

use App\Models\PriceType;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Справочник типов цен: создание (в том числе автоматически из файла цен 1С — скрытым), смена типа
 * по умолчанию и согласование products.price (цена типа по умолчанию) со строками product_prices.
 */
class PriceTypes
{
    public function __construct(private readonly PriceContext $context) {}

    /** Тип по коду; нет — создаётся скрытым (новая колонка price_<код> из 1С) */
    public function ensure(string $code): PriceType
    {
        $type = PriceType::query()->firstOrCreate(['code' => $code], [
            'name' => $code,
            'is_hidden' => true,
            'is_default' => false,
            'sort_order' => (int) PriceType::query()->max('sort_order') + 1,
        ]);
        $this->context->flush();

        return $type;
    }

    /**
     * Сделать тип ценой по умолчанию: products.price всех товаров — цены этого типа
     * (нет цены — null, «Цена уточняется» у клиентов без своего типа). Скрытый — нельзя.
     */
    public function makeDefault(PriceType $type): void
    {
        if ($type->is_hidden) {
            throw new RuntimeException('Скрытый тип не может быть ценой по умолчанию — сначала включите его');
        }

        DB::transaction(function () use ($type) {
            PriceType::query()->whereKeyNot($type->id)->where('is_default', true)->update(['is_default' => false]);
            $type->forceFill(['is_default' => true])->save();

            // Мимо модели: products.price меняется массово, строки product_prices уже верные
            DB::table('products')->update([
                'price' => DB::table('product_prices')->select('price')
                    ->whereColumn('product_prices.product_id', 'products.id')
                    ->where('product_prices.price_type_id', $type->id)
                    ->limit(1),
            ]);
        });

        $this->context->flush();
    }

    /** Строка product_prices типа по умолчанию — по products.price (сохранение товара моделью) */
    public function syncDefaultRow(int $productId, mixed $price): void
    {
        $typeId = $this->context->defaultId();

        if ($typeId === null) {
            return;
        }

        if ($price === null) {
            DB::table('product_prices')->where('product_id', $productId)->where('price_type_id', $typeId)->delete();

            return;
        }

        DB::table('product_prices')->upsert(
            [['product_id' => $productId, 'price_type_id' => $typeId, 'price' => $price, 'created_at' => now(), 'updated_at' => now()]],
            ['product_id', 'price_type_id'],
            ['price', 'updated_at'],
        );
    }
}
