<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Exceptions\ImageUploadException;
use App\Models\Product;
use App\Services\Images\ProductImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

/**
 * Проверка и загрузка фото товара: блок «Изображения» и единая форма товара.
 */
trait UploadsProductImages
{
    /** Файлов за одну загрузку */
    public const MAX_FILES = 20;

    /**
     * Загрузка из формы товара: файл с ошибкой не мешает ни остальным файлам,
     * ни сохранению полей товара. Возвращает [загружено, ошибки].
     *
     * @param  array<int, UploadedFile>  $files
     * @return array{0: int, 1: list<string>}
     */
    protected function uploadImages(Product $product, array $files): array
    {
        $uploaded = 0;
        $errors = [];

        if (count($files) > self::MAX_FILES) {
            return [0, ['За один раз — не больше ' . self::MAX_FILES . ' файлов']];
        }

        foreach ($files as $index => $file) {
            $validator = Validator::make(
                ['image' => $file],
                ['image' => $this->fileRules()],
                $this->fileMessages('image'),
                ['image' => $file instanceof UploadedFile ? "«{$file->getClientOriginalName()}»" : 'Файл ' . ($index + 1)]
            );

            if ($validator->fails()) {
                $errors[] = $validator->errors()->first('image');

                continue;
            }

            try {
                app(ProductImageService::class)->upload($product, $file);
                $uploaded++;
            } catch (ImageUploadException $e) {
                $errors[] = $e->getMessage();
            }
        }

        return [$uploaded, $errors];
    }

    /**
     * Тип проверяется по содержимому (MIME), а не по расширению.
     */
    protected function fileRules(): array
    {
        return [
            'file',
            'mimetypes:' . implode(',', config('shop.images.mimetypes')),
            'max:' . config('shop.images.max_kb'),
        ];
    }

    protected function fileMessages(string $field): array
    {
        $mb = (int) round(config('shop.images.max_kb') / 1024);

        return [
            "{$field}.uploaded"  => "Файл :attribute не загрузился — возможно, он больше {$mb} МБ",
            "{$field}.file"      => "Файл :attribute не загрузился",
            "{$field}.mimetypes" => 'Файл :attribute — недопустимый формат. Можно загружать JPG, PNG и WebP',
            "{$field}.max"       => "Файл :attribute больше {$mb} МБ",
        ];
    }

    /**
     * В сообщениях — имя файла пользователя, а не images.0.
     */
    protected function fileNames(string $field): array
    {
        $files = request()->file($field);

        if ($files instanceof UploadedFile) {
            return [$field => "«{$files->getClientOriginalName()}»"];
        }

        $names = [];

        foreach ((array) $files as $index => $file) {
            if ($file instanceof UploadedFile) {
                $names["{$field}.{$index}"] = "«{$file->getClientOriginalName()}»";
            }
        }

        return $names;
    }
}
