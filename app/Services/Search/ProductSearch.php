<?php

namespace App\Services\Search;

use App\Models\Category;
use App\Models\ProductClientCode;
use App\Services\ClientCodes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Поиск товаров по словам (шапка, подсказки, страница поиска) с учётом форм слов и синонимов.
 *
 * Запрос делится на слова; каждое слово должно найтись (порядок не важен) одним из способов:
 * - в products.search_text — по основе или началу слова (SearchText, SearchToken::sql):
 *   названия сайта и 1С, бренд, категория с родителями;
 * - через синоним (SearchSynonyms): «кран» → ещё и «смеситель»;
 * - как раньше подстрокой — в артикуле, sku, коде товара своей организации (ClientCodes),
 *   на странице результатов — ещё и в описании.
 *
 * Порядок: точный код организации → точный артикул / sku → все слова в названии как есть →
 * все слова по основам → остальные (синонимы, артикулы, описание); внутри — по названию.
 */
class ProductSearch
{
    public function __construct(
        private readonly SearchText $text,
        private readonly SearchSynonyms $synonyms,
        private readonly ClientCodes $codes,
    ) {}

    public function parse(string $query): SearchQuery
    {
        $query = trim($query);
        $items = [];

        foreach (preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $tokens = $this->text->tokens($word);

            // Только служебные слова или знаки («для», «-») — не условие
            if ($tokens !== []) {
                $items[] = ['word' => $word, 'tokens' => $tokens];
            }
        }

        $slots = [];

        for ($i = 0, $count = count($items); $i < $count; $i += $take) {
            // Синонимы — по подряд идущим простым словам (артикул «F-SL-C1166» из трёх частей — нет)
            $stems = [];
            for ($j = $i; $j < $count && count($items[$j]['tokens']) === 1; $j++) {
                $stems[] = $items[$j]['tokens'][0]->stem;
            }

            [$length, $synonyms] = $stems !== [] ? $this->synonyms->match($stems) : [0, []];
            $take = max(1, $length);
            $chunk = array_slice($items, $i, $take);

            $slots[] = new SearchSlot(
                array_column($chunk, 'word'),
                array_merge(...array_column($chunk, 'tokens')),
                $synonyms,
            );
        }

        return new SearchQuery($query, $slots);
    }

    /**
     * Условия и порядок поиска на выборке товаров.
     */
    public function apply(Builder $products, SearchQuery $query, ?int $organizationId = null, bool $withDescription = false): Builder
    {
        if ($query->isEmpty()) {
            return $products->whereRaw('1 = 0');
        }

        foreach ($query->slots as $slot) {
            $products->where(function (Builder $q) use ($slot, $organizationId, $withDescription) {
                foreach ($slot->variants() as $variant) {
                    $q->orWhereRaw(...$this->allTokens($variant));
                }

                // Подстрокой — артикул, sku, код организации (и описание): каждое слово части
                $q->orWhere(function (Builder $words) use ($slot, $organizationId, $withDescription) {
                    foreach ($slot->words as $word) {
                        $words->where(fn (Builder $one) => $this->whereWordContains($one, $word, $organizationId, $withDescription));
                    }
                });
            });
        }

        $this->order($products, $query, $organizationId);

        return $products;
    }

    /**
     * Категории, подходящие под запрос: по основам и синонимам названия категории вместе
     * с родителями («смеситель кухня» → «Смесители / Для кухни»). Путь — в атрибуте path.
     *
     * @param  Collection<int, Category>  $categories  все категории (id, parent_id, name)
     * @return Collection<int, Category>
     */
    public function categories(Collection $categories, SearchQuery $query): Collection
    {
        if ($query->isEmpty()) {
            return collect();
        }

        $byId = $categories->keyBy('id');

        return $categories->filter(function (Category $category) use ($query, $byId) {
            $names = [];
            for ($c = $category, $depth = 0; $c && $depth < 10; $c = $byId->get($c->parent_id), $depth++) {
                array_unshift($names, $c->name);
            }
            $category->setAttribute('path', implode(' / ', $names));

            $index = $this->text->index(...$names);

            foreach ($query->slots as $slot) {
                $found = false;
                foreach ($slot->variants() as $variant) {
                    if (array_filter($variant, fn (SearchToken $t) => ! $t->matches($index)) === []) {
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    private function whereWordContains(Builder $query, string $word, ?int $organizationId, bool $withDescription): void
    {
        $like = '%'.mb_strtolower($word).'%';

        $query->whereRaw('LOWER(sku) LIKE ?', [$like])
            ->orWhereRaw('LOWER(article) LIKE ?', [$like]);

        if ($withDescription) {
            $query->orWhereRaw('LOWER(description) LIKE ?', [$like]);
        }

        if ($organizationId) {
            $query->orWhere(fn ($code) => $this->codes->whereCodeContains($code, $word, $organizationId));
        }
    }

    private function order(Builder $products, SearchQuery $query, ?int $organizationId): void
    {
        if ($organizationId) {
            $products->orderByRaw(
                'CASE WHEN EXISTS (SELECT 1 FROM product_client_codes WHERE product_client_codes.product_id = products.id'
                .' AND product_client_codes.organization_id = ? AND product_client_codes.code_key = ?) THEN 0 ELSE 1 END',
                [$organizationId, ProductClientCode::key($query->text)]
            );
        }

        $exact = mb_strtolower($query->text);
        $products->orderByRaw('CASE WHEN LOWER(TRIM(article)) = ? OR LOWER(TRIM(sku)) = ? THEN 0 ELSE 1 END', [$exact, $exact]);

        // Все слова в названии как есть; все слова по основам (без синонимов)
        $title = [];
        $titleBindings = [];
        $own = [];
        $ownBindings = [];

        foreach ($query->slots as $slot) {
            foreach ($slot->words as $word) {
                $title[] = "LOWER(COALESCE(NULLIF(display_name, ''), name)) LIKE ?";
                $titleBindings[] = '%'.mb_strtolower($word).'%';
            }

            [$sql, $bindings] = $this->allTokens($slot->own);
            $own[] = $sql;
            array_push($ownBindings, ...$bindings);
        }

        $products->orderByRaw(
            'CASE WHEN '.implode(' AND ', $title).' THEN 0 WHEN '.implode(' AND ', $own).' THEN 1 ELSE 2 END',
            [...$titleBindings, ...$ownBindings]
        );

        $products->orderBy('name')->orderBy('id');
    }

    /**
     * Все слова фразы в search_text.
     *
     * @param  list<SearchToken>  $tokens
     * @return array{0: string, 1: list<string>}
     */
    private function allTokens(array $tokens): array
    {
        $parts = [];
        $bindings = [];

        foreach ($tokens as $token) {
            [$sql, $values] = $token->sql();
            $parts[] = $sql;
            array_push($bindings, ...$values);
        }

        return ['('.implode(' AND ', $parts).')', $bindings];
    }
}
