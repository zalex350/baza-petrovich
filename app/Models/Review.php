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
    protected $fillable = ['author', 'text', 'image', 'group', 'rating', 'work_id', 'reviewed_on', 'is_published', 'sort_order'];

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
        return $query->orderBy('sort_order')->orderByDesc('reviewed_on')->orderByDesc('id');
    }

    public function imageUrl(): ?string
    {
        return $this->image ? Storage::disk(ProductImage::disk())->url($this->image) : null;
    }
}
