<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ProductDocumentType;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Документы товара в админке (SRS §4.8): загрузка PDF (до 20 МБ) с типом и названием,
 * изменение названия и типа, порядок (перетаскивание — JSON), удаление. Применяется сразу,
 * без черновика — как фото.
 */
class AdminProductDocumentController extends Controller
{
    public function store($id)
    {
        $product = Product::findOrFail($id);

        $data = request()->validate([
            'document' => ['required', 'file', 'mimetypes:application/pdf', 'max:'.ProductDocument::MAX_KB],
            'type' => ['required', Rule::enum(ProductDocumentType::class)],
            'title' => ['nullable', 'string', 'max:255'],
        ], $this->messages());

        $file = request()->file('document');

        // Название не задано — из имени файла без расширения
        $title = trim((string) ($data['title'] ?? '')) ?: Str::of($file->getClientOriginalName())->beforeLast('.')->replace(['_', '-'], ' ')->squish()->limit(255, '')->value();

        $path = $file->storeAs(
            ProductDocument::DIRECTORY.'/'.$product->id,
            Str::lower(Str::random(32)).'.pdf',
            ProductImage::disk(),
        );

        ProductDocument::create([
            'product_id' => $product->id,
            'title' => $title ?: ProductDocumentType::from($data['type'])->label(),
            'type' => $data['type'],
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'size' => (int) $file->getSize(),
            'sort_order' => (int) ProductDocument::where('product_id', $product->id)->max('sort_order') + 1,
        ]);

        return $this->back($product)->with('success', 'Документ загружен');
    }

    public function update($id, $document)
    {
        $document = $this->document($id, $document);

        $data = request()->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(ProductDocumentType::class)],
        ], $this->messages());

        $document->update(['title' => trim($data['title']), 'type' => $data['type']]);

        return $this->back($document->product)->with('success', 'Документ сохранён');
    }

    /**
     * Порядок перетаскиванием: ids — документы этого товара в новом порядке; чужие id не учитываются.
     */
    public function reorder($id)
    {
        $product = Product::findOrFail($id);

        $ids = request()->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ])['ids'];

        $own = ProductDocument::where('product_id', $product->id)->pluck('id')->all();
        $ordered = array_values(array_unique(array_intersect(array_map('intval', $ids), $own)));
        // Не перечисленные — в конец, в прежнем порядке
        $ordered = [...$ordered, ...array_diff(
            ProductDocument::where('product_id', $product->id)->orderBy('sort_order')->orderBy('id')->pluck('id')->all(),
            $ordered,
        )];

        DB::transaction(function () use ($ordered) {
            foreach ($ordered as $position => $documentId) {
                ProductDocument::whereKey($documentId)->update(['sort_order' => $position + 1]);
            }
        });

        if (request()->expectsJson()) {
            return response()->json(['ok' => true, 'message' => 'Порядок сохранён'], 200, [], JSON_UNESCAPED_UNICODE);
        }

        return $this->back($product)->with('success', 'Порядок сохранён');
    }

    public function destroy($id, $document)
    {
        $document = $this->document($id, $document);
        $product = $document->product;

        $document->deleteWithFile();

        return $this->back($product)->with('success', 'Документ удалён');
    }

    private function document($productId, $documentId): ProductDocument
    {
        return ProductDocument::where('product_id', Product::findOrFail($productId)->id)->findOrFail($documentId);
    }

    private function back(Product $product)
    {
        return redirect("/admin/product/{$product->id}#documents");
    }

    private function messages(): array
    {
        $mb = intdiv(ProductDocument::MAX_KB, 1024);

        return [
            'document.required' => 'Выберите PDF-файл',
            'document.uploaded' => "Файл не загрузился — возможно, он больше {$mb} МБ",
            'document.file' => 'Файл не загрузился',
            'document.mimetypes' => 'Документ — только PDF',
            'document.max' => "Файл больше {$mb} МБ",
            'type.required' => 'Выберите тип документа',
            'type.enum' => 'Неизвестный тип документа',
            'title.required' => 'Укажите название',
            'title.max' => 'Название — не длиннее 255 символов',
        ];
    }
}
