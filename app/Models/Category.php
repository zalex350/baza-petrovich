<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * is_hidden — служебная категория («Без категории»): нет в меню и каталоге, товары находятся поиском.
 */
class Category extends Model
{
    /** Служебная категория для товаров, которые не удалось разложить (импорт каталога) */
    public const UNCATEGORIZED = 'Без категории';

    protected $fillable = [
        'name',
        'slug',
        'parent_id',
        'sort_order',
        'is_hidden',
        'image',
    ];

    protected $casts = [
        'is_hidden' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** Порядок среди соседей (админка «Категории»), при равном — по названию */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function scopeVisible($query)
    {
        return $query->where('is_hidden', false);
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id')->ordered();
    }

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Картинка категории (webp, плитки подкатегорий) или null.
     */
    public function imageUrl(): ?string
    {
        return $this->image ? Storage::disk(ProductImage::disk())->url($this->image) : null;
    }
}
