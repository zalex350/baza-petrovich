<?php

namespace App\Models;

use App\Services\PageTargets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Квиз: шаги-вопросы (steps), в конце контакты → заявка (Lead type quiz, ответы — payload).
 *
 * steps: [{question, type: single|multi|number|text, options: [строки], required: bool, hint}]
 * display: inline — блок внизу страницы; popup — кнопка «button_label» сбоку и окно; both — оба.
 * targets: места показа (App\Services\PageTargets).
 */
class Quiz extends Model
{
    public const TYPES = ['single' => 'Один вариант', 'multi' => 'Несколько вариантов', 'number' => 'Число', 'text' => 'Свой ответ (текст)'];

    public const DISPLAY = ['inline' => 'Блок на странице', 'popup' => 'Кнопка и всплывающее окно', 'both' => 'Блок и кнопка'];

    protected $fillable = ['title', 'intro', 'button_label', 'display', 'steps', 'result_title', 'result_text', 'targets', 'is_active', 'sort_order'];

    protected $casts = [
        'steps' => 'array',
        'targets' => 'array',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    /** Активные квизы для текущей страницы */
    public static function forPage(): Collection
    {
        $targets = app(PageTargets::class);

        return $targets->current() === [] ? collect() : static::active()->get()->filter(fn (Quiz $q) => $targets->matches($q->targets))->values();
    }

    public function inline(): bool
    {
        return in_array($this->display, ['inline', 'both'], true);
    }

    public function popup(): bool
    {
        return in_array($this->display, ['popup', 'both'], true);
    }

    /**
     * Шаги без пустых вопросов и вариантов; тип по умолчанию — single.
     *
     * @return list<array{question: string, type: string, options: list<string>, required: bool, hint: ?string}>
     */
    public static function normalizeSteps(mixed $steps): array
    {
        $out = [];

        foreach (is_array($steps) ? $steps : [] as $step) {
            $question = trim((string) ($step['question'] ?? ''));

            if ($question === '') {
                continue;
            }

            $type = isset(self::TYPES[$step['type'] ?? '']) ? $step['type'] : 'single';
            $options = array_values(array_filter(array_map(fn ($o) => mb_substr(trim((string) $o), 0, 200), (array) ($step['options'] ?? [])), fn ($o) => $o !== ''));

            if (in_array($type, ['single', 'multi'], true) && $options === []) {
                $type = 'text';
            }

            $out[] = [
                'question' => mb_substr($question, 0, 300),
                'type' => $type,
                'options' => in_array($type, ['single', 'multi'], true) ? array_slice($options, 0, 20) : [],
                'required' => (bool) ($step['required'] ?? true),
                'hint' => ($hint = trim((string) ($step['hint'] ?? ''))) !== '' ? mb_substr($hint, 0, 300) : null,
            ];
        }

        return array_slice($out, 0, 15);
    }
}
