<?php

namespace App\Models;

use App\Services\Vat\VatCalculator;
use App\Services\Vat\VatLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * guid — GUID номенклатуры 1С: заполняется только обменом с 1С, поэтому не в $fillable.
 */
class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'display_name',
        'sku',
        'price',
        'vat_rate',
        'stock',
        'on_order',
        'min_qty',
        'qty_step',
        'default_qty',
        'description',
        'description_generated',
        'category_id',
        'specifications',
        'brand',
        'slug',
        'is_active',
        'is_published',
        'is_hit',
        'is_new',
        'package_contents',
        'video_url',
    ];

    protected $attributes = [
        'vat_rate' => 20.00,
        'on_order' => false,
        'min_qty' => 1,
        'qty_step' => 1,
        'default_qty' => 1,
        'is_hit' => false,
        'is_new' => false,
    ];

    protected $casts = [
        'on_order' => 'boolean',
        'min_qty' => 'integer',
        'qty_step' => 'integer',
        'default_qty' => 'integer',
        'is_hit' => 'boolean',
        'is_new' => 'boolean',
        // Описание сгенерировано: на сайте не показывается, пока его не одобрят в админке
        'description_generated' => 'boolean',
    ];

    /**
     * Цена в БД — без НДС; vat_rate = null — «Без НДС».
     * Расчёт — VatCalculator; здесь — справочно за одну единицу.
     */
    public function vatLine(int $quantity = 1): VatLine
    {
        return app(VatCalculator::class)->line($this->price, $quantity, $this->vat_rate);
    }

    public function getVatAmountAttribute(): float
    {
        return $this->vatLine()->vat;
    }

    public function getPriceWithVatAttribute(): float
    {
        return $this->vatLine()->gross;
    }

    /**
     * Есть ли настоящее название: у товаров каталога без названия в name записан sku.
     */
    public function hasRealName(): bool
    {
        $name = trim((string) $this->name);

        return $name !== '' && mb_strtoupper($name) !== mb_strtoupper(trim((string) $this->sku));
    }

    /**
     * Название для сайта и админки: отображаемое → системное → «БРЕНД артикул» (без бренда — артикул).
     * Артикул — sku без префикса «БРЕНД-» (так sku собран в каталоге).
     */
    public function title(): string
    {
        if (trim((string) $this->display_name) !== '') {
            return $this->display_name;
        }

        if ($this->hasRealName()) {
            return $this->name;
        }

        $sku = trim((string) $this->sku);
        $brand = trim((string) $this->brand);
        $prefix = mb_strtoupper($brand).'-';

        if ($brand !== '' && str_starts_with(mb_strtoupper($sku), $prefix)) {
            return $brand.' '.mb_substr($sku, mb_strlen($prefix));
        }

        return $brand !== '' && mb_strtoupper($sku) !== mb_strtoupper($brand) ? "{$brand} {$sku}" : $sku;
    }

    /**
     * Товары без названия: пусто или sku вместо названия (админка, фильтр «Без названия»).
     */
    public function scopeWithoutName($query)
    {
        return $query->where(fn ($q) => $q->whereNull('name')->orWhere('name', '')->orWhereColumn('name', 'sku'));
    }

    public function getVatRateLabelAttribute(): string
    {
        return VatCalculator::rateLabel($this->vat_rate);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Галерея в порядке показа (BR-052).
     */
    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Галерея карточки: основное фото первым, остальные — в порядке из админки (BR-051, BR-052).
     *
     * @return Collection<int, ProductImage>
     */
    public function gallery()
    {
        return $this->images->sortByDesc('is_main')->values();
    }

    /**
     * Основное фото (BR-051) — для списков товаров, корзины, заказов.
     */
    public function mainImage()
    {
        return $this->hasOne(ProductImage::class)->where('is_main', true);
    }

    public function draft()
    {
        return $this->hasOne(ProductDraft::class);
    }

    /**
     * Коды товара у организаций-клиентов. Для списков — только коды одной организации:
     * Product::query()->withClientCodeFor($organization) или Product::loadClientCodesFor($products, $organization).
     */
    public function clientCodes()
    {
        return $this->hasMany(ProductClientCode::class);
    }

    /**
     * Код товара у организации или null. Если коды подгружены (withClientCodeFor /
     * loadClientCodesFor) — без запроса, иначе — один запрос.
     */
    public function codeFor(?Organization $organization): ?string
    {
        if (! $organization) {
            return null;
        }

        if ($this->relationLoaded('clientCodes')) {
            return $this->clientCodes->firstWhere('organization_id', $organization->id)?->code;
        }

        return $this->clientCodes()->where('organization_id', $organization->id)->value('code');
    }

    /**
     * Подгрузить коды одной организации для запроса списка (без N+1).
     */
    public function scopeWithClientCodeFor($query, ?Organization $organization)
    {
        return $query->with(['clientCodes' => fn ($q) => $q->where('organization_id', $organization?->id ?? 0)]);
    }

    /**
     * То же для уже загруженных товаров (страница пагинатора, коллекция): один запрос на все.
     *
     * @param  iterable<Product>  $products
     */
    public static function loadClientCodesFor(iterable $products, ?Organization $organization): void
    {
        // iterator_to_array: пагинатор — Arrayable, make() взял бы его служебный массив
        (new \Illuminate\Database\Eloquent\Collection(iterator_to_array($products, false)))
            ->load(['clientCodes' => fn ($q) => $q->where('organization_id', $organization?->id ?? 0)]);
    }

    /**
     * Характеристики в порядке из админки (BR-061).
     */
    public function attributeValues()
    {
        return $this->hasMany(ProductAttributeValue::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Строки таблицы характеристик карточки: в заданном порядке, пустые не выводятся (BR-062).
     *
     * @return Collection<int, array{name: string, value: string}>
     */
    public function specRows(string $group = 'main')
    {
        return $this->attributeValues
            ->filter(fn (ProductAttributeValue $v) => $v->attribute && trim((string) $v->value) !== '')
            ->filter(fn (ProductAttributeValue $v) => match ($group) {
                // «Характеристики»: без логистики и служебных (только для админки)
                'main' => ! $v->attribute->is_logistics && ! $v->attribute->is_admin_only,
                // «Упаковка и логистика» — свёрнутый блок карточки
                'logistics' => $v->attribute->is_logistics && ! $v->attribute->is_admin_only,
                default => true,
            })
            ->map(fn (ProductAttributeValue $v) => ['name' => $v->attribute->name, 'value' => $v->displayValue()])
            ->values();
    }
}
