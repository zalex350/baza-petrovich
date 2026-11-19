<?php

namespace App\Models;

use App\Services\Pricing\Discounts;
use App\Services\Pricing\PriceContext;
use App\Services\Pricing\PriceTypes;
use App\Services\Search\SearchIndex;
use App\Services\StockReserve;
use App\Services\Vat\VatCalculator;
use App\Services\Vat\VatLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use LogicException;

/**
 * guid — GUID номенклатуры 1С: заполняется только обменом с 1С, поэтому не в $fillable.
 * Так же — поля номенклатуры 1С (docs/1C_EXCHANGE_SPEC.md, раздел 4): unit, barcode, group_1c и отметки обмена synced_at / price_synced_at /
 * stock_synced_at, остаток 1С stock_1c (stock — доступный: stock_1c − резерв сайта, App\Services\StockReserve). name — наименование 1С, display_name — название сайта (title()).
 * article — артикул производителя без бренда: его видят и ищут покупатели (displayArticle());
 * у товара без GUID правится в админке, с GUID — приходит из 1С. sku — внутренний код сайта
 * для обмена с 1С, клиентам не показывается.
 * match_key — ключ сопоставления «бренд + артикул» (matchKey()), пересчитывается при сохранении.
 * search_text — слова и основы для поиска (App\Services\Search\SearchIndex), тоже при сохранении.
 */
class Product extends Model
{
    /** Характеристика, которая показывается в разделе «Комплектация», а не в таблице */
    public const PACKAGE_ATTRIBUTE = 'Комплектация';

    use SoftDeletes;

    protected $fillable = [
        'name',
        'display_name',
        'sku',
        'article',
        'price',
        'discount_percent',
        'discount_amount',
        'vat_rate',
        'stock',
        'on_order',
        'min_qty',
        'qty_step',
        'default_qty',
        'description',
        'description_generated',
        'seo_title',
        'seo_description',
        'category_id',
        'specifications',
        'brand',
        'slug',
        'is_active',
        'is_published',
        'is_hit',
        'is_new',
        'is_new_locked',
        'is_promo',
        'package_contents',
        'video_url',
    ];

    protected $attributes = [
        'vat_rate' => 20.00,
        'unit' => 'шт',
        'on_order' => false,
        'min_qty' => 1,
        'qty_step' => 1,
        'default_qty' => 1,
        'is_hit' => false,
        'is_new' => false,
        'is_new_locked' => false,
        'is_promo' => false,
    ];

    protected $casts = [
        'on_order' => 'boolean',
        'min_qty' => 'integer',
        'qty_step' => 'integer',
        'default_qty' => 'integer',
        'is_hit' => 'boolean',
        'is_new' => 'boolean',
        // «Новинку» задали в админке — импорт каталога её не меняет
        'is_new_locked' => 'boolean',
        'is_promo' => 'boolean',
        // Описание сгенерировано: на сайте не показывается, пока его не одобрят в админке
        'description_generated' => 'boolean',
        'stock' => 'integer',
        'stock_1c' => 'integer',
        'synced_at' => 'datetime',
        'price_synced_at' => 'datetime',
        'stock_synced_at' => 'datetime',
        // Скрыт потому, что 1С прислала active = 0 (CatalogHandler); active = 1 показывает только такие
        'hidden_by_1c' => 'boolean',
    ];

    /**
     * Отметка в выборке: колонка price — уже цена покупателя (PriceContext::select), аксессор её не
     * пересчитывает. Такую модель не сохранить: price в ней не products.price.
     */
    public const PRICE_RESOLVED = 'price_resolved';

    protected static function booted(): void
    {
        static::saving(function (Product $product) {
            if (array_key_exists(self::PRICE_RESOLVED, $product->getAttributes())) {
                throw new LogicException('Товар выбран с ценой покупателя (withEffectivePrice) — сохранять нельзя');
            }

            // Остаток 1С и доступный остаток (stock = stock_1c − резерв сайта) — согласованы
            app(StockReserve::class)->sync($product);

            if (! $product->exists || $product->isDirty(['brand', 'article'])) {
                $product->match_key = self::matchKey($product->brand, $product->article);
            }

            // Цена со скидкой (Discounts): цена, скидка или категория изменились
            if (! $product->exists || $product->isDirty(['price', 'discount_percent', 'discount_amount', 'category_id'])) {
                $product->sale_price = app(Discounts::class)->forProduct($product);
            }

            // Слова и основы для поиска (SearchIndex): названия, бренд, категория
            if (! $product->exists || $product->search_text === null || $product->isDirty(SearchIndex::FIELDS)) {
                $product->search_text = app(SearchIndex::class)->textFor($product);
            }
        });

        // products.price — цена типа по умолчанию: строка product_prices следует за ней
        static::saved(function (Product $product) {
            // В saved original ещё прежний — новое значение берём из атрибутов
            $price = $product->getAttributes()['price'] ?? null;

            if ($product->wasRecentlyCreated && ! $product->wasChanged() ? $price !== null : $product->wasChanged('price')) {
                app(PriceTypes::class)->syncDefaultRow($product->id, $price);
            }
        });
    }

    /**
     * Цена без НДС для текущего покупателя (PriceContext): тип цены его организации, нет цены этого
     * типа — цена по умолчанию (products.price), нет и её — null («Цена уточняется»).
     * Сырое products.price — getRawOriginal('price').
     */
    protected function price(): Attribute
    {
        return Attribute::get(fn ($value, array $attributes) => array_key_exists(self::PRICE_RESOLVED, $attributes)
            ? $value
            : app(PriceContext::class)->priceOf($this, $value));
    }

    /** Цены по всем типам */
    public function prices()
    {
        return $this->hasMany(ProductPrice::class);
    }

    /**
     * Цена до скидки (зачёркнутая): у покупателя действует розничная цена со скидкой — products.price,
     * иначе null.
     */
    public function getOldPriceAttribute(): ?float
    {
        $raw = $this->getAttributes()['price'] ?? null;
        $sale = $this->getAttributes()['sale_price'] ?? null;

        if ($raw === null || $sale === null || app(PriceContext::class)->isSpecial(app(PriceContext::class)->typeIdFor($this))) {
            return null;
        }

        return (float) $this->price < (float) $raw ? (float) $raw : null;
    }

    /** Скидка в процентах от прежней цены (бейдж «−15%»); нет скидки — null */
    public function getDiscountBadgeAttribute(): ?int
    {
        $old = $this->old_price;

        return $old ? (int) round((1 - (float) $this->price / $old) * 100) : null;
    }

    /** Цена типа текущего покупателя (PriceContext::typeIdFor) — для аксессора price и eager load */
    public function clientPrice()
    {
        return $this->hasOne(ProductPrice::class)->where('price_type_id', app(PriceContext::class)->typeIdFor($this));
    }

    /** Списки: колонка price — цена покупателя одним join (без запроса на товар) */
    public function scopeWithEffectivePrice(Builder $query, ?int $typeId = null): Builder
    {
        app(PriceContext::class)->select($query, $typeId);

        return $query;
    }

    /**
     * Поля, закрытые от правки в админке: у товара с GUID 1С — поля, которыми владеет 1С
     * (config shop.exchange_1c.managed_fields, SHOP_1C_MANAGED_FIELDS), без GUID — нет.
     *
     * @return list<string>
     */
    public function lockedFrom1c(): array
    {
        // Минимальное количество и кратность в обмен не входят — правятся в админке всегда
        return $this->guid !== null
            ? array_values(array_diff((array) config('shop.exchange_1c.managed_fields', []), ['min_qty', 'qty_step']))
            : [];
    }

    /**
     * Поля только для чтения в админке: поля 1С (lockedFrom1c) и у товара с GUID — sku:
     * код сайта 1С хранит у себя и передаёт во всех потоках (спецификация, раздел 4, правило 6).
     *
     * @return list<string>
     */
    public function lockedFields(): array
    {
        return $this->guid !== null ? array_values(array_unique([...$this->lockedFrom1c(), 'sku'])) : [];
    }

    /** Кириллические буквы, неотличимые от латинских (после перевода в верхний регистр) */
    private const LOOKALIKES = [
        'А' => 'A', 'В' => 'B', 'Е' => 'E', 'К' => 'K', 'М' => 'M', 'Н' => 'H',
        'О' => 'O', 'Р' => 'P', 'С' => 'C', 'Т' => 'T', 'Х' => 'X',
    ];

    /**
     * Ключ первичного сопоставления с 1С: «БРЕНД:АРТИКУЛ» после normalizeKeyPart
     * («Ledeme», «l  4755–2» → «LEDEME:L 4755-2»). Дефисы значимы: L4755-2 и L47552 — разные
     * ключи. Без бренда или артикула — null: по одному артикулу товары разных брендов
     * не сопоставляются. Массовые обновления мимо модели пересчитывают ключ этим же методом.
     */
    public static function matchKey(?string $brand, ?string $article): ?string
    {
        $brand = self::normalizeKeyPart($brand);
        $article = self::normalizeKeyPart($article);
        // Артикул из одних дефисов и пробелов («-», « - ») — не артикул
        $meaningful = fn (string $value) => trim(str_replace('-', '', $value)) !== '';

        return $meaningful($brand) && $meaningful($article) ? "{$brand}:{$article}" : null;
    }

    /**
     * Верхний регистр; пробелы (и неразрывные) схлопнуты в один и обрезаны по краям;
     * кириллические двойники латиницы (А, В, Е, К, М, Н, О, Р, С, Т, Х) → латиница;
     * типографские дефисы, тире и минус («‐», «‑», «–», «—», «−») → «-».
     */
    public static function normalizeKeyPart(?string $value): string
    {
        $value = (string) preg_replace('/[\s\p{Z}]+/u', ' ', mb_strtoupper((string) $value));
        $value = (string) preg_replace('/[\x{2010}-\x{2015}\x{2212}]/u', '-', $value);

        return strtr(trim($value), self::LOOKALIKES);
    }

    /**
     * Артикул для покупателя: артикул производителя, без него — код сайта (sku).
     * SKU — внутренний код обмена с 1С, клиентам отдельно не показывается.
     */
    public function displayArticle(): string
    {
        $article = trim((string) $this->article);

        return $article !== '' ? $article : (string) $this->sku;
    }

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
        return $this->images->reject->is_placeholder->sortByDesc('is_main')->values();
    }

    /**
     * Основное фото (BR-051) — для списков товаров, корзины, заказов, плиток разделов.
     * Заглушки (is_placeholder) не в счёт: если основное — заглушка, берётся первое настоящее по порядку,
     * настоящих нет — null (товар без фото).
     */
    public function mainImage()
    {
        return $this->hasOne(ProductImage::class)
            ->where('is_placeholder', false)
            ->orderByDesc('is_main')
            ->orderBy('sort_order')
            ->orderBy('id');
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
     * Документы товара (SRS §4.8) в порядке из админки.
     */
    public function documents()
    {
        return $this->hasMany(ProductDocument::class)->orderBy('sort_order')->orderBy('id');
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
     * Характеристика «Комплектация» в таблицу не попадает — она в разделе «Комплектация»
     * (packageContents()).
     *
     * @return Collection<int, array{name: string, value: string}>
     */
    public function specRows(string $group = 'main')
    {
        return $this->attributeValues
            ->filter(fn (ProductAttributeValue $v) => $v->attribute && trim((string) $v->value) !== '')
            ->reject(fn (ProductAttributeValue $v) => self::isPackageAttribute($v->attribute->name))
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

    /**
     * Раздел «Комплектация» карточки — один источник: поле товара (package_contents), а если оно
     * пусто — характеристика «Комплектация» (обычно из импорта). source: field | attribute | null.
     *
     * @return array{text: string, source: ?string}
     */
    public function packageContents(): array
    {
        $field = trim((string) $this->package_contents);

        if ($field !== '') {
            return ['text' => $field, 'source' => 'field'];
        }

        $attribute = trim((string) $this->attributeValues
            ->first(fn (ProductAttributeValue $v) => $v->attribute && self::isPackageAttribute($v->attribute->name))
            ?->value);

        return $attribute !== ''
            ? ['text' => $attribute, 'source' => 'attribute']
            : ['text' => '', 'source' => null];
    }

    public static function isPackageAttribute(?string $name): bool
    {
        return ProductAttribute::key($name) === ProductAttribute::key(self::PACKAGE_ATTRIBUTE);
    }
}
