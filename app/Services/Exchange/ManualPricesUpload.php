<?php

namespace App\Services\Exchange;

use App\Enums\ExchangeJobType;
use App\Exceptions\ExchangeJobFailedException;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Ручная загрузка цен и остатков в админке («Обмен с 1С» → «Загрузить цены»): тот же формат,
 * что POST /prices-stock (раздел 5 спецификации), плюс .xlsx. Черновик, применение, очистка —
 * ManualUpload; предпросмотр — PricesStockHandler::plan.
 */
class ManualPricesUpload extends ManualUpload
{
    public function __construct(private readonly PricesStockHandler $handler, ExchangeJobs $jobs)
    {
        parent::__construct($jobs);
    }

    protected function type(): ExchangeJobType
    {
        return ExchangeJobType::PricesStock;
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
