<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ImageUploadException;
use App\Http\Controllers\Admin\Concerns\UploadsProductImages;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Images\ProductImageService;

/**
 * Блок «Изображения» на странице товара в админке (SRS §10.4.8). Изменения применяются
 * сразу, без черновика.
 */
class AdminProductImageController extends Controller
{
    use UploadsProductImages;

    public function __construct(private readonly ProductImageService $images)
    {
    }

    public function store($id)
    {
        $product = Product::findOrFail($id);

        $files = request()->validate(
            [
                'images'   => ['required', 'array', 'max:' . self::MAX_FILES],
                'images.*' => $this->fileRules(),
            ],
            $this->fileMessages('images.*') + [
                'images.required' => 'Выберите файлы для загрузки',
                'images.array'    => 'Выберите файлы для загрузки',
                'images.max'      => 'За один раз — не больше ' . self::MAX_FILES . ' файлов',
            ],
            $this->fileNames('images')
        )['images'];

        $uploaded = 0;
        $errors = [];

        // Повреждённый файл не мешает остальным: он попадает в ошибки, остальные загружаются
        foreach ($files as $file) {
            try {
                $this->images->upload($product, $file);
                $uploaded++;
            } catch (ImageUploadException $e) {
                $errors[] = $e->getMessage();
            }
        }

        $redirect = $this->backToImages($product);

        if ($uploaded) {
            $redirect->with('success', "Загружено фото: {$uploaded}");
        }

        return $errors ? $redirect->withErrors(['images' => $errors]) : $redirect;
    }

    /**
     * Замена файла: позиция и признак основного сохраняются.
     */
    public function replace($id, $imageId)
    {
        $image = $this->image($id, $imageId);

        $file = request()->validate(
            ['image' => ['required', ...$this->fileRules()]],
            $this->fileMessages('image') + ['image.required' => 'Выберите файл для замены'],
            $this->fileNames('image')
        )['image'];

        try {
            $this->images->replace($image, $file);
        } catch (ImageUploadException $e) {
            return $this->backToImages($image->product)->withErrors(['images' => $e->getMessage()]);
        }

        return $this->backToImages($image->product)->with('success', 'Фото заменено');
    }

    public function destroy($id, $imageId)
    {
        $image = $this->image($id, $imageId);

        $this->images->delete($image);

        return $this->backToImages($image->product)->with('success', 'Фото удалено');
    }

    public function makeMain($id, $imageId)
    {
        $image = $this->image($id, $imageId);

        $this->images->makeMain($image);

        return $this->backToImages($image->product)->with('success', 'Основное фото изменено');
    }

    /**
     * Кнопки «вверх/вниз» — работают и без JS.
     */
    public function move($id, $imageId)
    {
        $image = $this->image($id, $imageId);

        $direction = request()->validate(['direction' => ['required', 'in:up,down']])['direction'];

        $this->images->move($image, $direction === 'up' ? -1 : 1);

        return $this->backToImages($image->product);
    }

    /**
     * Порядок после перетаскивания: ids — id фото в новом порядке. Отвечает JSON (fetch).
     */
    public function reorder($id)
    {
        $product = Product::findOrFail($id);

        $ids = request()->validate([
            'ids'   => ['required', 'array'],
            'ids.*' => ['integer'],
        ])['ids'];

        $this->images->reorder($product, $ids);

        if (request()->expectsJson()) {
            return response()->json(['ok' => true, 'message' => 'Порядок сохранён'], 200, [], JSON_UNESCAPED_UNICODE);
        }

        return $this->backToImages($product)->with('success', 'Порядок сохранён');
    }

    private function image($productId, $imageId): ProductImage
    {
        return ProductImage::where('product_id', Product::findOrFail($productId)->id)->findOrFail($imageId);
    }

    private function backToImages(Product $product)
    {
        return redirect("/admin/product/{$product->id}#images");
    }
}
