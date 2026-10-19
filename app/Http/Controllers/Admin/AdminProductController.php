<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\UploadsProductImages;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Services\Vat\VatCalculator;
use Illuminate\Validation\Rule;

/**
 * Товары в админке. Создание и редактирование — одна форма (admin/product_form):
 * «Данные учётной системы (1С)» сохраняются сразу, тексты «Данных сайта» — в черновик,
 * на сайт после публикации. Кнопки формы — action: draft | preview | publish.
 */
class AdminProductController extends Controller
{
    use UploadsProductImages;

    /** Поля учётной системы: при shop.catalog.manual_products = false приходят только из 1С */
    private const ERP_FIELDS = ['name', 'sku', 'brand', 'category_id', 'price', 'vat_rate', 'stock'];

    /** Тексты сайта: через черновик */
    private const DRAFT_FIELDS = ['display_name', 'description', 'specifications', 'package_contents'];

    public function index()
    {
        $products = Product::with('draft')->latest()->paginate(50);

        return view(
            'admin.products',
            compact('products')
        );
    }

    public function create()
    {
        $this->ensureManualProducts();

        return view('admin.product_form', [
            'product'    => new Product(['vat_rate' => 20.00, 'price' => 0, 'stock' => 0]),
            'categories' => Category::all(),
            'draft'      => null,
        ]);
    }

    public function store()
    {
        $this->ensureManualProducts();

        $data = request()->validate($this->erpRules() + $this->siteRules() + [
            'is_active' => 'boolean',
        ]);

        $data = $this->normalizeVat($data);

        $data['is_active']    = request()->boolean('is_active');
        $data['is_published'] = $this->action() === 'publish';

        // Новый товар на сайте не виден до публикации — тексты пишутся сразу в товар
        $product = Product::create($data);

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

    public function destroy($id)
    {
        Product::findOrFail($id)->delete();

        return redirect('/admin/products')->with('success', 'Товар удалён');
    }

    public function show($id)
    {
        $product = Product::with('images')->findOrFail($id);

        return view('admin.product_form', [
            'product'    => $product,
            'categories' => Category::all(),
            'draft'      => $product->draft,
        ]);
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

        $direct = $manual ? $this->normalizeVat(array_intersect_key($data, array_flip(self::ERP_FIELDS))) : [];

        if (request()->has('slug')) {
            $direct['slug'] = $data['slug'] ?? null;
        }

        $product->update($direct);

        $this->saveDraftFields($product, $data);

        return $this->afterSave($product, 'Товар сохранён');
    }

    /**
     * Сохранить только тексты в черновик (прежняя отдельная форма; оставлена для совместимости).
     */
    public function saveDraft($id)
    {
        $product = Product::findOrFail($id);

        ProductDraft::updateOrCreate(
            ['product_id' => $product->id],
            ['data' => [
                'display_name'    => request('display_name'),
                'description'     => request('description'),
                'specifications'  => request('specifications'),
                'package_contents' => request('package_contents'),
            ]]
        );

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
        $draft   = $product->draft;

        if ($draft) {
            $data = $draft->data;

            $product->display_name    = $data['display_name'] ?? $product->display_name;
            $product->description     = $data['description'] ?? $product->description;
            $product->specifications  = $data['specifications'] ?? $product->specifications;
            $product->package_contents = $data['package_contents'] ?? $product->package_contents;
        }

        $preview = ['draft' => (bool) $draft];

        return view('product', compact('product', 'preview'));
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

    private function erpRules(?Product $product = null): array
    {
        return [
            'name'        => 'required|string|max:255',
            'sku'         => ['required', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($product?->id)],
            'brand'       => 'nullable|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'price'       => 'required|numeric|min:0',
            'vat_rate'    => ['sometimes', 'required', VatCalculator::rateRule()],
            'stock'       => 'required|integer|min:0',
        ];
    }

    private function siteRules(?Product $product = null): array
    {
        return [
            'display_name'     => 'nullable|string|max:255',
            'slug'             => ['nullable', 'string', 'max:255', Rule::unique('products', 'slug')->ignore($product?->id)],
            'description'      => 'nullable|string',
            'specifications'   => 'nullable|string',
            'package_contents' => 'nullable|string',
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
     * Тексты пришли и отличаются от опубликованных (или черновик уже есть) — в черновик.
     * Сохранение без правок текста черновик не создаёт.
     */
    private function saveDraftFields(Product $product, array $data): void
    {
        $sent = array_intersect(self::DRAFT_FIELDS, array_keys(request()->all()));

        if (! $sent) {
            return;
        }

        $draft = $product->draft;
        $values = $draft?->data ?? array_combine(self::DRAFT_FIELDS, array_map(fn ($f) => $product->{$f}, self::DRAFT_FIELDS));

        foreach ($sent as $field) {
            $values[$field] = $data[$field] ?? null;
        }

        $changed = collect(self::DRAFT_FIELDS)->contains(
            fn ($field) => (string) ($values[$field] ?? '') !== (string) ($product->{$field} ?? '')
        );

        if ($draft || $changed) {
            ProductDraft::updateOrCreate(['product_id' => $product->id], ['data' => $values]);
        }
    }

    private function publishProduct(Product $product): void
    {
        // Не из кэша связи: черновик мог появиться только что, при сохранении формы
        $draft = $product->draft()->first();

        if ($draft) {
            $data = $draft->data;

            $product->update([
                'display_name'    => $data['display_name'] ?? null,
                'description'     => $data['description'] ?? $product->description,
                'specifications'  => $data['specifications'] ?? $product->specifications,
                'package_contents' => $data['package_contents'] ?? $product->package_contents,
                'is_published'    => true,
            ]);

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

        $message = $saved . ($uploaded ? ", загружено фото: {$uploaded}" : '');

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
