<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Фото товара (SRS §4.7, BR-051…BR-054). Одно основное на товар — следит ProductImageService.
 *
 * path — большая версия: products/{product_id}/{случайное имя}-large.webp; остальные
 * размеры лежат рядом с суффиксами -thumb / -medium. Фото, перенесённое из прежнего
 * products.image, хранится как есть (без суффикса) и отдаётся для всех размеров.
 * width / height — размеры большой версии.
 * is_placeholder — заглушка («нет фото» поставщика): на сайте не показывается (App\Services\Images\PlaceholderImages),
 * content_hash — sha1 файла.
 */
class ProductImage extends Model
{
    public const SIZES = ['thumb', 'medium', 'large'];

    protected $fillable = [
        'product_id',
        'path',
        'original_name',
        'is_main',
        'is_placeholder',
        'sort_order',
        'width',
        'height',
        'content_hash',
    ];

    protected $casts = [
        'is_main'    => 'boolean',
        'is_placeholder' => 'boolean',
        'sort_order' => 'integer',
        'width'      => 'integer',
        'height'     => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public static function disk(): string
    {
        return config('shop.images.disk', 'public');
    }

    public static function placeholderUrl(): string
    {
        return config('shop.images.placeholder');
    }

    /**
     * Путь файла нужного размера на диске.
     */
    public function pathFor(string $size): string
    {
        if (! $this->hasVariants()) {
            return $this->path;
        }

        return substr($this->path, 0, -strlen('-large.webp')) . "-{$size}.webp";
    }

    /**
     * Все файлы фото на диске (для удаления).
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return array_values(array_unique(array_map(fn ($size) => $this->pathFor($size), self::SIZES)));
    }

    public function url(string $size = 'thumb'): string
    {
        return Storage::disk(self::disk())->url($this->pathFor($size));
    }

    /**
     * Ширина и высота версии: вписана в квадрат размера, без увеличения.
     * Без известных размеров (перенесённое фото) — квадрат.
     *
     * @return array{0: int, 1: int}
     */
    public function dimensions(string $size): array
    {
        $box = (int) config("shop.images.sizes.{$size}");

        if (! $this->width || ! $this->height) {
            return [$box, $box];
        }

        $scale = min(1, $box / max($this->width, $this->height));

        return [max(1, (int) round($this->width * $scale)), max(1, (int) round($this->height * $scale))];
    }

    /**
     * URL и размеры для галереи карточки (product-gallery.js).
     *
     * @return array<string, string|int>
     */
    public function toGalleryItem(): array
    {
        [$mw, $mh] = $this->dimensions('medium');
        [$lw, $lh] = $this->dimensions('large');

        return [
            'thumb'  => $this->url('thumb'),
            'medium' => $this->url('medium'),
            'large'  => $this->url('large'),
            'mw' => $mw, 'mh' => $mh, 'lw' => $lw, 'lh' => $lh,
        ];
    }

    /**
     * Загружено через галерею — есть webp-версии всех размеров.
     */
    public function hasVariants(): bool
    {
        return str_ends_with($this->path, '-large.webp');
    }
}
