<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SearchSynonym;
use App\Services\Search\ProductSearch;
use App\Services\Search\SearchText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Validator;

/**
 * Синонимы поиска («Каталог → Синонимы поиска»): правила строкой — «смеситель, кран» (группа,
 * в обе стороны) или «мойка → раковина» (в одну сторону), добавление, правка в строке, удаление.
 * Кэш правил сбрасывает модель (SearchSynonym::booted) — изменения в поиске сразу, без деплоя.
 * «Проверить запрос» (?check=…): во что развернулся запрос и сколько товаров нашлось на витрине
 * (как у гостя: без кодов организации).
 */
class AdminSearchSynonymController extends Controller
{
    /** Товаров в результате проверки */
    public const CHECK_LIMIT = 10;

    public function index(ProductSearch $search)
    {
        $check = trim((string) request('check'));
        $result = null;

        if ($check !== '') {
            $started = microtime(true);
            $parsed = $search->parse($check);
            $products = $search->apply(Product::query()->where('is_active', 1)->where('is_published', true), $parsed);

            $result = [
                'slots' => $parsed->describe(),
                'total' => (clone $products)->reorder()->count(),
                'products' => $products->limit(self::CHECK_LIMIT)->get(['id', 'name', 'display_name', 'sku', 'article', 'brand']),
                'ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        }

        return view('admin.search_synonyms', [
            'synonyms' => SearchSynonym::query()->orderBy('terms')->get(),
            'check' => $check,
            'result' => $result,
        ]);
    }

    public function store(SearchText $text): RedirectResponse
    {
        $values = $this->validated('line', $text);
        $synonym = SearchSynonym::create($values);

        return redirect('/admin/search-synonyms')->with('success', "Синонимы добавлены: {$synonym->line()}");
    }

    public function update(int $id, SearchText $text): RedirectResponse
    {
        $synonym = SearchSynonym::findOrFail($id);
        $synonym->update($this->validated("lines.{$id}", $text));

        return redirect('/admin/search-synonyms')->with('success', "Синонимы сохранены: {$synonym->line()}");
    }

    public function destroy(int $id): RedirectResponse
    {
        $synonym = SearchSynonym::findOrFail($id);
        $synonym->delete();

        return redirect('/admin/search-synonyms')->with('success', "Синонимы удалены: {$synonym->line()}");
    }

    /**
     * Строка правила → terms / targets. Группа — от двух слов; замена — слово слева и справа;
     * каждое слово — не только из служебных («для», «с»…).
     *
     * @return array{terms: string, targets: ?string}
     */
    private function validated(string $field, SearchText $text): array
    {
        $line = (string) data_get(request()->all(), $field, '');
        $parsed = SearchSynonym::parseLine($line);

        $validator = Validator::make(request()->all(), [$field => ['required', 'string', 'max:500']], [], [$field => 'синонимы']);

        $validator->after(function ($validator) use ($field, $parsed, $text) {
            if ($validator->errors()->has($field)) {
                return;
            }

            $terms = SearchSynonym::split($parsed['terms']);
            $targets = SearchSynonym::split($parsed['targets']);

            $empty = array_filter([...$terms, ...$targets], fn ($w) => $text->words($w) === []);
            if ($empty !== []) {
                $validator->errors()->add($field, 'Не слово для поиска: «'.implode('», «', $empty).'».');
            } elseif ($parsed['targets'] === null && count($terms) < 2) {
                $validator->errors()->add($field, 'Укажите два слова или больше через запятую — или замену через «→».');
            } elseif ($terms === []) {
                $validator->errors()->add($field, 'Укажите слово слева от «→».');
            }
        });

        $validator->validate();

        return $parsed;
    }
}
