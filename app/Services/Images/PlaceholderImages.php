<?php

namespace App\Services\Images;

use App\Models\ProductImage;
use Illuminate\Support\Facades\Storage;

/**
 * Фото-заглушки товаров (product_images.is_placeholder): «нет фото» поставщика, плейсхолдеры.
 * Заглушка не показывается нигде (Product::mainImage, gallery) — товар считается без фото.
 *
 * Признаки:
 *  - name      — имя файла или путь похожи на заглушку (no-photo, noimage, placeholder, заглушка…);
 *  - empty     — пустой путь;
 *  - missing   — файла нет на диске (вместо него показалась бы общая заглушка сайта);
 *  - duplicate — одинаковое содержимое (content_hash) у фото многих разных товаров.
 * Загрузка в админке (ProductImageService) помечает по имени и по содержимому уже помеченной заглушки;
 * все признаки сразу — команда images:placeholders.
 */
class PlaceholderImages
{
    public const EMPTY = 'empty';

    public const NAME = 'name';

    public const MISSING = 'missing';

    public const DUPLICATE = 'duplicate';

    public const REASONS = [
        self::NAME => 'имя файла похоже на заглушку',
        self::EMPTY => 'пустой путь',
        self::MISSING => 'файла нет на диске',
        self::DUPLICATE => 'одинаковый файл у многих товаров',
    ];

    /** Одинаковый файл у стольких разных товаров и больше — заглушка (команда, --min-products) */
    public const MIN_PRODUCTS = 20;

    private const NAME_PATTERN = '/(?:^|[^\p{L}])(?:no|net|not|without)[-_ .]?(?:photo|foto|image|img|picture|pic|pict)'
        .'|nophoto|noimage|noimg|placeholder|dummy|zaglushk|stub[-_ .]?(?:photo|image)'
        .'|заглушк|нет[-_ .]?(?:фото|изображ|картинк)|без[-_ .]?(?:фото|изображ|картинк)'
        .'|default[-_ .]?(?:photo|foto|image|img|picture)/iu';

    public static function nameLooksLike(?string ...$names): bool
    {
        foreach ($names as $name) {
            if ($name !== null && $name !== '' && preg_match(self::NAME_PATTERN, $name)) {
                return true;
            }
        }

        return false;
    }

    /** sha1 содержимого файла фото (большая версия или перенесённый файл); нет файла — null */
    public static function hashOf(ProductImage $image): ?string
    {
        if (trim((string) $image->path) === '') {
            return null;
        }

        $disk = Storage::disk(ProductImage::disk());
        $path = $image->pathFor('large');

        return $disk->exists($path) ? sha1($disk->get($path)) : null;
    }

    /**
     * Новое фото из админки — заглушка? По имени файла или если такой же файл уже помечен заглушкой.
     */
    public static function isKnown(?string $originalName, ?string $hash): bool
    {
        return self::nameLooksLike($originalName)
            || ($hash !== null && ProductImage::query()->where('content_hash', $hash)->where('is_placeholder', true)->exists());
    }

    /**
     * Признак заглушки без учёта одинаковых файлов (их считает команда по всей таблице).
     *
     * @param  bool  $fileExists  есть ли файл на диске
     */
    public static function reason(ProductImage $image, bool $fileExists): ?string
    {
        return match (true) {
            trim((string) $image->path) === '' => self::EMPTY,
            // Путь загруженного через галерею — случайное имя, смотрим только исходное имя файла
            self::nameLooksLike($image->hasVariants() ? null : $image->path, $image->original_name) => self::NAME,
            ! $fileExists => self::MISSING,
            default => null,
        };
    }
}
