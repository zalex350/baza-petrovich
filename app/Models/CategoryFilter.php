<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Фильтр каталога, включённый для категории (BR-016): стандартный (производитель, цена,
 * наличие) или характеристика из справочника. Порядок — sort_order. Категория без
 * своих строк наследует фильтры ближайшего родителя с настройкой.
 */
class CategoryFilter extends Model
{
    public const BRAND = 'brand';

    public const PRICE = 'price';

    public const STOCK = 'stock';

    public const ATTRIBUTE = 'attribute';

    public const STANDARD = [
        self::BRAND => 'Производитель',
        self::PRICE => 'Цена',
        self::STOCK => 'Наличие',
    ];

    protected $fillable = ['category_id', 'type', 'attribute_id', 'sort_order'];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function attribute()
    {
        return $this->belongsTo(ProductAttribute::class, 'attribute_id');
    }

    /**
     * Ключ фильтра: brand, price, stock, attribute:12 — в форме настройки и при сравнении.
     */
    public function key(): string
    {
        return $this->type === self::ATTRIBUTE ? self::ATTRIBUTE . ':' . $this->attribute_id : $this->type;
    }
}
