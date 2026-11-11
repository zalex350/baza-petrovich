<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * Поиск товара по артикулу для покупателя: артикул производителя (products.article) и код сайта
 * (products.sku) — без учёта регистра и пробелов по краям. Артикул не уникален между брендами,
 * поэтому точный поиск возвращает все совпадения — выбирает покупатель (CartController::quickAdd).
 * Не нашлось — didYouMean: вариант без дефисов и пробелов («L47552» → «L4755-2»), только подсказка.
 */
class ArticleSearch
{
    /** Подсказок «Возможно, вы искали» — не больше */
    public const SUGGESTIONS = 3;

    /**
     * Условие «артикул или sku равен тексту».
     */
    public function whereExact(Builder $query, string $text): Builder
    {
        $text = mb_strtolower(trim($text));

        return $query->where(fn ($q) => $q
            ->whereRaw('LOWER(TRIM(article)) = ?', [$text])
            ->orWhereRaw('LOWER(TRIM(sku)) = ?', [$text]));
    }

    /**
     * Артикулы (для показа — Product::displayArticle), совпадающие с текстом без учёта дефисов
     * и пробелов, но не точно. Пусто — подсказки нет; короче 3 знаков — тоже.
     *
     * @return list<string>
     */
    public function didYouMean(Builder $query, string $text): array
    {
        $compact = self::compact($text);

        if (mb_strlen($compact) < 3) {
            return [];
        }

        $column = fn (string $name) => "REPLACE(REPLACE(LOWER(COALESCE({$name}, '')), '-', ''), ' ', '')";

        return $query
            ->where(fn ($q) => $q->whereRaw($column('article').' = ?', [$compact])->orWhereRaw($column('sku').' = ?', [$compact]))
            ->orderBy('id')
            ->limit(20)
            ->get(['id', 'sku', 'article'])
            ->map(fn (Product $p) => $p->displayArticle())
            ->reject(fn (string $article) => mb_strtolower(trim($article)) === mb_strtolower(trim($text)))
            ->unique(fn (string $article) => mb_strtolower($article))
            ->take(self::SUGGESTIONS)
            ->values()
            ->all();
    }

    private static function compact(string $text): string
    {
        return mb_strtolower((string) preg_replace('/[\s\p{Z}\-\x{2010}-\x{2015}\x{2212}]+/u', '', $text));
    }
}
