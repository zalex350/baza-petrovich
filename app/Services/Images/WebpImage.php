<?php

namespace App\Services\Images;

use App\Exceptions\ImageUploadException;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Exceptions\RuntimeException as ImageRuntimeException;
use Intervention\Image\ImageManager;

/**
 * Одна webp-картинка на диске фото (баннеры, категории): длинная сторона не больше $max,
 * ориентация по EXIF, EXIF не сохраняется, маленькие не растягиваются. Те же проверки,
 * что у фото товаров (ProductImageService): битый файл, слишком большое разрешение.
 */
class WebpImage
{
    /**
     * @return string путь на диске, например banners/abc…-desktop.webp
     *
     * @throws ImageUploadException
     */
    public function store(UploadedFile $file, string $directory, int $max, string $suffix = ''): string
    {
        $name = $file->getClientOriginalName();
        $info = @getimagesize($file->getRealPath());

        if (! $info) {
            throw ImageUploadException::broken($name);
        }

        if (ProductImageService::MAX_MEGAPIXELS * 1_000_000 < $info[0] * $info[1]) {
            throw ImageUploadException::tooLarge($name, ProductImageService::MAX_MEGAPIXELS);
        }

        try {
            $image = ImageManager::gd(autoOrientation: true, decodeAnimation: false, strip: true)
                ->read($file->getRealPath())
                ->scaleDown(width: $max, height: $max);

            $path = $directory.'/'.Str::lower(Str::random(32)).($suffix !== '' ? "-{$suffix}" : '').'.webp';
            Storage::disk(ProductImage::disk())->put($path, (string) $image->toWebp(quality: (int) config('shop.images.quality')));
        } catch (ImageRuntimeException) {
            throw ImageUploadException::broken($name);
        }

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path) {
            Storage::disk(ProductImage::disk())->delete($path);
        }
    }
}
