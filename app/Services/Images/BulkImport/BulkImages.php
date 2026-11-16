<?php

namespace App\Services\Images\BulkImport;

use App\Exceptions\ImageUploadException;
use App\Jobs\ProcessImageImport;
use App\Models\ImageImport;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\CatalogMenu;
use App\Services\Images\PlaceholderImages;
use App\Services\Images\ProductImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Массовая загрузка фото товаров (админка «Товары → Загрузить фото», artisan images:import).
 *
 * 1. Черновик: ZIP или CSV «код;ссылка» загружен (диск local, DIR) или указана папка / файл на сервере.
 * 2. План (предпросмотр, база не меняется): имя файла → товар (ProductLookup: SKU, артикул,
 *    БРЕНД_АРТИКУЛ; суффиксы _1, _2, -2 — порядок в галерее), не найдено, неоднозначно, пропущено.
 * 3. «Применить» (режим «добавить» / «заменить») — в очередь (ProcessImageImport): порциями по
 *    BATCH_SECONDS, прогресс и итог — в записи журнала ImageImport. Каждое фото — через
 *    ProductImageService (webp thumb / medium / large ≤ 1600 px, исходник не хранится); тот же файл
 *    (content_hash), что уже есть у товара, не добавляется; битый файл — в ошибки, загрузка идёт дальше.
 *    «Заменить»: прежние фото товара удаляются после того, как добавилось хотя бы одно новое.
 */
class BulkImages
{
    public const DIR = 'image-imports';

    public const DISK = 'local';

    /** ZIP — до 500 МБ, CSV — до 5 МБ (КБ, правило max) */
    public const ZIP_MAX_KB = 512000;

    public const CSV_MAX_KB = 5120;

    /** Строк в таблицах предпросмотра */
    public const PREVIEW_LIMIT = 200;

    /** Длительность одной порции в очереди: меньше retry_after очереди */
    public const BATCH_SECONDS = 40;

    public function __construct(private readonly ProductImageService $images) {}

    /** ZIP или CSV из админки → черновик с планом */
    public function draftFromUpload(UploadedFile $file, string $source, ?User $user): ImageImport
    {
        $this->pruneDrafts();

        $extension = $source === ImageImport::SOURCE_CSV ? 'csv' : 'zip';
        $path = Storage::disk(self::DISK)->putFileAs(self::DIR, $file, Str::uuid().'.'.$extension);

        if ($path === false) {
            throw new RuntimeException('Не удалось сохранить файл');
        }

        return $this->plan(ImageImport::create([
            'user_id' => $user?->id,
            'source' => $source,
            'name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'path' => $path,
        ]));
    }

    /** Папка, ZIP или CSV на сервере (artisan) → черновик с планом */
    public function draftFromPath(string $path, ?User $user = null): ImageImport
    {
        $source = match (true) {
            is_dir($path) => ImageImport::SOURCE_DIR,
            strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv' => ImageImport::SOURCE_CSV,
            default => ImageImport::SOURCE_ZIP,
        };

        return $this->plan(ImageImport::create([
            'user_id' => $user?->id,
            'source' => $source,
            'name' => Str::limit($path, 250, ''),
            'path' => realpath($path) ?: $path,
        ]));
    }

    /** Предпросмотр: сопоставление файлов товарам. Источник не открылся — failed с причиной */
    public function plan(ImageImport $import): ImageImport
    {
        try {
            $source = $this->source($import);
        } catch (RuntimeException $e) {
            $import->update(['status' => ImageImport::FAILED, 'message' => $e->getMessage(), 'finished_at' => now()]);
            $this->cleanup($import);

            return $import;
        }

        $lookup = new ProductLookup;
        $items = $notFound = $ambiguous = $skipped = [];
        $row = 0;

        foreach ($source->entries() as $entry) {
            $row++;

            if (isset($entry['skip'])) {
                $skipped[] = ['name' => $entry['name'], 'reason' => $entry['skip']];

                continue;
            }

            $match = isset($entry['code'])
                ? $lookup->find($entry['code']) + ['order' => $row]
                : $lookup->forFileName($entry['stem']);

            if ($match['product_id'] !== null) {
                $items[] = ['entry' => $entry['entry'], 'name' => $entry['name'], 'product_id' => $match['product_id'], 'order' => $match['order']];
            } elseif ($match['ambiguous'] !== []) {
                $ambiguous[] = ['name' => $entry['name'], 'products' => $match['ambiguous']];
            } else {
                $notFound[] = ['name' => $entry['name']];
            }
        }
        $source->close();

        // По товарам; внутри — без номера, затем _1, _2…; при равенстве — по имени
        usort($items, fn ($a, $b) => [$a['product_id'], $a['order'], $a['name']] <=> [$b['product_id'], $b['order'], $b['name']]);

        $import->update([
            'plan' => ['items' => $items],
            'report' => ['not_found' => $notFound, 'ambiguous' => $ambiguous, 'skipped' => $skipped, 'errors' => []],
            'files_total' => count($items),
            'products_total' => count(array_unique(array_column($items, 'product_id'))),
        ]);

        return $import;
    }

    /** «Применить»: в очередь (или сразу — artisan) */
    public function apply(ImageImport $import, string $mode, bool $queue = true): void
    {
        if ($import->status !== ImageImport::DRAFT) {
            throw new RuntimeException('Загрузка уже применена или отменена');
        }

        $import->update([
            'mode' => $mode,
            'status' => ImageImport::QUEUED,
            'image_id_floor' => (int) ProductImage::query()->max('id'),
        ]);

        if ($queue) {
            ProcessImageImport::dispatch($import->id);
        }
    }

    /**
     * Обработать следующую порцию. true — загрузка закончена.
     *
     * @param  float|null  $seconds  длительность порции; null — до конца
     */
    public function runBatch(ImageImport $import, ?float $seconds = self::BATCH_SECONDS, ?callable $progress = null): bool
    {
        $import->refresh();

        if (! $import->isActive()) {
            return true;
        }
        if ($import->status === ImageImport::QUEUED) {
            $import->update(['status' => ImageImport::RUNNING, 'started_at' => now()]);
        }

        $items = $import->plan['items'] ?? [];
        $report = $import->report ?? [];
        $deadline = $seconds === null ? null : microtime(true) + $seconds;
        $source = $this->source($import);

        try {
            for ($i = $import->processed; $i < count($items); $i++) {
                $item = $items[$i];

                try {
                    $this->addOne($import, $source, $item);
                } catch (ImageUploadException|RuntimeException $e) {
                    $this->error($import, $report, $item['name'], $e->getMessage());
                } catch (Throwable $e) {
                    report($e);
                    $this->error($import, $report, $item['name'], 'Не удалось обработать файл');
                }

                // Последний файл товара — «заменить»: убрать прежние фото
                if ($import->mode === ImageImport::MODE_REPLACE && ($items[$i + 1]['product_id'] ?? null) !== $item['product_id']) {
                    $this->removeOld($import, $item['product_id']);
                }

                $import->processed = $i + 1;
                $import->report = $report;
                $import->save();

                if ($progress) {
                    $progress($import);
                }

                if ($deadline !== null && microtime(true) >= $deadline && $i + 1 < count($items)) {
                    return false;
                }
            }
        } finally {
            $source->close();
        }

        $import->update([
            'status' => ImageImport::DONE,
            'finished_at' => now(),
            'message' => "Добавлено фото: {$import->added}, дублей: {$import->duplicates}, ошибок: {$import->failed}"
                .($import->mode === ImageImport::MODE_REPLACE ? ", удалено прежних: {$import->removed}" : ''),
        ]);
        $this->cleanup($import);
        // Фото плиток разделов и списков могли измениться
        CatalogMenu::flush();

        return true;
    }

    /** Сбой обработки (очередь): failed с причиной */
    public function fail(ImageImport $import, string $message): void
    {
        $import->update(['status' => ImageImport::FAILED, 'message' => Str::limit($message, 500), 'finished_at' => now()]);
        $this->cleanup($import);
    }

    /** Отменить черновик */
    public function discard(ImageImport $import): void
    {
        if ($import->status === ImageImport::DRAFT) {
            $import->update(['status' => ImageImport::EXPIRED, 'plan' => null, 'message' => 'Отменено']);
            $this->cleanup($import);
        }
    }

    /** Черновики старше суток: файл удаляется, запись — «не применён» */
    public function pruneDrafts(): int
    {
        $old = ImageImport::query()->where('status', ImageImport::DRAFT)->where('created_at', '<', now()->subDay())->get();

        foreach ($old as $import) {
            $import->update(['status' => ImageImport::EXPIRED, 'plan' => null]);
            $this->cleanup($import);
        }

        return $old->count();
    }

    public function source(ImageImport $import): Source
    {
        $path = $this->uploaded($import) ? Storage::disk(self::DISK)->path($import->path) : (string) $import->path;

        return match ($import->source) {
            ImageImport::SOURCE_DIR => new DirSource($path),
            ImageImport::SOURCE_CSV => new CsvSource($path),
            default => new ZipSource($path),
        };
    }

    private function addOne(ImageImport $import, Source $source, array $item): void
    {
        $product = Product::query()->find($item['product_id']);
        if (! $product) {
            throw new RuntimeException('Товар удалён');
        }

        $tmp = $source->fetch($item['entry']);

        try {
            $mime = (string) mime_content_type($tmp);
            if (! in_array($mime, config('shop.images.mimetypes'), true)) {
                throw new RuntimeException('Не картинка JPG, PNG или WebP');
            }

            $image = $this->images->upload($product, new UploadedFile($tmp, $this->fileName($item), $mime, null, true));
        } finally {
            @unlink($tmp);
        }

        if ($this->isDuplicate($import, $image)) {
            $this->images->delete($image);
            $import->duplicates++;
        } else {
            $import->added++;
        }
    }

    /**
     * Тот же файл уже есть у товара: «добавить» — среди всех фото, «заменить» — среди добавленных этой
     * загрузкой (прежние всё равно удалятся). Старым фото без хэша он считается здесь же.
     */
    private function isDuplicate(ImageImport $import, ProductImage $image): bool
    {
        $others = ProductImage::query()->where('product_id', $image->product_id)->whereKeyNot($image->id)
            ->when($import->mode === ImageImport::MODE_REPLACE, fn ($q) => $q->where('id', '>', (int) $import->image_id_floor))
            ->get();

        foreach ($others as $other) {
            if ($other->content_hash === null && ($hash = PlaceholderImages::hashOf($other))) {
                $other->forceFill(['content_hash' => $hash])->saveQuietly();
            }

            if ($other->content_hash !== null && $other->content_hash === $image->content_hash) {
                return true;
            }
        }

        return false;
    }

    private function removeOld(ImageImport $import, int $productId): void
    {
        $floor = (int) $import->image_id_floor;
        $images = ProductImage::query()->where('product_id', $productId)->get();

        if (! $images->contains(fn ($image) => $image->id > $floor)) {
            return;
        }

        foreach ($images->filter(fn ($image) => $image->id <= $floor) as $old) {
            $this->images->delete($old);
            $import->removed++;
        }
    }

    private function error(ImageImport $import, array &$report, string $name, string $message): void
    {
        $import->failed++;

        if (count($report['errors'] ?? []) < ImageImport::ERRORS_LIMIT) {
            $report['errors'][] = ['name' => $name, 'message' => $message];
        }
    }

    /** Имя файла для фото (и признака заглушки): из ZIP / папки — как есть, из ссылки — последняя часть пути */
    private function fileName(array $item): string
    {
        $name = str_contains($item['entry'], '://')
            ? basename((string) parse_url($item['entry'], PHP_URL_PATH))
            : basename(str_replace('\\', '/', $item['entry']));

        return $name !== '' ? $name : 'photo';
    }

    private function uploaded(ImageImport $import): bool
    {
        return $import->source !== ImageImport::SOURCE_DIR && str_starts_with((string) $import->path, self::DIR.'/');
    }

    /** Загруженный файл черновика больше не нужен; папки и файлы на сервере не трогаем */
    private function cleanup(ImageImport $import): void
    {
        if ($this->uploaded($import)) {
            Storage::disk(self::DISK)->delete($import->path);
        }
    }
}
