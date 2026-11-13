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
 * Ручная загрузка номенклатуры в админке («Обмен с 1С» → «Загрузить номенклатуру»): тот же
 * формат, что POST /catalog (раздел 4 спецификации), плюс .xlsx. Главный сценарий — первичная
 * привязка GUID по выгрузке из 1С: предпросмотр (CatalogHandler::plan как пробный прогон —
 * с товарами сайта без пары) показывает, что будет привязано по SKU и по «бренд + артикул»,
 * что создастся и что изменится; база меняется только после «Применить».
 */
class ManualCatalogUpload extends ManualUpload
{
    public function __construct(private readonly CatalogHandler $handler, ExchangeJobs $jobs)
    {
        parent::__construct($jobs);
    }

    protected function type(): ExchangeJobType
    {
        return ExchangeJobType::Catalog;
    }

    /**
     * Предпросмотр: план или текст отказа (нет обязательных колонок). Предохранитель mode=full
     * в предпросмотре не роняет план — отмечается в $plan->guard, «Применить» недоступно.
     *
     * @return array{plan: ?CatalogPlan, failed: ?string, products: Collection<int, Product>}
     */
    public function preview(string $path, string $mode): array
    {
        try {
            $plan = $this->handler->plan(Storage::disk('exchange')->path($path), $mode, dryRun: true);
        } catch (ExchangeJobFailedException $e) {
            return ['plan' => null, 'failed' => $e->getMessage(), 'products' => collect()];
        }

        // Таблицы предпросмотра: привязка (не по GUID) и изменения (по GUID) — по PREVIEW_LIMIT строк
        $ids = [];
        foreach ([true, false] as $binding) {
            $rows = array_filter($plan->matches, fn ($m) => ($m['how'] !== 'guid') === $binding);
            $ids = [...$ids, ...array_column(array_slice($rows, 0, self::PREVIEW_LIMIT), 'id')];
        }
        $products = Product::withTrashed()->whereIn('id', $ids)->get(['id', 'sku', 'name', 'display_name', 'brand'])->keyBy('id');

        return ['plan' => $plan, 'failed' => null, 'products' => $products];
    }

    /** Шаблон .xlsx: колонки потока 1 и примеры строк (раздел 4) */
    public function template(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['guid', 'sku', 'article', 'brand', 'name', 'unit', 'vat_rate', 'barcode', 'group_1c', 'active']));
        $writer->addRow(Row::fromValues(['5f1c2b8a-3d4e-11ef-9a1b-0050569a1c01', 'LEDEME-L4755-2', 'L4755-2', 'LEDEME', 'Смеситель для кухни LEDEME L4755-2', 'шт', '20', '4810000000017', 'Смесители', '1']));
        $writer->addRow(Row::fromValues(['8c4f5e1d-3d4e-11ef-9a1b-0050569a1c04', '', 'BL 5810', 'TIM', 'Смеситель для ванны TIM BL5810', 'шт', '20', '', 'Смесители', '1']));
        $writer->close();

        return $path;
    }
}
