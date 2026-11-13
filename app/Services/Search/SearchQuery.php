<?php

namespace App\Services\Search;

/**
 * Разобранный запрос (ProductSearch::parse): исходный текст и части. Товар подходит,
 * если подходит под каждую часть (все слова запроса, порядок не важен).
 */
final class SearchQuery
{
    /**
     * @param  list<SearchSlot>  $slots
     */
    public function __construct(
        public readonly string $text,
        public readonly array $slots,
    ) {}

    public function isEmpty(): bool
    {
        return $this->slots === [];
    }

    /**
     * Во что развернулся запрос — для «Проверить запрос» в админке.
     *
     * @return list<array{words: string, stems: string, synonyms: list<string>}>
     */
    public function describe(): array
    {
        return array_map(fn (SearchSlot $slot) => [
            'words' => implode(' ', $slot->words),
            'stems' => SearchSlot::key($slot->own),
            'synonyms' => array_map(fn (array $phrase) => SearchSlot::phrase($phrase), $slot->synonyms),
        ], $this->slots);
    }
}
