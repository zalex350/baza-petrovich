<?php

namespace App\Services\Images;

use App\Exceptions\ImageUploadException;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Exceptions\RuntimeException as ImageRuntimeException;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Фото товара (SRS §4.7, §10.4.8, BR-051…BR-054): загрузка с генерацией размеров,
 * замена, удаление, основное фото и порядок.
 *
 * Из файла делаются webp-версии thumb / medium / large: длинная сторона не больше
 * заданной, пропорции сохраняются, маленькие изображения не растягиваются. Ориентация
 * берётся из EXIF (фото с телефона не повёрнуты), сами EXIF-данные в webp не попадают.
 * Оригинал не хранится.
 *
 * Заглушки («нет фото» по имени файла или такой же файл, как у помеченной заглушки) помечаются
 * is_placeholder — на сайте не показываются (PlaceholderImages).
 *
 * Инвариант: если у товара есть фото, ровно одно из них основное. Все изменения —
 * в транзакции с блокировкой строки товара.
 */
class ProductImageService
{
    /** Защита от «бомб»: маленький файл с огромным разрешением съест всю память при декодировании */
    public const MAX_MEGAPIXELS = 50;

    public function upload(Product $product, UploadedFile $file): ProductImage
    {
        $stored = $this->process($product->id, $file);

        try {
            return DB::transaction(function () use ($product, $file, $stored) {
                $this->lockProduct($product->id);

                $hasImages = ProductImage::where('product_id', $product->id)->exists();

                return ProductImage::create([
                    'product_id'    => $product->id,
                    'path'          => $stored['path'],
                    'original_name' => $this->originalName($file),
                    'content_hash'  => $stored['hash'],
                    'is_placeholder' => PlaceholderImages::isKnown($this->originalName($file), $stored['hash']),
                    // Первое фото товара становится основным само (BR-051)
                    'is_main'       => ! $hasImages,
                    'sort_order'    => (int) ProductImage::where('product_id', $product->id)->max('sort_order') + 1,
                    'width'         => $stored['width'],
                    'height'        => $stored['height'],
                ]);
            });
        } catch (Throwable $e) {
            $this->deleteFiles($stored['paths']);

            throw $e;
        }
    }

    /**
     * Новый файл на месте старого: позиция и признак основного сохраняются.
     */
    public function replace(ProductImage $image, UploadedFile $file): ProductImage
    {
        $stored = $this->process($image->product_id, $file);
        $oldPaths = $image->paths();

        try {
            DB::transaction(function () use ($image, $file, $stored) {
                $this->lockProduct($image->product_id);

                $image->update([
                    'path'          => $stored['path'],
                    'original_name' => $this->originalName($file),
                    'content_hash'  => $stored['hash'],
                    'is_placeholder' => PlaceholderImages::isKnown($this->originalName($file), $stored['hash']),
                    'width'         => $stored['width'],
                    'height'        => $stored['height'],
                ]);
            });
        } catch (Throwable $e) {
            $this->deleteFiles($stored['paths']);

            throw $e;
        }

        $this->deleteFiles($oldPaths);

        return $image;
    }

    /**
     * Удаляет фото и все его файлы; если оно было основным — основным становится первое по порядку.
     */
    public function delete(ProductImage $image): void
    {
        $paths = $image->paths();

        DB::transaction(function () use ($image) {
            $this->lockProduct($image->product_id);

            $image->delete();

            $this->ensureMain($image->product_id);
        });

        $this->deleteFiles($paths);
    }

    public function makeMain(ProductImage $image): void
    {
        DB::transaction(function () use ($image) {
            $this->lockProduct($image->product_id);

            ProductImage::where('product_id', $image->product_id)
                ->where('id', '!=', $image->id)
                ->where('is_main', true)
                ->update(['is_main' => false]);

            $image->forceFill(['is_main' => true])->save();
        });
    }

    /**
     * Новый порядок — список id фото товара (перетаскивание). Фото, которых нет в списке,
     * идут следом в прежнем порядке; чужие id игнорируются.
     *
     * @param  list<int|string>  $ids
     */
    public function reorder(Product $product, array $ids): void
    {
        DB::transaction(function () use ($product, $ids) {
            $this->lockProduct($product->id);

            $current = ProductImage::where('product_id', $product->id)
                ->orderBy('sort_order')->orderBy('id')
                ->pluck('id');

            $wanted = collect($ids)->map(fn ($id) => (int) $id)->unique()->intersect($current);

            $this->saveOrder($wanted->concat($current->diff($wanted))->values()->all());
        });
    }

    /**
     * Сдвиг на одну позицию: $step = -1 — вверх, 1 — вниз (кнопки без JS).
     */
    public function move(ProductImage $image, int $step): void
    {
        DB::transaction(function () use ($image, $step) {
            $this->lockProduct($image->product_id);

            $ids = ProductImage::where('product_id', $image->product_id)
                ->orderBy('sort_order')->orderBy('id')
                ->pluck('id')->all();

            $from = array_search($image->id, $ids, true);
            $to = $from + $step;

            if ($from === false || $to < 0 || $to >= count($ids)) {
                return;
            }

            [$ids[$from], $ids[$to]] = [$ids[$to], $ids[$from]];

            $this->saveOrder($ids);
        });
    }

    /**
     * Если у товара есть фото, но нет основного — основным становится первое по порядку.
     */
    private function ensureMain(int $productId): void
    {
        if (ProductImage::where('product_id', $productId)->where('is_main', true)->exists()) {
            return;
        }

        ProductImage::where('product_id', $productId)
            ->orderBy('sort_order')->orderBy('id')
            ->first()
            ?->forceFill(['is_main' => true])->save();
    }

    /**
     * @param  list<int>  $ids
     */
    private function saveOrder(array $ids): void
    {
        foreach ($ids as $position => $id) {
            ProductImage::whereKey($id)->update(['sort_order' => $position + 1]);
        }
    }

    /**
     * Декодирует файл, пишет на диск три webp-версии.
     *
     * @return array{path: string, paths: list<string>, width: int, height: int, hash: string}
     *
     * @throws ImageUploadException
     */
    private function process(int $productId, UploadedFile $file): array
    {
        $name = $this->originalName($file);
        $info = @getimagesize($file->getRealPath());

        if (! $info) {
            throw ImageUploadException::broken($name);
        }

        if ($info[0] * $info[1] > self::MAX_MEGAPIXELS * 1_000_000) {
            throw ImageUploadException::tooLarge($name, self::MAX_MEGAPIXELS);
        }

        try {
            $image = ImageManager::gd(autoOrientation: true, decodeAnimation: false, strip: true)
                ->read($file->getRealPath());
        } catch (ImageRuntimeException) {
            throw ImageUploadException::broken($name);
        }

        $disk = Storage::disk(ProductImage::disk());
        $base = config('shop.images.directory') . "/{$productId}/" . Str::lower(Str::random(32));
        $quality = (int) config('shop.images.quality');
        $sizes = config('shop.images.sizes');
        $written = [];
        $large = null;

        try {
            // От большего к меньшему, в одном изображении: в памяти только одна копия
            arsort($sizes);

            foreach ($sizes as $size => $max) {
                $image->scaleDown(width: $max, height: $max);

                $path = "{$base}-{$size}.webp";
                $content = (string) $image->toWebp(quality: $quality);
                $disk->put($path, $content);
                $written[] = $path;

                if ($size === 'large') {
                    // hash — sha1 большой версии: одинаковые файлы (заглушки) у разных товаров
                    $large = ['width' => $image->width(), 'height' => $image->height(), 'hash' => sha1($content)];
                }
            }
        } catch (Throwable $e) {
            $this->deleteFiles($written);

            throw $e instanceof ImageRuntimeException ? ImageUploadException::broken($name) : $e;
        }

        return ['path' => "{$base}-large.webp", 'paths' => $written] + $large;
    }

    /**
     * @param  list<string>  $paths
     */
    private function deleteFiles(array $paths): void
    {
        if ($paths) {
            Storage::disk(ProductImage::disk())->delete($paths);
        }
    }

    private function lockProduct(int $productId): void
    {
        Product::withTrashed()->whereKey($productId)->lockForUpdate()->first();
    }

    private function originalName(UploadedFile $file): string
    {
        return Str::limit($file->getClientOriginalName(), 250, '');
    }
}
