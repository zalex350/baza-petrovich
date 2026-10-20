<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CategoryFilter;
use App\Services\Catalog\CatalogFilters;

/**
 * Фильтры каталога по категориям (BR-016): какие фильтры и в каком порядке.
 * Подкатегория без своей настройки наследует фильтры родителя.
 */
class AdminCatalogFilterController extends Controller
{
    public function __construct(private readonly CatalogFilters $filters)
    {
    }

    public function index()
    {
        $categories = Category::orderBy('name')->get();
        $available = $this->filters->available();

        // Все настройки одним запросом; наследование — по дереву в памяти
        $own = CategoryFilter::orderBy('sort_order')->get()
            ->filter(fn ($f) => $available->has($f->key()))
            ->groupBy('category_id');
        $byId = $categories->keyBy('id');
        $default = collect(CatalogFilters::DEFAULT);

        $resolve = function (Category $category) use ($own, $byId, $default) {
            for ($c = $category, $guard = 0; $c && $guard < 20; $c = $byId[$c->parent_id] ?? null, $guard++) {
                if ($own->has($c->id)) {
                    return ['source' => $c, 'keys' => $own[$c->id]->map->key()];
                }
            }

            return ['source' => null, 'keys' => $default];
        };

        // Дерево: корни, затем дети — с отступом
        $byParent = $categories->groupBy(fn ($c) => $c->parent_id ?? 0);
        $rows = [];
        $walk = function ($parentId, $depth) use (&$walk, &$rows, $byParent, $resolve, $own) {
            foreach ($byParent[$parentId] ?? [] as $category) {
                $rows[] = ['category' => $category, 'depth' => $depth, 'own' => $own->has($category->id)] + $resolve($category);
                $walk($category->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return view('admin.catalog_filters', compact('rows', 'available'));
    }

    public function edit($id)
    {
        $category = Category::with('parent')->findOrFail($id);
        $resolved = $this->filters->forCategory($category);
        $own = $this->filters->own($category);
        $available = $this->filters->available();

        // Порядок в форме: включённые — в своём порядке, затем остальные
        $current = ($own->isNotEmpty() ? $own : $resolved['filters'])->map->key()->all();
        $ordered = collect($current)->filter(fn ($k) => $available->has($k))
            ->merge($available->keys()->diff($current))
            ->values();

        return view('admin.catalog_filter_form', [
            'category'  => $category,
            'available' => $available,
            'ordered'   => $ordered,
            'enabled'   => array_flip($current),
            'inherits'  => $own->isEmpty(),
            'source'    => $resolved['source'],
        ]);
    }

    public function update($id)
    {
        $category = Category::findOrFail($id);

        $data = request()->validate([
            'mode'      => ['required', 'in:inherit,own'],
            'filters'   => ['array'],
            'filters.*' => ['string'],
            'order'     => ['array'],
            'order.*'   => ['nullable', 'integer'],
        ]);

        if ($data['mode'] === 'inherit') {
            $this->filters->save($category, []);

            return redirect('/admin/catalog-filters')->with('success', "«{$category->name}»: фильтры наследуются от родительской категории");
        }

        $keys = $data['filters'] ?? [];

        if (! $keys) {
            return back()->withErrors(['filters' => 'Отметьте хотя бы один фильтр или выберите «Наследовать»']);
        }

        // Порядок — по полю «№», при равенстве — как в форме
        $order = $data['order'] ?? [];
        $keys = collect($keys)->values()
            ->sortBy(fn ($key, $i) => [($order[$key] ?? 999), $i])
            ->values()->all();

        $this->filters->save($category, $keys);

        return redirect('/admin/catalog-filters')->with('success', "«{$category->name}»: фильтры сохранены");
    }
}
