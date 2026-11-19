<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Page;
use App\Models\Product;

/**
 * Где сейчас посетитель — набор «мест» для квизов и кодов сторонних сервисов:
 *   all, home, catalog, products, category:{id} (раздел, его подразделы и товары), services, service:{slug},
 *   works (наши работы и отзывы), cart, page:{slug}.
 * Квиз / код показывается, если его targets пересекаются с текущими (matches). Определяется по маршруту —
 * контроллеры ничего не передают. Один раз за запрос (scoped).
 */
class PageTargets
{
    /** @var list<string>|null */
    private ?array $current = null;

    /** Подписи общих мест для админки */
    public const GENERAL = [
        'all' => 'Все страницы',
        'home' => 'Главная',
        'catalog' => 'Весь каталог (разделы и товары)',
        'products' => 'Все карточки товаров',
        'services' => 'Услуги',
        'works' => 'Наши работы и отзывы',
        'cart' => 'Корзина',
    ];

    /** @return list<string> */
    public function current(): array
    {
        return $this->current ??= $this->resolve();
    }

    /** @param list<string>|null $targets */
    public function matches(?array $targets): bool
    {
        return (bool) array_intersect($targets ?? [], $this->current());
    }

    /** Сбросить (тесты, несколько запросов в одном процессе) */
    public function flush(): void
    {
        $this->current = null;
    }

    /** @return list<string> */
    private function resolve(): array
    {
        $request = request();
        $route = $request->route();
        $name = $route?->getName();
        $targets = ['all'];

        if ($request->is('admin', 'admin/*', 'api/*')) {
            return [];
        }

        if ($request->path() === '/') {
            $targets[] = 'home';
        }

        if ($request->is('order')) {
            $targets[] = 'cart';
        }

        if ($name === 'catalog.index' || str_starts_with((string) $name, 'collection.')) {
            $targets[] = 'catalog';
        }

        if ($name === 'catalog.show') {
            $targets[] = 'catalog';
            $category = Category::where('slug', $route->parameter('slug'))->first();
            $targets = [...$targets, ...$this->categoryTargets($category?->id)];
        }

        if ($name === 'product.show') {
            $targets[] = 'catalog';
            $targets[] = 'products';
            $key = (string) $route->parameter('key');
            $product = Product::where('slug', $key)->first() ?? (ctype_digit($key) ? Product::find((int) $key) : null);
            $targets = [...$targets, ...$this->categoryTargets($product?->category_id)];
        }

        if (in_array($name, ['services.index', 'services.show'], true)) {
            $targets[] = 'services';
            if ($name === 'services.show') {
                $targets[] = 'service:'.$route->parameter('slug');
            }
        }

        if (in_array($name, ['works.index', 'works.show', 'reviews.index'], true)) {
            $targets[] = 'works';
        }

        $slug = $route?->parameter('slug') ?? $route?->defaults['slug'] ?? null;
        if ($slug && $route?->getActionMethod() === 'show' && str_contains((string) $route->getActionName(), 'PageController')) {
            $targets[] = 'page:'.$slug;
        }

        return array_values(array_unique($targets));
    }

    /** @return list<string> раздел и все его родители */
    private function categoryTargets(?int $categoryId): array
    {
        if (! $categoryId) {
            return [];
        }

        $parents = Category::query()->pluck('parent_id', 'id');
        $out = [];

        for ($id = $categoryId, $guard = 0; $id && $guard < 20; $id = $parents[$id] ?? null, $guard++) {
            $out[] = 'category:'.$id;
        }

        return $out;
    }

    /**
     * Варианты мест для админки: общие, разделы (с отступом по глубине), услуги, страницы.
     *
     * @return array<string, array<string, string>> группа => [target => подпись]
     */
    public static function options(): array
    {
        $categories = Category::query()->where('is_hidden', false)->get(['id', 'name', 'parent_id']);
        $children = $categories->groupBy('parent_id');
        $rows = [];
        $walk = function ($parentId, int $depth) use (&$walk, &$rows, $children) {
            foreach (($children[$parentId] ?? collect())->sortBy('name') as $c) {
                if ($depth < 2) {
                    $rows['category:'.$c->id] = str_repeat('— ', $depth).$c->name;
                    $walk($c->id, $depth + 1);
                }
            }
        };
        $walk(null, 0);

        return [
            'Общие' => self::GENERAL,
            'Разделы каталога (с подразделами и товарами)' => $rows,
            'Услуги' => Page::services()->pluck('title', 'slug')->mapWithKeys(fn ($t, $s) => ['service:'.$s => $t])->all(),
            'Страницы' => Page::query()->where('type', 'page')->pluck('title', 'slug')->mapWithKeys(fn ($t, $s) => ['page:'.$s => $t])->all(),
        ];
    }
}
