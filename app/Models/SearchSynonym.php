<?php

namespace App\Models;

use App\Services\Search\SearchSynonyms;
use Illuminate\Database\Eloquent\Model;

/**
 * Синонимы поиска (админка «Синонимы поиска», SearchSynonyms).
 * targets пусто — группа: слова terms равнозначны в обе стороны («смеситель, кран»);
 * задано — замена в одну сторону: по словам terms ищутся ещё и targets («мойка → раковина»).
 * Слова через запятую; слово может быть фразой («душевая стойка»). Кэш правил сбрасывается
 * при каждом сохранении и удалении.
 */
class SearchSynonym extends Model
{
    protected $fillable = ['terms', 'targets'];

    protected static function booted(): void
    {
        static::saved(fn () => SearchSynonyms::flush());
        static::deleted(fn () => SearchSynonyms::flush());
    }

    public function isOneWay(): bool
    {
        return trim((string) $this->targets) !== '';
    }

    /** Правило строкой: «смеситель, кран» или «мойка → раковина» */
    public function line(): string
    {
        return $this->isOneWay() ? $this->terms.' → '.$this->targets : $this->terms;
    }

    /**
     * Разбор строки из админки: «a, b» — группа, «a → b» (или «->», «=>») — в одну сторону.
     *
     * @return array{terms: string, targets: ?string}
     */
    public static function parseLine(string $line): array
    {
        $parts = preg_split('/\s*(?:→|->|=>)\s*/u', trim($line), 2);
        $clean = fn (string $list) => implode(', ', self::split($list));

        return [
            'terms' => $clean($parts[0] ?? ''),
            'targets' => isset($parts[1]) && self::split($parts[1]) !== [] ? $clean($parts[1]) : null,
        ];
    }

    /**
     * Слова списка через запятую (или «;»), без пустых и повторов, пробелы схлопнуты.
     *
     * @return list<string>
     */
    public static function split(?string $list): array
    {
        $items = preg_split('/[,;]/u', (string) $list) ?: [];
        $items = array_map(fn ($w) => trim((string) preg_replace('/\s+/u', ' ', $w)), $items);

        return array_values(array_unique(array_filter($items, fn ($w) => $w !== '')));
    }
}
