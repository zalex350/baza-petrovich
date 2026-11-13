<?php

namespace App\Services\Search;

/**
 * Слово поиска: как написано (нижний регистр) и его основа (SearchText::stem).
 */
final class SearchToken
{
    public function __construct(
        public readonly string $word,
        public readonly string $stem,
    ) {}

    /**
     * Условие на products.search_text: основа целым словом или слово — началом слова индекса
     * («смеси» найдёт «смеситель», «ванна» → основа «ван» — «ванны», но не «вантуз»).
     *
     * @return array{0: string, 1: list<string>}
     */
    public function sql(string $column = 'search_text'): array
    {
        if ($this->stem === $this->word) {
            return ["{$column} LIKE ?", ['% '.$this->word.'%']];
        }

        return ["({$column} LIKE ? OR {$column} LIKE ?)", ['% '.$this->stem.' %', '% '.$this->word.'%']];
    }

    /** То же для текста индекса в PHP (категории в подсказках) */
    public function matches(string $index): bool
    {
        return str_contains($index, ' '.$this->stem.' ') || str_contains($index, ' '.$this->word);
    }
}
