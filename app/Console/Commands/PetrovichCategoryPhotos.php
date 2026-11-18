<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Services\CatalogMenu;
use App\Services\Images\WebpImage;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

/**
 * Фото разделов каталога (плитки главной и /catalog) из database/data/petrovich/category-photos:
 * файл {source_key раздела}.jpg → «Картинка» категории (categories.image, webp). Фото — CC0
 * (StockSnap, rawpixel), авторы и лицензии — credits.json, страница /photo-credits.
 * Заменяет только пустые картинки; --force — все из папки.
 */
class PetrovichCategoryPhotos extends Command
{
    protected $signature = 'petrovich:category-photos {dir? : папка с фото (по умолчанию database/data/petrovich/category-photos)} {--force : заменить уже заданные картинки}';

    protected $description = 'Фото разделов каталога металлобазы (свободные лицензии)';

    /** Длинная сторона, px (плитка ~300 px, с запасом на ретину) */
    public const MAX = 900;

    public function handle(WebpImage $images): int
    {
        $dir = $this->argument('dir') ?: database_path('data/petrovich/category-photos');
        $count = 0;

        foreach (glob($dir.'/*.jpg') ?: [] as $file) {
            $key = basename($file, '.jpg');
            $category = Category::where('source_key', $key)->first();

            if (! $category) {
                $this->warn("Раздел «{$key}» не найден — пропуск");

                continue;
            }

            if ($category->image && ! $this->option('force')) {
                continue;
            }

            $path = $images->store(new UploadedFile($file, basename($file), 'image/jpeg', null, true), 'categories', self::MAX);
            $old = $category->image;
            $category->update(['image' => $path]);

            if ($old) {
                $images->delete($old);
            }

            $count++;
        }

        app(CatalogMenu::class)->flush();
        $this->info("Фото разделов: {$count}");

        return self::SUCCESS;
    }
}
