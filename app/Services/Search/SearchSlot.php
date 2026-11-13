<?php

namespace App\Services\Search;

/**
 * Часть запроса: одно слово (или фраза синонима из нескольких слов) и чем его можно заменить.
 * Товар подходит, если в нём есть все слова своей части (own) или все слова одного из синонимов.
 */
final class SearchSlot
{
    /**
     * @param  list<string>  $words  слова запроса как введены
     * @param  list<SearchToken>  $own  их слова и основы
     * @param  list<list<SearchToken>>  $synonyms  замены: каждая — все слова должны найтись
     */
    public function __construct(
        public readonly array $words,
        public readonly array $own,
        public readonly array $synonyms = [],
    ) {}

    /** @return list<list<SearchToken>> своя часть и синонимы */
    public function variants(): array
    {
        return [$this->own, ...$this->synonyms];
    }

    /** Ключ фразы — основы через пробел */
    public static function key(array $tokens): string
    {
        return implode(' ', array_map(fn (SearchToken $t) => $t->stem, $tokens));
    }

    /** Фраза для людей — слова через пробел */
    public static function phrase(array $tokens): string
    {
        return implode(' ', array_map(fn (SearchToken $t) => $t->word, $tokens));
    }
}
