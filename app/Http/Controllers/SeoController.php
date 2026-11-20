<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Product;
use App\Models\Review;
use App\Models\Work;
use App\Services\CatalogMenu;
use App\Support\Seo;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * /sitemap.xml и /robots.txt — собираются из каталога, услуг, страниц и работ.
 * Индексация выключена (config seo.index = false: стенд) — robots.txt запрещает всё, карта пуста.
 */
class SeoController extends Controller
{
    public const CACHE_KEY = 'seo.sitemap';

    public function robots(): Response
    {
        $lines = ['User-agent: *'];

        if (! config('seo.index')) {
            $lines[] = 'Disallow: /';
        } else {
            foreach (Seo::PRIVATE_PATHS as $path) {
                $lines[] = 'Disallow: /'.$path;
            }
            // Сортировка и вид списка — дубли без нового содержимого; фильтры закрыты meta robots noindex
            // (робот должен их открыть, чтобы увидеть noindex), ?page=N и метки рекламы — можно
            foreach (['sort', 'view'] as $param) {
                $lines[] = 'Disallow: /*?*'.$param.'=';
            }
            $lines[] = 'Allow: /storage/';
            $lines[] = 'Allow: /build/';
            $lines[] = '';
            $lines[] = 'Sitemap: '.Seo::absolute('/sitemap.xml');
        }

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(CatalogMenu $menu): Response
    {
        $xml = config('seo.index')
            ? Cache::remember(self::CACHE_KEY, config('seo.sitemap_ttl'), fn () => $this->build($menu))
            : $this->render([]);

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /**
     * Адреса карты: главная и разделы — чаще, товары — по дате изменения, с фото (image:image).
     *
     * @return list<array{loc: string, lastmod?: ?string, priority?: string, changefreq?: string, images?: list<string>}>
     */
    public function urls(CatalogMenu $menu): array
    {
        $urls = [
            ['loc' => '/', 'priority' => '1.0', 'changefreq' => 'daily'],
            ['loc' => '/catalog', 'priority' => '0.9', 'changefreq' => 'daily'],
        ];

        // Разделы — видимые с товарами (дерево меню)
        $walk = function (array $nodes, int $depth) use (&$walk, &$urls) {
            foreach ($nodes as $node) {
                $urls[] = ['loc' => $node['url'], 'priority' => $depth === 0 ? '0.9' : '0.8', 'changefreq' => 'daily'];
                $walk($node['children'] ?? [], $depth + 1);
            }
        };
        $walk($menu->tree(), 0);

        foreach (array_keys(CatalogController::COLLECTIONS) as $key) {
            if (Product::where('is_active', true)->where('is_published', true)->where(CatalogController::COLLECTIONS[$key][0], true)->exists()) {
                $urls[] = ['loc' => '/'.$key, 'priority' => '0.6', 'changefreq' => 'daily'];
            }
        }

        Product::query()
            ->where('is_active', true)
            ->where('is_published', true)
            ->whereHas('category', fn ($q) => $q->where('is_hidden', false))
            ->with(['images' => fn ($q) => $q->where('is_placeholder', false)->orderByDesc('is_main')->orderBy('sort_order')])
            ->orderBy('id')
            ->chunk(500, function ($products) use (&$urls) {
                foreach ($products as $product) {
                    $urls[] = [
                        'loc' => $product->url(false),
                        'lastmod' => $product->updated_at?->toAtomString(),
                        'priority' => '0.7',
                        'changefreq' => 'weekly',
                        'images' => $product->images->take(3)->map(fn ($image) => $image->url('large'))->all(),
                    ];
                }
            });

        if (Page::published()->services()->exists()) {
            $urls[] = ['loc' => '/uslugi', 'priority' => '0.7', 'changefreq' => 'weekly'];
        }

        if (Page::published()->articles()->exists()) {
            $urls[] = ['loc' => '/stati', 'priority' => '0.6', 'changefreq' => 'weekly'];
        }

        foreach (Page::published()->orderBy('sort_order')->get() as $page) {
            $urls[] = [
                'loc' => $page->url(),
                'lastmod' => $page->updated_at?->toAtomString(),
                'priority' => match ($page->type) {
                    Page::SERVICE => '0.7', Page::ARTICLE => '0.6', default => '0.5'
                },
                'changefreq' => 'monthly',
                'images' => $page->type === Page::ARTICLE && $page->imageUrl() ? [$page->imageUrl()] : [],
            ];
        }

        if (Work::published()->exists()) {
            $urls[] = ['loc' => '/nashi-raboty', 'priority' => '0.6', 'changefreq' => 'weekly'];

            foreach (Work::published()->ordered()->with('cover')->get() as $work) {
                $urls[] = ['loc' => $work->url(), 'lastmod' => $work->updated_at?->toAtomString(), 'priority' => '0.5', 'changefreq' => 'monthly',
                    'images' => $work->cover ? [$work->cover->url()] : []];
            }
        }

        if (Review::published()->exists()) {
            $urls[] = ['loc' => '/otzyvy', 'priority' => '0.5', 'changefreq' => 'weekly'];
        }

        return $urls;
    }

    private function build(CatalogMenu $menu): string
    {
        return $this->render($this->urls($menu));
    }

    private function render(array $urls): string
    {
        $e = fn (string $value) => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $out = ['<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'];

        $seen = [];

        foreach ($urls as $url) {
            $loc = Seo::absolute($url['loc']);

            if (isset($seen[$loc])) {
                continue;
            }
            $seen[$loc] = true;

            $out[] = '  <url>';
            $out[] = '    <loc>'.$e($loc).'</loc>';
            if (! empty($url['lastmod'])) {
                $out[] = '    <lastmod>'.$e($url['lastmod']).'</lastmod>';
            }
            if (! empty($url['changefreq'])) {
                $out[] = '    <changefreq>'.$url['changefreq'].'</changefreq>';
            }
            if (! empty($url['priority'])) {
                $out[] = '    <priority>'.$url['priority'].'</priority>';
            }
            foreach ($url['images'] ?? [] as $image) {
                $out[] = '    <image:image><image:loc>'.$e(Seo::absolute($image)).'</image:loc></image:image>';
            }
            $out[] = '  </url>';
        }

        $out[] = '</urlset>';

        return implode("\n", $out)."\n";
    }
}
