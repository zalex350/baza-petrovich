<?php

namespace App\Services\Exchange;

use App\Enums\ExchangeJobType;
use App\Exceptions\ExchangeJobFailedException;
use App\Models\ExchangeJob;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Ручная загрузка цен и остатков в админке («Обмен с 1С» → «Загрузить цены»): тот же формат,
 * что POST /prices-stock (раздел 5 спецификации), плюс .xlsx. Файл → черновик
 * (диск exchange, uploads/<токен>.csv|xlsx; CSV приводится к UTF-8 без BOM, cp1251 из Excel
 * перекодируется) → предпросмотр (PricesStockHandler::plan, база не меняется) → «Применить»:
 * задание в общем журнале (source = manual, автор), обработка сразу. Черновики старше суток
 * удаляет exchange:prune.
 */
class ManualPricesUpload
{
    public const DIR = 'uploads';

    /** Строк в таблицах предпросмотра */
    public const PREVIEW_LIMIT = 200;

    public function __construct(
        private readonly PricesStockHandler $handler,
        private readonly ExchangeJobs $jobs,
    ) {}

    /** Сохранить файл черновиком; токен — для предпросмотра и применения */
    public function store(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
        $token = (string) Str::uuid();
        $path = self::DIR."/{$token}.{$extension}";

        if ($extension === 'csv') {
            $content = (string) file_get_contents($file->getRealPath());
            $content = str_starts_with($content, "\u{FEFF}") ? substr($content, 3) : $content;

            if (! mb_check_encoding($content, 'UTF-8')) {
                $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
            }

            Storage::disk('exchange')->put($path, $content);
        } else {
            Storage::disk('exchange')->putFileAs(self::DIR, $file, "{$token}.xlsx");
        }

        return $token;
    }

    public function path(string $token): ?string
    {
        foreach (['csv', 'xlsx'] as $extension) {
            $path = self::DIR."/{$token}.{$extension}";

            if (preg_match('/^[0-9a-f-]{36}$/', $token) && Storage::disk('exchange')->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Предпросмотр: план или текст отказа (нет колонок, предохранитель mode=full).
     *
     * @return array{plan: ?PricesStockPlan, failed: ?string, products: Collection<int, Product>}
     */
    public function preview(string $path, string $mode): array
    {
        try {
            $plan = $this->handler->plan(Storage::disk('exchange')->path($path), $mode);
        } catch (ExchangeJobFailedException $e) {
            return ['plan' => null, 'failed' => $e->getMessage(), 'products' => collect()];
        }

        $ids = [...array_slice(array_keys($plan->updated), 0, self::PREVIEW_LIMIT), ...array_slice($plan->zeroed, 0, self::PREVIEW_LIMIT)];
        $products = Product::withTrashed()->whereIn('id', $ids)->get(['id', 'sku', 'name', 'display_name', 'brand'])->keyBy('id');

        return ['plan' => $plan, 'failed' => null, 'products' => $products];
    }

    /** Применить: задание в журнале (вручную, автор), обработка сразу; черновик уходит в архив */
    public function apply(string $path, string $mode, User $user, ?string $ip): ExchangeJob
    {
        $disk = Storage::disk('exchange');
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return $this->jobs->accept([
            'path' => $disk->path($path),
            'size' => $disk->size($path),
            'content_type' => $extension === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv',
            'gzip' => false,
            'extension' => $extension,
        ], ExchangeJobType::PricesStock, $mode, null, null, $ip, $user);
    }

    public function discard(string $path): void
    {
        Storage::disk('exchange')->delete($path);
    }

    /** Черновики старше суток (не применённые и не отменённые) */
    public function pruneDrafts(): int
    {
        $disk = Storage::disk('exchange');
        $count = 0;

        foreach ($disk->files(self::DIR) as $file) {
            if ($disk->lastModified($file) < now()->subDay()->getTimestamp()) {
                $disk->delete($file);
                $count++;
            }
        }

        return $count;
    }

    /** Шаблон .xlsx: колонки потока 2 и пример строки (время — по Минску) */
    public function template(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['guid', 'sku', 'price', 'stock', 'stock_status', 'updated_at']));
        $writer->addRow(Row::fromValues(['5f1c2b8a-3d4e-11ef-9a1b-0050569a1c01', 'LEDEME-L4755-2', '832.26', '7', 'in_stock', now()->setTimezone('Europe/Minsk')->format('Y-m-d\TH:i:sP')]));
        $writer->addRow(Row::fromValues(['', 'TIM-BL5800', '', '0', 'on_order', now()->setTimezone('Europe/Minsk')->format('Y-m-d\TH:i:sP')]));
        $writer->close();

        return $path;
    }
}
