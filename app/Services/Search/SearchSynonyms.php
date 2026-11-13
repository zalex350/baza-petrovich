<?php

namespace App\Services\Search;

use App\Models\SearchSynonym;
use Illuminate\Support\Facades\Cache;

/**
 * Правила синонимов из БД (SearchSynonym), собранные в словарь «основы фразы → чем заменить».
 * Слова правил нормализуются как запрос (SearchText): «смесители» в группе = «смеситель».
 * Словарь кэшируется навсегда и сбрасывается при сохранении / удалении правила — без деплоя.
 */
class SearchSynonyms
{
    public const CACHE_KEY = 'search.synonyms.v1';

    public function __construct(private readonly SearchText $text) {}

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Самая длинная фраза из правил, совпадающая с началом списка основ, и её замены.
     *
     * @param  list<string>  $stems  основы слов запроса начиная с текущего
     * @return array{0: int, 1: list<list<SearchToken>>} сколько слов занято (0 — нет совпадения) и замены
     */
    public function match(array $stems): array
    {
        $dictionary = $this->dictionary();

        for ($length = min($dictionary['max'], count($stems)); $length >= 1; $length--) {
            $key = implode(' ', array_slice($stems, 0, $length));

            if (isset($dictionary['map'][$key])) {
                return [$length, array_map(
                    fn (array $phrase) => array_map(fn (array $t) => new SearchToken($t[0], $t[1]), $phrase),
                    $dictionary['map'][$key],
                )];
            }
        }

        return [0, []];
    }

    /**
     * @return array{max: int, map: array<string, list<list<array{0: string, 1: string}>>>}
     */
    public function dictionary(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => $this->compile(SearchSynonym::query()->orderBy('id')->get()));
    }

    /**
     * @param  iterable<SearchSynonym>  $rules
     * @return array{max: int, map: array<string, list<list<array{0: string, 1: string}>>>}
     */
    public function compile(iterable $rules): array
    {
        $map = [];
        $max = 0;

        foreach ($rules as $rule) {
            $from = $this->phrases($rule->terms);
            $to = $rule->isOneWay() ? $this->phrases($rule->targets) : $from;

            foreach ($from as $key => $phrase) {
                $max = max($max, count($phrase));

                foreach ($to as $targetKey => $target) {
                    if ($targetKey !== $key) {
                        $map[$key][$targetKey] = array_map(fn (SearchToken $t) => [$t->word, $t->stem], $target);
                    }
                }
            }
        }

        return ['max' => $max, 'map' => array_map('array_values', array_filter($map))];
    }

    /**
     * Фразы списка «a, b c» с основами, ключ — основы через пробел.
     *
     * @return array<string, list<SearchToken>>
     */
    private function phrases(?string $list): array
    {
        $phrases = [];

        foreach (SearchSynonym::split($list) as $item) {
            $tokens = $this->text->tokens($item);

            if ($tokens !== []) {
                $phrases[SearchSlot::key($tokens)] = $tokens;
            }
        }

        return $phrases;
    }
}
