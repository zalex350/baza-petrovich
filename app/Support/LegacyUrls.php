<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Models\Redirect;
use App\Models\Work;

/**
 * Куда вести адрес, которого нет на сайте (страница 404): адреса старого baza-petrovich.by
 * и сменённые адреса нового сайта. Вызывается из обработчика 404 (bootstrap/app.php), только для GET.
 *
 * Порядок: таблица редиректов (админка «Редиректы», смена slug) → правила старого сайта:
 * - catalog/раздел/…/товар — товар или раздел по ключу импорта (source_key); нет — ближайший
 *   родитель, который есть; иначе /catalog;
 * - nashi-raboty/…, uslugi/…, news/…, sovet/…, otzyvy/… — работа, услуга, статья по ключу; иначе список;
 * - служебные страницы старого сайта (STATIC).
 * null — такого адреса не было: настоящая 404.
 */
class LegacyUrls
{
    /** Страницы старого сайта без пары по ключу: путь → новый адрес */
    public const STATIC = [
        'company' => '/about',
        'nashi-klienty' => '/about',
        'blago' => '/about',
        'sotrudnichestvo' => '/partner',
        'rassrochka_i_krediti' => '/delivery',
        'information' => '/delivery',
        'rannee-bronirovanie' => '/promo',
        'sale' => '/promo',
        'price' => '/catalog',
        'calculator' => '/uslugi',
        'cutter' => '/uslugi/rezka-metalla',
        'news' => '/stati',
        'sovet' => '/stati',
        'slajder' => '/',
        'nizhnee-menyu' => '/',
        'poisk' => '/search',
        'index.php' => '/',
        'index.html' => '/',
    ];

    public static function resolve(string $path): ?string
    {
        $path = Redirect::normalize($path);

        if ($path === '') {
            return null;
        }

        if ($redirect = Redirect::where('from_path', $path)->first()) {
            $redirect->increment('hits', 1, ['last_hit_at' => now()]);

            return $redirect->to_url;
        }

        $parts = explode('/', $path);
        $first = $parts[0];

        return match ($first) {
            'catalog' => self::catalog($path),
            'product' => self::product($parts[1] ?? null),
            'nashi-raboty' => self::byKey(Work::class, $path, '/nashi-raboty'),
            'uslugi' => self::service($parts[1] ?? null),
            'news', 'sovet' => self::article($path),
            'otzyvy' => '/otzyvy',
            default => self::STATIC[$first] ?? null,
        };
    }

    /** Старый каталог: товар или раздел по ключу, иначе ближайший существующий родитель */
    private static function catalog(string $path): string
    {
        for ($try = $path; str_contains($try, '/'); $try = substr($try, 0, strrpos($try, '/'))) {
            $product = Product::where('source_key', $try)->where('is_active', true)->where('is_published', true)->first();

            if ($product) {
                return $product->url(false);
            }

            $category = Category::where('source_key', substr($try, strlen('catalog/')))->where('is_hidden', false)->first()
                ?? Category::where('slug', basename($try))->where('is_hidden', false)->first();

            if ($category) {
                return '/catalog/'.$category->slug;
            }
        }

        return '/catalog';
    }

    /** /product/{старый slug или id} — товар снят или адрес сменился без записи */
    private static function product(?string $key): ?string
    {
        if (! $key) {
            return '/catalog';
        }

        $product = Product::withTrashed()->where('slug', $key)->first();

        // Снятый с сайта товар — в его раздел
        if ($product?->category && ! $product->category->is_hidden) {
            return '/catalog/'.$product->category->slug;
        }

        return null;
    }

    private static function service(?string $slug): string
    {
        $page = $slug ? Page::services()->published()->where(fn ($q) => $q->where('slug', $slug)->orWhere('source_key', 'uslugi/'.$slug))->first() : null;

        return $page ? $page->url() : '/uslugi';
    }

    private static function article(string $path): string
    {
        $page = Page::articles()->published()->where('source_key', $path)->first();

        return $page ? $page->url() : '/stati';
    }

    /** @param  class-string<Work>  $model */
    private static function byKey(string $model, string $path, string $fallback): string
    {
        $item = $model::query()->where('source_key', $path)->orWhere('slug', basename($path))->first();

        return $item && $item->is_published ? $item->url() : $fallback;
    }
}
