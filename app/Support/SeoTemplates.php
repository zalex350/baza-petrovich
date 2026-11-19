<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;

/**
 * Заголовки и описания разделов и товаров по шаблонам (админка «Настройки» → SEO).
 * Своё seo_title / seo_description у раздела или товара важнее шаблона.
 *
 * Подстановки: {name}, {price}, {category}, {city}, {site}, {count}.
 * Часть в квадратных скобках выводится, только если все подстановки в ней заполнены:
 * «{name}[, цена {price}]» без цены — просто «{name}».
 */
class SeoTemplates
{
    public const DEFAULTS = [
        'seo_category_title' => '{name} — купить в {city}[, цены {price}]',
        'seo_category_description' => '{name} в {city}[: {count}][, цены {price}]. Доставка по Беларуси в день заказа, самовывоз в Минске и Бресте. Металлобаза «{site}».',
        'seo_product_title' => '{name}[ — цена {price}], купить в {city}',
        'seo_product_description' => '{name}[ за {price}]. [{category} — ]в наличии на металлобазе «{site}»: доставка по Беларуси, самовывоз в Минске и Бресте. Заказ на сайте или по телефону.',
    ];

    public static function categoryTitle(Category $category, ?float $minPrice = null, ?int $count = null): string
    {
        return $category->seo_title ?: self::render('seo_category_title', self::categoryVars($category, $minPrice, $count));
    }

    public static function categoryDescription(Category $category, ?float $minPrice = null, ?int $count = null): string
    {
        return $category->seo_description ?: self::render('seo_category_description', self::categoryVars($category, $minPrice, $count));
    }

    public static function productTitle(Product $product): string
    {
        return $product->seo_title ?: self::render('seo_product_title', self::productVars($product));
    }

    public static function productDescription(Product $product): string
    {
        return $product->seo_description ?: self::render('seo_product_description', self::productVars($product));
    }

    /**
     * @param  array<string, ?string>  $vars
     */
    public static function render(string $key, array $vars): string
    {
        $template = Setting::value($key) ?: self::DEFAULTS[$key];
        $vars += ['city' => config('seo.city'), 'site' => Seo::siteName()];

        // Необязательные части: [ … ] — только если все подстановки внутри заполнены
        $text = preg_replace_callback('/\[([^\[\]]*)\]/u', function ($m) use ($vars) {
            preg_match_all('/\{(\w+)\}/', $m[1], $names);

            foreach ($names[1] as $name) {
                if (blank($vars[$name] ?? null)) {
                    return '';
                }
            }

            return $m[1];
        }, $template);

        $text = preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) ($vars[$m[1]] ?? ''), $text);

        // Пробелы и знаки по краям — регуляркой (trim() режет многобайтовое «—» побайтно)
        return preg_replace(['/\s+/u', '/\s+([,.:])/u', '/([,:])\1+/u', '/^[\s,:—-]+|[\s,:—-]+$/u'], [' ', '$1', '$1', ''], $text);
    }

    private static function categoryVars(Category $category, ?float $minPrice, ?int $count): array
    {
        return [
            'name' => $category->name,
            'price' => $minPrice ? 'от '.money($minPrice) : null,
            'count' => $count ? Plural::ru($count, ['товар', 'товара', 'товаров']) : null,
            'category' => $category->name,
        ];
    }

    private static function productVars(Product $product): array
    {
        $price = prices_visible() && $product->price !== null
            ? money($product->price).($product->unit && $product->unit !== 'шт' ? ' за '.unit_label($product->unit) : '')
            : null;

        return [
            'name' => $product->title(),
            'price' => $price,
            'category' => $product->category && ! $product->category->is_hidden ? $product->category->name : null,
            'count' => null,
        ];
    }
}
