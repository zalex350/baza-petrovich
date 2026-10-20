<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\Catalog\CatalogFilters;
use App\Services\Catalog\CatalogListing;
use App\Services\CatalogMenu;

class CatalogController extends Controller
{
    /** Ключ сессии: адрес последнего списка каталога с фильтрами */
    public const BACK_URL = 'catalog.back_url';

    public function index()
    {
        $parents = Category::whereNull('parent_id')
            ->with(['children' => function ($q) {
                $q->withCount(['products' => fn($q2) => $q2->where('is_active', 1)])
                  ->orderBy('name');
            }])
            ->orderBy('name')
            ->get();

        return view('catalog_index', compact('parents'));
    }

    /**
     * JSON-дерево категорий для мобильного меню (из кэша CatalogMenu).
     */
    public function menu(CatalogMenu $menu)
    {
        return response()
            ->json($menu->tree(), 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ->header('Cache-Control', 'public, max-age=300');
    }

    /**
     * Товары категории вместе со всеми вложенными подкатегориями — «весь раздел» из мегаменю
     * и мобильного меню — с фильтрами и сортировкой (SRS §3.9, §3.10). Всё состояние — в URL
     * (BR-029, BR-030); адрес списка запоминается для «← Вернуться к списку» в карточке (BR-084).
     */
    public function show($slug, CatalogMenu $menu, CatalogFilters $filters)
    {
        $category = Category::where('slug', $slug)->firstOrFail();

        $listing = new CatalogListing(
            $category,
            $menu->descendantIds($category),
            $filters,
            request()->query(),
            auth()->check(),
        );

        $baseUrl = '/catalog/' . $category->slug;

        session([self::BACK_URL => request()->getRequestUri()]);

        return view('catalog', [
            'category' => $category,
            'listing'  => $listing,
            'products' => $listing->products(),
            'facets'   => $listing->facets(),
            'chips'    => $listing->chips($baseUrl),
            'baseUrl'  => $baseUrl,
        ]);
    }

    /**
     * Куда ведёт «← Вернуться к списку» из карточки: последний открытый список каталога,
     * иначе — категория товара.
     */
    public static function backUrl(?Category $category): string
    {
        $url = session(self::BACK_URL);

        if (is_string($url) && str_starts_with($url, '/catalog/')) {
            return $url;
        }

        return $category ? '/catalog/' . $category->slug : route('catalog.index');
    }
}
