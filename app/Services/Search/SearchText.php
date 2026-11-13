<?php

namespace App\Services\Search;

use Wamania\Snowball\Stemmer\Stemmer;
use Wamania\Snowball\StemmerFactory;

/**
 * Нормализация текста для поиска: нижний регистр, «ё» → «е», слова из букв и цифр
 * (дефисы, точки и пробелы — разделители: «F-SL-C1166» → f, sl, c1166), без служебных слов
 * («для», «с», «в»…). Русские слова — к основе стеммером Snowball («смесители» и «смеситель» →
 * «смесител», «кухни» → «кухн»); латиница и слова с цифрами — как есть.
 *
 * Индекс товара (products.search_text) — « слово основа слово основа … » с пробелами по краям:
 * основа ищется целиком (« ван »), исходное слово — по началу (« смеси…»), см. SearchQuery.
 */
class SearchText
{
    /** Служебные слова: в индекс не попадают, из запроса выбрасываются */
    public const STOP_WORDS = [
        'а', 'без', 'в', 'во', 'для', 'до', 'за', 'и', 'из', 'или', 'к', 'ко', 'на', 'над',
        'о', 'об', 'от', 'по', 'под', 'при', 'с', 'со', 'у',
    ];

    private ?Stemmer $stemmer = null;

    /** @var array<string, string> */
    private array $stems = [];

    /**
     * Слова текста (нижний регистр, без служебных).
     *
     * @return list<string>
     */
    public function words(?string $text): array
    {
        $text = str_replace('ё', 'е', mb_strtolower((string) $text));
        $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($words, fn (string $w) => ! in_array($w, self::STOP_WORDS, true)));
    }

    /**
     * Слова текста с основами.
     *
     * @return list<SearchToken>
     */
    public function tokens(?string $text): array
    {
        return array_map(fn (string $w) => new SearchToken($w, $this->stem($w)), $this->words($text));
    }

    /** Основа слова: русское — стеммером, остальное — как есть */
    public function stem(string $word): string
    {
        if (! preg_match('/^\p{Cyrillic}+$/u', $word)) {
            return $word;
        }

        if (! isset($this->stems[$word])) {
            $this->stemmer ??= StemmerFactory::create('ru');
            $stem = (string) $this->stemmer->stem($word);
            $this->stems[$word] = $stem !== '' ? $stem : $word;
        }

        return $this->stems[$word];
    }

    /**
     * Текст индекса: слова и их основы без повторов, « … » с пробелами по краям.
     */
    public function index(?string ...$texts): string
    {
        $parts = [];

        foreach ($texts as $text) {
            foreach ($this->tokens($text) as $token) {
                $parts[$token->word] = true;
                $parts[$token->stem] = true;
            }
        }

        return $parts === [] ? '' : ' '.implode(' ', array_keys($parts)).' ';
    }
}
