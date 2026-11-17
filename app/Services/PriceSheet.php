<?php

namespace App\Services;

use App\Models\Product;
use App\Services\Vat\VatCalculator;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;

/**
 * Цены в Excel (админка «Цены»): выгрузка всех товаров и загрузка изменений обратно.
 *
 * Колонки: SKU | Название | Раздел | Ед. | Цена | Скидка, % | Скидка, BYN | Цена со скидкой | В наличии.
 * При загрузке товар находится по SKU; меняются цена (пусто — «по запросу»), скидки (пусто — нет)
 * и наличие (1 / 0, «да» / «нет»). Название, раздел, «Цена со скидкой» — только для справки.
 * Пока цены ведутся здесь; когда подключится обмен с 1С, цену и наличие будет присылать 1С.
 */
class PriceSheet
{
    public const HEADERS = ['SKU', 'Название', 'Раздел', 'Ед.', 'Цена', 'Скидка, %', 'Скидка, BYN', 'Цена со скидкой', 'В наличии'];

    /** @return string путь к временному .xlsx */
    public function export(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'prices');

        $options = new Options;
        $options->setColumnWidth(14, 1);
        $options->setColumnWidth(60, 2);
        $options->setColumnWidth(30, 3);
        $options->setColumnWidth(8, 4);
        $options->setColumnWidth(12, 5, 6, 7, 8, 9);

        $writer = new Writer($options);
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Цены');
        $writer->addRow(Row::fromValues(array_map(fn ($h) => $h === 'Цена' ? (VatCalculator::pricesIncludeVat() ? 'Цена с НДС' : 'Цена без НДС') : $h, self::HEADERS), (new Style)->setFontBold()));

        Product::query()
            ->with('category:id,name')
            ->orderBy('category_id')->orderBy('id')
            ->chunk(500, function ($products) use ($writer) {
                foreach ($products as $product) {
                    $raw = $product->getAttributes();
                    $writer->addRow(Row::fromValues([
                        $product->sku,
                        $product->title(),
                        $product->category?->name ?? '',
                        unit_label($product->unit),
                        $raw['price'] === null ? '' : (float) $raw['price'],
                        $product->discount_percent === null ? '' : (float) $product->discount_percent,
                        $product->discount_amount === null ? '' : (float) $product->discount_amount,
                        $raw['sale_price'] === null ? '' : (float) $raw['sale_price'],
                        $product->stock > 0 ? 1 : 0,
                    ]));
                }
            });

        $writer->close();

        return $path;
    }

    /**
     * @return array{updated: int, unchanged: int, errors: list<string>}
     */
    public function import(string $path): array
    {
        $result = ['updated' => 0, 'unchanged' => 0, 'errors' => []];
        $reader = new Reader;

        try {
            $reader->open($path);
        } catch (Throwable) {
            $result['errors'][] = 'Файл не читается — нужен .xlsx, выгруженный из админки';

            return $result;
        }

        DB::transaction(function () use ($reader, &$result) {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $n => $row) {
                    $cells = array_map(fn ($c) => is_string($c) ? trim($c) : $c, $row->toArray());

                    if ($n === 1 || ! array_filter($cells, fn ($c) => $c !== '' && $c !== null)) {
                        continue;
                    }

                    $sku = (string) ($cells[0] ?? '');
                    $product = $sku !== '' ? Product::where('sku', $sku)->first() : null;

                    if (! $product) {
                        $result['errors'][] = "Строка {$n}: товар с SKU «{$sku}» не найден";

                        continue;
                    }

                    try {
                        $values = [
                            'price' => $this->money($cells[4] ?? null, 'цена'),
                            'discount_percent' => $this->money($cells[5] ?? null, 'скидка, %', max: 99.99),
                            'discount_amount' => $this->money($cells[6] ?? null, 'скидка, BYN'),
                            'stock' => $this->flag($cells[8] ?? null, $product->stock),
                        ];
                    } catch (\InvalidArgumentException $e) {
                        $result['errors'][] = "Строка {$n} ({$sku}): {$e->getMessage()}";

                        continue;
                    }

                    // Поля 1С у товара с GUID не меняются (цена и остаток придут из 1С)
                    foreach ($product->lockedFields() as $locked) {
                        unset($values[$locked]);
                    }

                    $product->fill($values);

                    if ($product->isDirty()) {
                        $product->save();
                        $result['updated']++;
                    } else {
                        $result['unchanged']++;
                    }
                }

                break; // только первый лист
            }
        });

        $reader->close();

        return $result;
    }

    private function money(mixed $value, string $label, ?float $max = null): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $number = is_numeric($value) ? (float) $value : (is_string($value) && is_numeric($n = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $value)) ? (float) $n : null);

        if ($number === null || $number < 0 || ($max !== null && $number > $max)) {
            throw new \InvalidArgumentException("{$label} — «{$value}» не число".($max !== null ? " от 0 до {$max}" : ' от 0'));
        }

        return number_format($number, 2, '.', '');
    }

    private function flag(mixed $value, int $current): int
    {
        if ($value === null || $value === '') {
            return $current;
        }

        $v = mb_strtolower(trim((string) $value));

        return match (true) {
            in_array($v, ['1', 'да', 'есть', 'yes', 'true'], true) => max(1, $current),
            in_array($v, ['0', 'нет', 'no', 'false'], true) => 0,
            default => throw new \InvalidArgumentException("«В наличии» — 1 или 0, а не «{$value}»"),
        };
    }
}
