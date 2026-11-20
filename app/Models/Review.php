<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Отзыв: текст и/или фото (скан письменного отзыва), группа («Заборы «под ключ»», «Доставка и продажа металла»),
 * оценка 1–5, связь с объектом «Наши работы».
 */
class Review extends Model
{
    protected $fillable = ['author', 'text', 'image', 'group', 'source', 'rating', 'work_id', 'reviewed_on', 'is_published', 'sort_order'];

    /** Источник: значок на карточке; Google и Яндекс — первыми в списках */
    public const SOURCES = ['google' => 'Google', 'yandex' => 'Яндекс', 'letter' => 'Письменный отзыв'];

    /** Отзывы на картах (не сканы писем) */
    public const EXTERNAL = ['google', 'yandex'];

    protected $casts = [
        'reviewed_on' => 'date',
        'is_published' => 'boolean',
        'rating' => 'integer',
    ];

    public function work()
    {
        return $this->belongsTo(Work::class);
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function scopeOrdered($query)
    {
        // Сначала отзывы Google и Яндекса, затем остальные с текстом, сканы писем — в конце
        return $query
            ->orderByRaw("CASE WHEN source IN ('google', 'yandex') THEN 0 WHEN source = 'letter' OR text IS NULL OR text = '' THEN 2 ELSE 1 END")
            ->orderBy('sort_order')->orderByDesc('reviewed_on')->orderByDesc('id');
    }

    public function isExternal(): bool
    {
        return in_array($this->source, self::EXTERNAL, true);
    }

    /** Скан письменного отзыва (бланк) — показывается вертикальной миниатюрой; фото объекта — горизонтальным */
    public function isScan(): bool
    {
        return $this->source === 'letter' || ($this->source === null && blank($this->text));
    }

    public function imageUrl(): ?string
    {
        return $this->image ? Storage::disk(ProductImage::disk())->url($this->image) : null;
    }
}
