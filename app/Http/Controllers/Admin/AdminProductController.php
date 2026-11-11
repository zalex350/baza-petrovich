<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\UploadsProductImages;
use App\Http\Controllers\Controller;
use App\Http\Controllers\ProductController;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductDraft;
use App\Services\ProductAttributes;
use App\Services\ProductDrafts;
use App\Services\Vat\VatCalculator;
use Illuminate\Validation\Rule;

/**
 * Товары в админке. Создание и редактирование — одна форма (admin/product_form):
 * «Данные учётной системы (1С)» сохраняются сразу, тексты «Данных сайта» — в черновик,
 * на сайт после публикации. Кнопки формы — action: draft | preview | publish.
 * Бейджи (ХИТ, НОВИНКА, Акция) у существующего товара — сразу по галочке: badge().
 */
class AdminProductController extends Controller
{
    use UploadsProductImages;

    public function __construct(
        private readonly ProductAttributes $attributes,
        private readonly ProductDrafts $drafts,
    ) {}

    /** Поля учётной системы: при shop.catalog.manual_products = false приходят только из 1С */
    private const ERP_FIELDS = ['name', 'sku', 'brand', 'price', 'vat_rate', 'stock', 'on_order', 'min_qty', 'qty_step', 'default_qty'];

    /** Бейджи витрины: у существующего товара сохраняются сразу по галочке (badge) */
    private const BADGE_FIELDS = ['is_hit', 'is_new', 'is_promo'];

    /** Данные сайта, которые применяются сразу, без черновика (SRS §11.3: категория — сайта, не 1С) */
    private const DIRECT_SITE_FIELDS = ['slug', 'category_id', 'is_hit', 'is_new', 'is_promo'];

    /** Тексты сайта: через черновик */
    private const DRAFT_FIELDS = ProductDrafts::FIELDS;

    public function index()
    {
        // «Без категории»: служебная категория (скрыта на сайте) или категория не задана;
        // «Без названия»: пусто или sku вместо названия (на сайте — «БРЕНД артикул»);
        // «Описание не одобрено»: сгенерированное импортом, на сайте не показывается
        // Поиск (артикул, название, бренд) работает вместе с фильтром; оба — в ссылках пагинации
        $uncategorized = request('category') === 'none';
        $unnamed = request('name') === 'none';
        $unapproved = request('description') === 'unapproved';
        $search = mb_substr(trim((string) request('q')), 0, 100);
        $hiddenIds = Category::where('is_hidden', true)->pluck('id');
        $noCategory = fn ($q) => $q->where(fn ($q) => $q->whereNull('category_id')->orWhereIn('category_id', $hiddenIds));
        $matches = fn ($q) => $q->when($search !== '', fn ($q) => $q->where(function ($q) use ($search) {
            $like = '%'.$search.'%';
            $q->where('sku', 'like', $like)->orWhere('name', 'like', $like)->orWhere('display_name', 'like', $like)->orWhere('brand', 'like', $like);
        }));

        $products = Product::with('draft')
            ->tap($matches)
            ->when($uncategorized, $noCategory)
            ->when($unnamed, fn ($q) => $q->withoutName())
            ->when($unapproved, fn ($q) => $q->where('description_generated', true))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        // Счётчики фильтров — среди найденного
        $uncategorizedCount = Product::query()->tap($matches)->tap($noCategory)->count();
        $unnamedCount = Product::query()->tap($matches)->withoutName()->count();
        $unapprovedCount = Product::query()->tap($matches)->where('description_generated', true)->count();

        // Активный фильтр — для скрытого поля формы поиска и ссылок «Показать все»
        $filter = array_filter([
            'category' => $uncategorized ? 'none' : null,
            'name' => $unnamed ? 'none' : null,
            'description' => $unapproved ? 'unapproved' : null,
        ]);

        return view('admin.products', compact('products', 'uncategorized', 'uncategorizedCount', 'unnamed', 'unnamedCount', 'unapproved', 'unapprovedCount', 'search', 'filter'));
    }

    public function create()
    {
        $this->ensureManualProducts();

        return view('admin.product_form', [
            // Цена пустая: её обязательно ввести, 0 не подставляется (F-6)
            'product' => new Product(['vat_rate' => 20.00, 'stock' => 0]),
            'categories' => Category::all(),
            'draft' => null,
            'attributeRows' => $this->attributeRows(collect()),
        ]);
    }

    public function store()
    {
        $this->ensureManualProducts();

        $data = request()->validate($this->erpRules() + $this->siteRules() + [
            'is_active' => 'boolean',
        ]);

        $data = $this->normalizeVat($data);

        $data['is_active'] = request()->boolean('is_active');
        $data['is_published'] = $this->action() === 'publish';

        // Характеристики проверяются до создания товара: ошибка — товар не создаётся
        $set = request()->has('attributes_sent') ? $this->attributes->fromRows((array) request('attributes', [])) : [];

        // Новый товар на сайте не виден до публикации — тексты пишутся сразу в товар
        $product = Product::create($data);

        $this->attributes->sync($product, $set);

        return $this->afterSave($product, 'Товар создан');
    }

    public function toggleVisibility($id)
    {
        $product = Product::findOrFail($id);

        $product->update(['is_active' => ! $product->is_active]);

        return back()->with(
            'success',
            $product->is_active ? 'Товар показан на сайте' : 'Товар скрыт с сайта'
        );
    }

    /**
     * Сгенерированное описание проверено — показывать на сайте.
     */
    public function approveDescription($id)
    {
        Product::findOrFail($id)->update(['description_generated' => false]);

        return back()->with('success', 'Описание одобрено и показывается на сайте');
    }

    public function destroy($id)
    {
        Product::findOrFail($id)->delete();

        return redirect('/admin/products')->with('success', 'Товар удалён');
    }

    public function show($id)
    {
        $product = Product::with(['images', 'attributeValues.attribute'])->findOrFail($id);
        $draft = $product->draft;

        // Характеристики: черновые, если есть, иначе опубликованные
        $values = isset($draft?->data['attributes'])
            ? $this->attributes->toModels($draft->data['attributes'])
            : $product->attributeValues;

        return view('admin.product_form', [
            'product' => $product,
            'categories' => Category::all(),
            'draft' => $draft,
            'attributeRows' => $this->attributeRows($values),
            ...$this->clientCodes($product),
        ]);
    }

    /**
     * Блок «Коды клиентов»: коды товара и клиенты, у которых кода этого товара ещё нет.
     */
    private function clientCodes(Product $product): array
    {
        $clientCodes = $product->clientCodes()
            ->with('organization:id,name')
            ->get()
            ->sortBy(fn ($code) => mb_strtolower((string) $code->organization?->name))
            ->values();

        return [
            'clientCodes' => $clientCodes,
            'codeOrganizations' => Organization::whereNotIn('id', $clientCodes->pluck('organization_id'))
                ->orderBy('name')
                ->pluck('name', 'id'),
        ];
    }

    /**
     * Сохранение формы: поля 1С — сразу (если товары заводятся вручную), slug — сразу,
     * тексты — в черновик, фото из очереди — сразу. Затем — по кнопке.
     */
    public function update($id)
    {
        $product = Product::findOrFail($id);

        $manual = config('shop.catalog.manual_products');

        $data = request()->validate(
            ($manual ? $this->erpRules($product) : []) + $this->siteRules($product)
        );

        // Характеристики — до записи: ошибка в них не сохраняет ничего
        if (request()->has('attributes_sent')) {
            // Не менявшиеся значения (товара и черновика) — как есть, даже нечисловые
            $known = [...$this->attributes->current($product), ...($product->draft?->data['attributes'] ?? [])];
            $data['attributes'] = $this->attributes->fromRows((array) request('attributes', []), $known);
        }

        $direct = $manual ? $this->normalizeVat(array_intersect_key($data, array_flip(self::ERP_FIELDS))) : [];

        foreach (self::DIRECT_SITE_FIELDS as $field) {
            if (request()->has($field)) {
                $direct[$field] = $data[$field] ?? null;
            }
        }

        $product->update($this->lockNewBadge($product, $direct));

        $this->saveDraftFields($product, $data);

        return $this->afterSave($product, 'Товар сохранён');
    }

    /**
     * Бейдж (ХИТ, НОВИНКА, Акция) — сразу по галочке, без кнопок формы (admin-product-badges.js).
     */
    public function badge($id)
    {
        $product = Product::findOrFail($id);

        $data = request()->validate([
            'field' => ['required', Rule::in(self::BADGE_FIELDS)],
            'value' => 'required|boolean',
        ]);

        $product->update($this->lockNewBadge($product, [$data['field'] => (bool) $data['value']]));

        return response()->json(['message' => 'Сохранено', 'value' => (bool) $product->{$data['field']}]);
    }

    /**
     * Сохранить только тексты в черновик (прежняя отдельная форма; оставлена для совместимости).
     */
    public function saveDraft($id)
    {
        $product = Product::findOrFail($id);

        // Черновые характеристики, если есть, сохраняются; переносы строк — как в базе
        $values = array_merge($product->draft?->data ?? [], collect(ProductDrafts::FIELDS)
            ->mapWithKeys(fn ($field) => [$field => request()->filled($field) ? str_replace(["\r\n", "\r"], "\n", request($field)) : null])
            ->all());

        // Черновик — только если отличается от опубликованного
        if (! $this->drafts->differs($product, $values)) {
            $product->draft()->delete();

            return back()->with('success', 'Изменений нет — черновик не нужен');
        }

        ProductDraft::updateOrCreate(['product_id' => $product->id], ['data' => $values]);

        return back()->with('success', 'Черновик сохранён');
    }

    public function publish($id)
    {
        $this->publishProduct(Product::findOrFail($id));

        return back()->with('success', 'Товар опубликован');
    }

    public function deleteDraft($id)
    {
        $product = Product::findOrFail($id);
        $product->draft()->delete();

        return back()->with('success', 'Черновик удалён');
    }

    public function preview($id)
    {
        $product = Product::findOrFail($id);
        $draft = $product->draft;

        if ($draft) {
            $data = $draft->data;

            $product->display_name = $data['display_name'] ?? $product->display_name;
            $product->description = $data['description'] ?? $product->description;
            $product->specifications = $data['specifications'] ?? $product->specifications;
            $product->package_contents = $data['package_contents'] ?? $product->package_contents;

            if (isset($data['attributes'])) {
                $product->setRelation('attributeValues', $this->attributes->toModels($data['attributes']));
            }
        }

        $preview = ['draft' => (bool) $draft];

        if (! $product->relationLoaded('attributeValues')) {
            $product->load('attributeValues.attribute');
        }

        return view('product', ProductController::cardData($product) + compact('preview'));
    }

    // --- Внутреннее ---

    private function ensureManualProducts(): void
    {
        abort_unless(config('shop.catalog.manual_products'), 403, 'Товары приходят из 1С — создание в админке отключено');
    }

    private function action(): string
    {
        return in_array(request('action'), ['draft', 'preview', 'publish'], true) ? request('action') : 'draft';
    }

    /**
     * «Новинку» изменили вручную — импорт каталога её больше не трогает (A-6).
     */
    private function lockNewBadge(Product $product, array $changes): array
    {
        if (array_key_exists('is_new', $changes) && (bool) $changes['is_new'] !== $product->is_new) {
            $changes['is_new_locked'] = true;
        }

        return $changes;
    }

    private function erpRules(?Product $product = null): array
    {
        return [
            'name' => 'required|string|max:255',
            'sku' => ['required', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($product?->id)],
            'brand' => 'nullable|string|max:255',
            // Цена нового товара обязательна и больше 0 при любой кнопке (F-6). У товара с ценой
            // стереть её нельзя. У товара без цены (из импорта каталога — цена придёт из 1С) поле
            // может остаться пустым: иначе форма не сохраняла ничего — ни категорию, ни тексты
            'price' => $product
                ? [$product->price === null ? 'nullable' : 'required', 'numeric', 'min:0']
                : ['required', 'numeric', 'gt:0'],
            'vat_rate' => ['sometimes', 'required', VatCalculator::rateRule()],
            'stock' => 'required|integer|min:0',
            'on_order' => 'boolean',
            // Кратность заказа: не отправлены — остаются прежние (по умолчанию 1)
            'min_qty' => 'sometimes|required|integer|min:1',
            'qty_step' => 'sometimes|required|integer|min:1',
            'default_qty' => ['sometimes', 'required', 'integer', 'min:1', $this->defaultQtyRule($product)],
        ];
    }

    /**
     * Количество по умолчанию — одно из допустимых: min_qty, min_qty + qty_step, …
     */
    private function defaultQtyRule(?Product $product): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($product) {
            $min = (int) request('min_qty', $product?->min_qty ?? 1);
            $step = max(1, (int) request('qty_step', $product?->qty_step ?? 1));

            if ($min >= 1 && ((int) $value < $min || ((int) $value - $min) % $step !== 0)) {
                $fail("Количество по умолчанию — не меньше минимума ({$min}) и с шагом {$step}.");
            }
        };
    }

    private function siteRules(?Product $product = null): array
    {
        return [
            'category_id' => 'nullable|exists:categories,id',
            'display_name' => 'nullable|string|max:255',
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('products', 'slug')->ignore($product?->id)],
            'description' => 'nullable|string',
            'specifications' => 'nullable|string',
            'package_contents' => 'nullable|string',
            'is_hit' => 'boolean',
            'is_new' => 'boolean',
            'is_promo' => 'boolean',
        ];
    }

    private function normalizeVat(array $data): array
    {
        if (array_key_exists('vat_rate', $data)) {
            $data['vat_rate'] = VatCalculator::normalizeRate($data['vat_rate']);
        }

        return $data;
    }

    /**
     * Тексты и характеристики пришли и по смыслу отличаются от опубликованных (ProductDrafts) —
     * в черновик. Сохранение без правок черновик не создаёт; черновик, ставший равным
     * опубликованному, удаляется.
     */
    private function saveDraftFields(Product $product, array $data): void
    {
        $sent = array_intersect(self::DRAFT_FIELDS, array_keys(request()->all()));
        $attributesSent = array_key_exists('attributes', $data);

        if (! $sent && ! $attributesSent) {
            return;
        }

        $draft = $product->draft;
        $values = $draft?->data ?? array_combine(self::DRAFT_FIELDS, array_map(fn ($f) => $product->{$f}, self::DRAFT_FIELDS));

        foreach ($sent as $field) {
            // Переносы строк из браузера (\r\n) — как в базе (\n)
            $values[$field] = isset($data[$field]) ? str_replace(["\r\n", "\r"], "\n", $data[$field]) : null;
        }

        if ($attributesSent) {
            $values['attributes'] = $data['attributes'];
        }

        // Черновик — только если сайтовые поля реально отличаются от опубликованных;
        // совпал с опубликованным (правки вернули обратно) — удаляется
        if ($this->drafts->differs($product, $values)) {
            ProductDraft::updateOrCreate(['product_id' => $product->id], ['data' => $values]);
        } elseif ($draft) {
            $draft->delete();
            $product->unsetRelation('draft');
        }
    }

    /**
     * Строки блока «Характеристики»: после ошибки — введённое, иначе — из значений.
     */
    private function attributeRows($values): array
    {
        if (is_array(old('attributes'))) {
            $dictionary = ProductAttribute::all()->keyBy('name_key');

            return collect(old('attributes'))
                ->map(function ($row) use ($dictionary) {
                    $attribute = $dictionary[ProductAttribute::key($row['name'] ?? '')] ?? null;
                    $value = (string) ($row['value'] ?? '');

                    return [
                        'name' => (string) ($row['name'] ?? ''),
                        'value' => $value,
                        'unit' => $attribute?->unit,
                        'warning' => $this->notNumberWarning($attribute, $value),
                    ];
                })
                ->filter(fn ($row) => $row['name'] !== '' || $row['value'] !== '')
                ->values()->all();
        }

        return collect($values)->map(fn ($v) => [
            'name' => $v->attribute->name,
            'value' => $v->value,
            'unit' => $v->attribute->unit,
            'warning' => $this->notNumberWarning($v->attribute, $v->value),
        ])->values()->all();
    }

    /**
     * Нечисловое значение числовой характеристики (из импорта) сохраняется как есть —
     * предупреждение у поля; изменить его можно только на число.
     */
    private function notNumberWarning(?ProductAttribute $attribute, string $value): ?string
    {
        return $attribute?->isNumber() && $value !== '' && ProductAttribute::parseNumber($value) === null
            ? 'Числовая характеристика, значение не число — сохраняется как есть; новое значение — только число'
            : null;
    }

    private function publishProduct(Product $product): void
    {
        // Не из кэша связи: черновик мог появиться только что, при сохранении формы
        $draft = $product->draft()->first();

        if ($draft) {
            $data = $draft->data;

            $description = $data['description'] ?? $product->description;

            $product->update([
                'display_name' => $data['display_name'] ?? null,
                'description' => $description,
                // Описание переписали вручную — оно уже не сгенерированное
                'description_generated' => $product->description_generated && trim((string) $description) === trim((string) $product->description),
                'specifications' => $data['specifications'] ?? $product->specifications,
                'package_contents' => $data['package_contents'] ?? $product->package_contents,
                'is_published' => true,
            ]);

            if (isset($data['attributes'])) {
                $this->attributes->sync($product, $data['attributes']);
            }

            $draft->delete();
        } else {
            $product->update(['is_published' => true]);
        }
    }

    /**
     * Фото из очереди, публикация и переход — по нажатой кнопке. Ошибки фото не отменяют
     * сохранение: товар сохранён, ошибки — в блоке фото.
     */
    private function afterSave(Product $product, string $saved)
    {
        [$uploaded, $imageErrors] = $this->uploadImages($product, array_values((array) request()->file('images', [])));

        $action = $this->action();

        if ($action === 'publish') {
            $this->publishProduct($product);
            $saved = 'Товар опубликован';
        }

        $message = $saved.($uploaded ? ", загружено фото: {$uploaded}" : '');

        if ($imageErrors) {
            return redirect("/admin/product/{$product->id}#images")
                ->with('success', $message)
                ->withErrors(['images' => $imageErrors]);
        }

        if ($action === 'preview') {
            return redirect("/admin/product/{$product->id}/preview")->with('success', $message);
        }

        return redirect("/admin/product/{$product->id}")->with('success', $message);
    }
}
