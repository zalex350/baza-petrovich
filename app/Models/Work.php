<?php

namespace App\Models;

use App\Support\Html;
use Illuminate\Database\Eloquent\Model;

/**
 * «Наши работы»: объект (забор, ворота, кровля…) — описание (HTML, очищается при выводе), место, метка
 * для фильтра (tag: «Заборы», «Ворота»…), галерея (WorkImage). Порядок — sort_order, затем новые первыми.
 */
class Work extends Model
{
    protected $fillable = ['title', 'slug', 'description', 'location', 'tag', 'completed_on', 'is_published', 'sort_order', 'seo_title', 'seo_description'];

    protected $casts = [
        'completed_on' => 'date',
        'is_published' => 'boolean',
    ];

    public function images()
    {
        return $this->hasMany(WorkImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function cover()
    {
        return $this->hasOne(WorkImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderByDesc('completed_on')->orderByDesc('id');
    }

    public function url(): string
    {
        return '/nashi-raboty/'.$this->slug;
    }

    public function safeDescription(): string
    {
        return Html::clean((string) $this->description);
    }
}
