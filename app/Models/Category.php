<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
        'is_hidden',
    ];

    protected $casts = [
        'is_hidden' => 'boolean',
    ];

    public function scopeVisible($query)
    {
        return $query->where('is_hidden', false);
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
