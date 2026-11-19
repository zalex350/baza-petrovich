<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Str;

/**
 * SEO страницы витрины — один объект на запрос (app(Seo::class), в шаблонах — seo()).
 * Контроллер или шаблон задаёт заголовок, описание, картинку и разметку schema.org,
 * layouts/app выводит <title>, description, canonical, robots, Open Graph и JSON-LD (partials/seo).
 *
 * Канонический адрес по умолчанию — текущий путь без параметров; только ?page=N сохраняется.
 * Фильтры, сортировка, вид списка (любые другие параметры) — noindex, follow: дубли не попадают в индекс,
 * ссылки на товары остаются. Служебные разделы (корзина, заказ, кабинет, поиск) — noindex всегда.
 * Индексация выключена (config seo.index = false: стенд, локально) — noindex на всех страницах.
 */
class Seo
{
    public ?string $title = null;

    public ?string $description = null;

    public ?string $canonical = null;

    public ?string $image = null;

    public string $type = 'website';

    public bool $noindex = false;

    /** @var list<array<string, mixed>> */
    private array $schema = [];

    /** Пути, которые никогда не индексируются (кроме sitemap/robots они же — Disallow в robots.txt) */
    public const PRIVATE_PATHS = [
        'admin', 'order', 'orders', 'o', 'cart', 'checkout', 'account', 'profile', 'addresses',
        'login', 'register', 'forgot-password', 'reset-password', 'verify-email', 'confirm-password',
        'invite', 'search', 'request', 'price-request', 'quiz', 'dashboard', 'api',
    ];

    /** Параметры, которые не делают страницу дублем (метки рекламы) — canonical их отбрасывает, noindex не ставится */
    public const TRACKING_PARAMS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'gbraid', 'wbraid', 'yclid', 'fbclid', 'msclkid', '_openstat'];

    public function title(?string $title): static
    {
        $this->title = $title !== null ? trim($title) : null;

        return $this;
    }

    public function description(?string $description): static
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $description)));
        $this->description = $text !== '' ? Str::limit($text, 300, '…') : null;

        return $this;
    }

    public function image(?string $url): static
    {
        $this->image = $url ? self::absolute($url) : null;

        return $this;
    }

    public function canonical(?string $url): static
    {
        $this->canonical = $url ? self::absolute($url) : null;

        return $this;
    }

    public function type(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function noindex(bool $noindex = true): static
    {
        $this->noindex = $noindex;

        return $this;
    }

    /** Добавить объект schema.org (без @context — добавится при выводе) */
    public function schema(array $item): static
    {
        $this->schema[] = $item;

        return $this;
    }

    /**
     * Хлебные крошки (x-breadcrumbs: [['label', 'url'?], …]) → BreadcrumbList.
     * У последней крошки без ссылки — адрес текущей страницы.
     */
    public function breadcrumbs(array $items): static
    {
        $list = [];

        foreach (array_values($items) as $i => $item) {
            $url = $item['url'] ?? null;
            $list[] = array_filter([
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $item['label'],
                'item' => $url ? self::absolute(strtok($url, '?')) : self::absolute(request()->path()),
            ]);
        }

        return $list ? $this->schema(['@type' => 'BreadcrumbList', 'itemListElement' => $list]) : $this;
    }

    /** Вопросы и ответы → FAQPage: [['q' => …, 'a' => …], …] */
    public function faq(array $items): static
    {
        $questions = collect($items)
            ->filter(fn ($item) => filled($item['q'] ?? null) && filled($item['a'] ?? null))
            ->map(fn ($item) => [
                '@type' => 'Question',
                'name' => $item['q'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
            ])->values()->all();

        return $questions ? $this->schema(['@type' => 'FAQPage', 'mainEntity' => $questions]) : $this;
    }

    /** @return list<array<string, mixed>> */
    public function schemaItems(): array
    {
        return array_map(fn ($item) => ['@context' => 'https://schema.org'] + $item, $this->schema);
    }

    /**
     * <title>: заданный или $fallback (@section('title') страницы); название магазина — в конце, если его нет.
     */
    public function fullTitle(?string $fallback = null): string
    {
        $site = self::siteName();
        $title = trim((string) ($this->title ?: $fallback));

        if ($title === '' || $title === $site) {
            return $this->homeTitle();
        }

        return Str::contains($title, $site) ? $title : $title.' — '.$site;
    }

    public function homeTitle(): string
    {
        return Setting::value('seo_home_title') ?: self::siteName().' — '.(config('seo.tagline'));
    }

    public function metaDescription(): ?string
    {
        return $this->description ?: (request()->path() === '/' ? (Setting::value('seo_home_description') ?: config('seo.description')) : null);
    }

    /** Канонический адрес: заданный или текущий путь (+ ?page=N, если N > 1) */
    public function canonicalUrl(): string
    {
        if ($this->canonical) {
            return $this->canonical;
        }

        $url = self::absolute(request()->path());
        $page = (int) request()->query('page');

        return $page > 1 && ! $this->hasFilterParams() ? $url.'?page='.$page : $url;
    }

    public function robots(): string
    {
        return $this->isIndexable() ? 'index, follow' : 'noindex, follow';
    }

    public function isIndexable(): bool
    {
        return config('seo.index')
            && ! $this->noindex
            && ! self::isPrivatePath(request()->path())
            && ! $this->hasFilterParams()
            && request()->isMethod('GET');
    }

    public function ogImage(): string
    {
        return $this->image ?: self::absolute(Setting::value('seo_og_image') ?: config('seo.og_image'));
    }

    /** В адресе есть параметры кроме page и рекламных меток — фильтр, сортировка, вид */
    public function hasFilterParams(): bool
    {
        return collect(request()->query())->keys()
            ->reject(fn ($key) => $key === 'page' || in_array($key, self::TRACKING_PARAMS, true))
            ->isNotEmpty();
    }

    public static function isPrivatePath(string $path): bool
    {
        $first = explode('/', trim($path, '/'))[0];

        return in_array($first, self::PRIVATE_PATHS, true);
    }

    public static function siteName(): string
    {
        return (string) config('seo.site_name', config('shop.name'));
    }

    /** Абсолютный адрес на основном домене (config seo.url, иначе app.url) */
    public static function absolute(string $url): string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $base = rtrim((string) (config('seo.url') ?: config('app.url')), '/');
        $path = '/'.ltrim($url, '/');

        return $path === '/' ? $base.'/' : $base.$path;
    }
}
