<?php

namespace App\Services\Exchange;

use App\Enums\ExchangeJobType;
use App\Exceptions\ExchangeJobFailedException;
use App\Models\PriceType;
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

    /**
     * Шаблон .xlsx: колонки потока 2 — цена по каждому типу из справочника (price_<код>, с НДС, если
     * включено «Цены из 1С с НДС») — и примеры строк (время — по Минску). Ненужную колонку цены можно
     * удалить: тип, которого нет в файле, не меняется.
     */
    public function template(): string
    {
        $codes = PriceType::query()->ordered()->pluck('code')->all() ?: [PriceType::LEGACY_CODE];
        $now = now()->setTimezone('Europe/Minsk')->format('Y-m-d\TH:i:sP');
        // Пример: оптовая 998.71, остальные типы — дороже на 5 % за каждый
        $example = array_map(fn ($i) => number_format(998.71 * (1 + 0.05 * $i), 2, '.', ''), array_keys($codes));

        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['guid', 'sku', ...array_map(fn ($c) => "price_{$c}", $codes), 'stock', 'stock_status', 'updated_at']));
        $writer->addRow(Row::fromValues(['5f1c2b8a-3d4e-11ef-9a1b-0050569a1c01', 'LEDEME-L4755-2', ...$example, '7', 'in_stock', $now]));
        // Пустая цена — у товара нет цены этого типа (у типа по умолчанию — «Цена уточняется»)
        $writer->addRow(Row::fromValues(['', 'TIM-BL5800', ...array_fill(0, count($codes), ''), '0', 'on_order', $now]));
        $writer->close();

        return $path;
    }
}
