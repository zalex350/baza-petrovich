<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Product;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Выгрузка ассортимента для организации клиента (кабинет → «Выгрузка ассортимента»,
 * docs/MODULES.md §11): .xlsx (openspout/openspout) — ваш код, артикул, бренд, название,
 * цена без НДС, цена с НДС, остаток. Артикул — Product::displayArticle (артикул производителя,
 * без него — код сайта); SKU как отдельная колонка клиенту не выводится.
 *
 * Товары — опубликованные (is_active и is_published), с кодом этой организации; с опцией
 * withoutCode — и без кода (ячейка кода пустая, такие строки — после строк с кодом).
 * Только коды своей организации. Остаток — точным числом до shop.export_stock_cap,
 * больше — «более N». Цены — текущие цены товара (без НДС и с НДС по ставке товара); цены
 * нет — «Цена уточняется». Порядок — по артикулу.
 */
class AssortmentExport
{
    public const HEADERS = ['Ваш код', 'Артикул', 'Бренд', 'Название', 'Цена без НДС', 'Цена с НДС', 'Остаток'];

    /** Товаров в одной выборке: файл пишется потоково, весь каталог в памяти не держится */
    private const CHUNK = 1000;

    /**
     * @return string путь к временному .xlsx — удалить после отправки
     */
    public function write(Organization $organization, bool $withoutCode = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'assortment');

        $options = new Options;
        $options->setColumnWidth(18, 1);
        $options->setColumnWidth(16, 2, 3);
        $options->setColumnWidth(60, 4);
        $options->setColumnWidth(14, 5, 6, 7);

        $writer = new Writer($options);
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Ассортимент');

        $writer->addRow(Row::fromValues(self::HEADERS, (new Style)->setFontBold()));

        // Код и артикул — текст: Excel не превратит 00123 в 123
        $text = (new Style)->setFormat('@');
        $money = (new Style)->setFormat('#,##0.00');

        $this->products($organization, codes: true)->each(fn ($product) => $writer->addRow($this->row($product, $text, $money)));

        if ($withoutCode) {
            $this->products($organization, codes: false)->each(fn ($product) => $writer->addRow($this->row($product, $text, $money)));
        }

        $writer->close();

        return $path;
    }

    /**
     * Опубликованные товары с кодом организации (codes) или без него; export_code — код.
     */
    private function products(Organization $organization, bool $codes)
    {
        return Product::query()
            ->select(['products.id', 'products.sku', 'products.article', 'products.brand', 'products.name', 'products.display_name', 'products.price', 'products.vat_rate', 'products.stock'])
            ->addSelect('product_client_codes.code as export_code')
            ->leftJoin('product_client_codes', fn ($join) => $join
                ->on('product_client_codes.product_id', '=', 'products.id')
                ->where('product_client_codes.organization_id', $organization->id))
            ->where('products.is_active', true)
            ->where('products.is_published', true)
            ->when($codes, fn ($q) => $q->whereNotNull('product_client_codes.id'), fn ($q) => $q->whereNull('product_client_codes.id'))
            ->orderByRaw("COALESCE(NULLIF(TRIM(products.article), ''), products.sku)")
            ->orderBy('products.id')
            ->lazy(self::CHUNK);
    }

    private function row(Product $product, Style $text, Style $money): Row
    {
        $price = $product->price === null ? null : (float) $product->price;

        return new Row([
            Cell::fromValue((string) $product->export_code, $text),
            Cell::fromValue($product->displayArticle(), $text),
            Cell::fromValue((string) $product->brand),
            Cell::fromValue($product->title()),
            $price === null ? Cell::fromValue('Цена уточняется') : Cell::fromValue($price, $money),
            $price === null ? Cell::fromValue('Цена уточняется') : Cell::fromValue($product->price_with_vat, $money),
            Cell::fromValue(self::stock((int) $product->stock)),
        ]);
    }

    /** Остаток: точное число до shop.export_stock_cap, больше — «более N» */
    public static function stock(int $stock): int|string
    {
        $cap = (int) config('shop.export_stock_cap');

        return $stock > $cap ? 'более '.$cap : max(0, $stock);
    }
}
