<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductDraft;

/**
 * Черновик текстов товара (product_drafts) нужен, только если сайтовые поля отличаются
 * от опубликованных. Сравнение — по смыслу, а не посимвольно: переносы строк из браузера
 * (\r\n) и пробелы по краям не считаются изменением — иначе любое «Сохранить» без правок
 * создавало черновик (товар с описанием в несколько строк).
 */
class ProductDrafts
{
    /** Тексты сайта, которые идут через черновик (AdminProductController::DRAFT_FIELDS) */
    public const FIELDS = ['display_name', 'description', 'specifications', 'package_contents'];

    public function __construct(private readonly ProductAttributes $attributes) {}

    /**
     * Текст в одном виде: переносы — \n, без пробелов по краям; null — пустая строка.
     */
    public static function text($value): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", (string) $value));
    }

    /**
     * Отличаются ли значения черновика от опубликованного товара. Поля, которых нет
     * в $values, не сравниваются (не пришли из формы — значит, не менялись).
     */
    public function differs(Product $product, array $values): bool
    {
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $values) && self::text($values[$field]) !== self::text($product->{$field})) {
                return true;
            }
        }

        return isset($values['attributes'])
            && $this->attributeSet($values['attributes']) !== $this->attributeSet($this->attributes->current($product));
    }

    /**
     * Удалить черновики, совпадающие с опубликованным товаром. Повторный запуск ничего
     * не меняет. Возвращает число удалённых.
     */
    public function pruneUnchanged(): int
    {
        $deleted = 0;

        ProductDraft::query()->with('product')->orderBy('id')->chunkById(200, function ($drafts) use (&$deleted) {
            foreach ($drafts as $draft) {
                // Товар удалён (soft delete) — связь пуста; черновик оставить: товар могут восстановить
                if ($draft->product && ! $this->differs($draft->product, (array) $draft->data)) {
                    $draft->delete();
                    $deleted++;
                }
            }
        });

        return $deleted;
    }

    /**
     * Набор характеристик для сравнения: [id, значение] в порядке строк.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function attributeSet(array $set): array
    {
        return array_map(fn ($item) => [(int) $item['attribute_id'], self::text($item['value'])], array_values($set));
    }
}
