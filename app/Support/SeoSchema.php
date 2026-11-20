<?php

namespace App\Support;

use App\Models\Product;
use App\Models\Setting;

/**
 * Разметка schema.org (JSON-LD) для seo()->schema(): организация с филиалами, сайт с поиском, товар.
 * Цена товара — та, что видит покупатель (Product::price, в рознице — с НДС), валюта BYN.
 */
class SeoSchema
{
    /** Организация и магазины-филиалы (config seo.organization, контакты — «Настройки») */
    public static function organization(): array
    {
        $config = config('seo.organization');
        $phones = array_column(Setting::phones(), 'number');
        $email = Setting::value('contact_email');
        $url = Seo::absolute('/');

        $hours = array_map(fn ($row) => [
            '@type' => 'OpeningHoursSpecification',
            'dayOfWeek' => $row['days'],
            'opens' => $row['opens'],
            'closes' => $row['closes'],
        ], $config['hours'] ?? []);

        $branches = array_map(fn ($branch) => array_filter([
            '@type' => $config['type'] ?? 'Store',
            'name' => $branch['name'],
            'url' => $url,
            'image' => Seo::absolute(config('seo.og_image')),
            'telephone' => $phones[0] ?? null,
            'priceRange' => $config['price_range'] ?? null,
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => $branch['street'],
                'addressLocality' => $branch['locality'],
                'addressRegion' => $branch['region'],
                'postalCode' => $branch['postal'],
                'addressCountry' => 'BY',
            ],
            'geo' => $branch['geo'] ? ['@type' => 'GeoCoordinates', 'latitude' => $branch['geo'][0], 'longitude' => $branch['geo'][1]] : null,
            'openingHoursSpecification' => $hours ?: null,
        ]), $config['branches'] ?? []);

        return array_filter([
            '@type' => 'Organization',
            '@id' => $url.'#organization',
            'name' => Seo::siteName(),
            'legalName' => Setting::value('seller_name') ?: ($config['legal_name'] ?? null),
            'url' => $url,
            'logo' => Seo::absolute(config('seo.og_image')),
            'email' => $email,
            'telephone' => $phones[0] ?? null,
            'contactPoint' => array_map(fn ($phone) => [
                '@type' => 'ContactPoint',
                'telephone' => $phone['number'],
                'contactType' => 'sales',
                'name' => $phone['label'] ?: null,
                'areaServed' => 'BY',
                'availableLanguage' => ['ru', 'be'],
            ], Setting::phones()) ?: null,
            'sameAs' => array_values(array_filter([config('shop.reviews_url')])) ?: null,
            'subOrganization' => $branches ?: null,
        ]);
    }

    /** Сайт с поиском — строка поиска Google по сайту */
    public static function website(): array
    {
        return [
            '@type' => 'WebSite',
            'name' => Seo::siteName(),
            'url' => Seo::absolute('/'),
            'inLanguage' => 'ru-BY',
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => ['@type' => 'EntryPoint', 'urlTemplate' => Seo::absolute('/search').'?q={search_term_string}'],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    /**
     * Товар: название, фото, артикул, бренд, раздел, предложение (цена, наличие, продавец).
     * Без цены (по запросу) — без offers: Google не показывает цену, ошибки разметки нет.
     */
    public static function product(Product $product, string $description, array $images): array
    {
        $price = prices_visible() ? $product->price : null;

        $availability = match (true) {
            (int) $product->stock > 0 => 'https://schema.org/InStock',
            (bool) $product->on_order => 'https://schema.org/BackOrder',
            default => 'https://schema.org/OutOfStock',
        };

        return array_filter([
            '@type' => 'Product',
            'name' => $product->title(),
            'description' => $description,
            'image' => $images ?: null,
            'sku' => $product->article ?: $product->sku ?: (string) $product->id,
            'brand' => $product->brand ? ['@type' => 'Brand', 'name' => $product->brand] : null,
            'category' => $product->category?->name,
            'url' => Seo::absolute($product->url(false)),
            'offers' => $price !== null ? array_filter([
                '@type' => 'Offer',
                'url' => Seo::absolute($product->url(false)),
                'priceCurrency' => 'BYN',
                'price' => number_format((float) $price, 2, '.', ''),
                'availability' => $availability,
                'itemCondition' => 'https://schema.org/NewCondition',
                'seller' => ['@type' => 'Organization', 'name' => Seo::siteName()],
                'unitText' => $product->unit ? unit_label($product->unit) : null,
            ]) : null,
        ]);
    }
}
