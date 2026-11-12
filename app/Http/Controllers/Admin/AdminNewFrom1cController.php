<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * «Товары → Новые из 1С»: черновики, созданные обменом номенклатуры (CatalogHandler: есть GUID,
 * не опубликован). Фильтр — без категории (по умолчанию; служебная скрытая категория — тоже
 * «без категории») / с категорией / все; поиск по SKU, артикулу, названию, бренду; группа 1С —
 * подсказка для выбора категории. Массово: назначить категорию, назначить и опубликовать,
 * опубликовать. Публикуются только товары с категорией сайта и без черновика текстов.
 */
class AdminNewFrom1cController extends Controller
{
    private const STATES = ['none', 'ready', 'all'];

    public function index()
    {
        $state = in_array(request('state'), self::STATES, true) ? request('state') : 'none';
        $search = mb_substr(trim((string) request('q')), 0, 100);
        $group = trim((string) request('group'));

        $products = $this->scope()
            ->when($state === 'none', fn ($q) => $this->withoutCategory($q))
            ->when($state === 'ready', fn ($q) => $this->withCategory($q))
            ->when($group !== '', fn ($q) => $q->where('group_1c', $group))
            ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search) {
                $like = '%'.$search.'%';
                $q->where('sku', 'like', $like)->orWhere('article', 'like', $like)->orWhere('name', 'like', $like)->orWhere('brand', 'like', $like);
            }))
            ->with('category:id,name')
            ->orderBy('group_1c')->orderBy('brand')->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.products_new_1c', [
            'products' => $products,
            'state' => $state,
            'search' => $search,
            'group' => $group,
            'counts' => [
                'none' => $this->withoutCategory($this->scope())->count(),
                'ready' => $this->withCategory($this->scope())->count(),
                'all' => $this->scope()->count(),
            ],
            'groups' => $this->scope()->whereNotNull('group_1c')->distinct()->orderBy('group_1c')->pluck('group_1c'),
            'categories' => Category::where('is_hidden', false)->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function update()
    {
        $data = request()->validate([
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['category', 'category_publish', 'publish'])],
            'category_id' => [
                Rule::requiredIf(fn () => request('action') !== 'publish'),
                'nullable',
                // where-замыкание: булево значение в строке правила превратилось бы в текст
                Rule::exists('categories', 'id')->where(fn ($q) => $q->where('is_hidden', false)),
            ],
        ], [
            'ids.required' => 'Отметьте товары',
            'category_id.required' => 'Выберите категорию',
            'category_id.exists' => 'Выберите категорию сайта',
        ]);

        // Только новые из 1С: чужие id из запроса не трогаются
        $products = $this->scope()->whereIn('id', $data['ids'])->with('draft')->get();
        $messages = [];

        if ($data['action'] !== 'publish') {
            foreach ($products as $product) {
                $product->update(['category_id' => (int) $data['category_id']]);
            }

            $messages[] = 'Категория назначена: '.$products->count();
        }

        if ($data['action'] !== 'category') {
            $hidden = Category::where('is_hidden', true)->pluck('id')->all();
            $published = 0;
            $noCategory = 0;
            $withDraft = 0;

            foreach ($products as $product) {
                if ($product->category_id === null || in_array($product->category_id, $hidden, true)) {
                    $noCategory++;
                } elseif ($product->draft) {
                    $withDraft++;
                } else {
                    $product->update(['is_published' => true]);
                    $published++;
                }
            }

            $messages[] = 'Опубликовано: '.$published;
            if ($noCategory) {
                $messages[] = "без категории не опубликовано: {$noCategory}";
            }
            if ($withDraft) {
                $messages[] = "с черновиком текстов — опубликуйте в карточке: {$withDraft}";
            }
        }

        return back()->with('success', implode('; ', $messages));
    }

    /** Новые из 1С: есть GUID, не опубликованы */
    private function scope(): Builder
    {
        return Product::query()->whereNotNull('guid')->where('is_published', false);
    }

    private function withoutCategory(Builder $query): Builder
    {
        $hidden = Category::where('is_hidden', true)->pluck('id');

        return $query->where(fn ($q) => $q->whereNull('category_id')->orWhereIn('category_id', $hidden));
    }

    private function withCategory(Builder $query): Builder
    {
        return $query->whereNotNull('category_id')->whereNotIn('category_id', Category::where('is_hidden', true)->pluck('id'));
    }
}
