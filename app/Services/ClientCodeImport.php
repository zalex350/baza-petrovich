<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductClientCode;
use DateInterval;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ODS\Options as OdsOptions;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Options as WriterOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use RuntimeException;
use Throwable;

/**
 * Загрузка кодов товаров клиента из Excel / CSV: столбцы «Ваш код», «Артикул» и необязательный
 * «Бренд» — как в выгрузке ассортимента (AssortmentExport), её файл принимается как есть;
 * прежние заголовки «Код клиента» и «Наш артикул» тоже понимаются. Строка заголовков — любая
 * из первых 10, порядок столбцов любой.
 *
 * Товар по строке (match): 1) SKU сайта — точно (без регистра и пробелов по краям);
 * 2) есть бренд — «бренд + артикул» через match_key (Product::matchKey); 3) артикул без бренда
 * (нормализация как в match_key) — только если он у одного товара. Несколько совпадений —
 * ошибка строки с перечнем «БРЕНД · Арт. …». Бренд в файле не совпал с брендом единственного
 * товара с таким артикулом — тоже ошибка: код не должен уйти чужому товару. Файлы читает и шаблон
 * пишет openspout/openspout (нужно расширение zip): .xlsx и .ods (LibreOffice) — первый
 * лист, формулы — их вычисленное значение; CSV — UTF-8 (с BOM или без) или cp1251, разделитель «;», «,» или Tab.
 *
 * Режимы: add — добавить новые и обновить изменённые, остальные коды не трогать;
 * replace — после загрузки у организации ровно коды из файла, остальные удаляются.
 *
 * Сначала preview() (база не меняется), затем apply() — в транзакции, с той же проверкой
 * по текущему состоянию базы. Строки с ошибками пропускаются; в режиме replace при любой
 * ошибке применять нельзя — иначе коды товаров из ошибочных строк были бы удалены.
 *
 * Код сравнивается без учёта регистра и пробелов по краям — как уникальный индекс в MySQL
 * (utf8mb4_unicode_ci): «ab-1» и «AB-1» — один код.
 */
class ClientCodeImport
{
    public const MODE_ADD = 'add';

    public const MODE_REPLACE = 'replace';

    public const MODES = [
        self::MODE_ADD => 'Добавить и обновить',
        self::MODE_REPLACE => 'Заменить полностью',
    ];

    /** Заголовки — как в выгрузке ассортимента (AssortmentExport::HEADERS) */
    public const CODE_HEADER = 'Ваш код';

    public const ARTICLE_HEADER = 'Артикул';

    public const BRAND_HEADER = 'Бренд';

    /** Прежние заголовки шаблона — тоже принимаются */
    public const LEGACY_HEADERS = ['Код клиента' => self::CODE_HEADER, 'Наш артикул' => self::ARTICLE_HEADER];

    public const MAX_ROWS = 10000;

    public const MAX_KB = 5120;

    /** Строк с текстовым форматом в шаблоне */
    public const TEMPLATE_ROWS = 1000;

    /**
     * Строки файла: [['row' => номер строки в файле, 'article' => …, 'brand' => …, 'code' => …], …],
     * без строки заголовков и строк без артикула и кода.
     *
     * @return list<array{row: int, article: string, brand: string, code: string}>
     *
     * @throws RuntimeException файл не читается или нет нужных столбцов — текст для пользователя
     */
    public function read(string $path, string $extension): array
    {
        $rows = $this->rows($path, $extension);

        [$headerIndex, $articleColumn, $codeColumn, $brandColumn] = $this->columns($rows);

        $result = [];

        foreach (array_slice($rows, $headerIndex + 1, null, true) as $index => $cells) {
            $article = trim((string) ($cells[$articleColumn] ?? ''));
            $code = trim((string) ($cells[$codeColumn] ?? ''));
            $brand = $brandColumn === null ? '' : trim((string) ($cells[$brandColumn] ?? ''));

            if ($article === '' && $code === '') {
                continue;
            }

            $result[] = ['row' => $index + 1, 'article' => $article, 'brand' => $brand, 'code' => $code];
        }

        if ($result === []) {
            throw new RuntimeException('В файле нет строк с кодами под заголовками «'.self::CODE_HEADER.'» и «'.self::ARTICLE_HEADER.'».');
        }

        if (count($result) > self::MAX_ROWS) {
            throw new RuntimeException('В файле больше '.self::MAX_ROWS.' строк — разбейте его на части.');
        }

        return $result;
    }

    /**
     * Шаблон для загрузки: .xlsx с заголовками (жирным) и текстовым форматом «@» в первых
     * TEMPLATE_ROWS строках — чтобы Excel не превращал введённые коды вроде 00123 в число 123.
     *
     * @return string путь к временному файлу — удалить после отправки
     */
    public function template(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'codes-template');

        $options = new WriterOptions;
        $options->setColumnWidth(24, 1, 2, 3);

        $writer = new XlsxWriter($options);
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Коды');
        // Столбцы — как в выгрузке ассортимента: её можно заполнить и загрузить обратно
        $writer->addRow(Row::fromValues([self::CODE_HEADER, self::ARTICLE_HEADER, self::BRAND_HEADER], (new Style)->setFormat('@')->setFontBold()));

        // Формат хранится в ячейке, а пустые ячейки и строки openspout не записывает —
        // поэтому ячейки с пустым текстом
        $text = (new Style)->setFormat('@');

        for ($i = 0; $i < self::TEMPLATE_ROWS; $i++) {
            $writer->addRow(new Row([new Cell\StringCell('', $text), new Cell\StringCell('', $text), new Cell\StringCell('', $text)]));
        }

        $writer->close();

        return $path;
    }

    /**
     * Что изменится: add — новые коды, update — было → стало, delete — коды, которые режим
     * replace удалит, unchanged — число строк без изменений, errors — ошибки по строкам.
     *
     * @param  list<array{row: int, article: string, brand: string, code: string}>  $rows
     */
    public function preview(Organization $organization, array $rows, string $mode): array
    {
        $existing = ProductClientCode::with('product:id,sku,article,brand,name,display_name')
            ->where('organization_id', $organization->id)
            ->get()
            ->keyBy('product_id')
            // except() у Eloquent-коллекции — по id моделей, здесь нужен по ключу product_id
            ->toBase();

        $catalog = $this->catalog();

        $errors = [];
        $valid = [];
        $byCode = [];
        $byProduct = [];

        // Проверки строки по самому файлу
        foreach ($rows as $row) {
            $message = match (true) {
                $row['article'] === '' => 'Пустое поле «'.self::ARTICLE_HEADER.'».',
                $row['code'] === '' => 'Пустое поле «'.self::CODE_HEADER.'».',
                mb_strlen($row['code']) > 100 => 'Код длиннее 100 символов.',
                default => null,
            };

            if ($message === null) {
                [$product, $message] = $this->match($catalog, $row['article'], $row['brand']);
            }

            if ($message !== null) {
                $errors[] = $this->error($row, $message);

                continue;
            }

            $row['product'] = $product;
            $valid[] = $row;
            $byCode[self::key($row['code'])][] = $row['row'];
            $byProduct[$row['product']->id][] = $row['row'];
        }

        // Повторы внутри файла: какая из строк правильная — неизвестно, ошибка у всех
        $valid = array_values(array_filter($valid, function ($row) use (&$errors, $byCode, $byProduct) {
            $codeRows = $byCode[self::key($row['code'])];
            $productRows = $byProduct[$row['product']->id];

            $message = match (true) {
                count($codeRows) > 1 => 'Код повторяется в файле (строки '.implode(', ', $codeRows).').',
                count($productRows) > 1 => 'Товар повторяется в файле (строки '.implode(', ', $productRows).') — у товара один код.',
                default => null,
            };

            if ($message !== null) {
                $errors[] = $this->error($row, $message);
            }

            return $message === null;
        }));

        // Код занят товаром, код которого файл не меняет. В режиме replace таких нет — все
        // коды не из файла удаляются. Ошибка у строки оставляет код её товара как был, и он
        // может занять код другой строки — поэтому до тех пор, пока ошибки появляются.
        if ($mode !== self::MODE_REPLACE) {
            do {
                $kept = $existing->except(array_map(fn ($row) => $row['product']->id, $valid))
                    ->keyBy(fn ($code) => self::key($code->code));

                $before = count($valid);

                $valid = array_values(array_filter($valid, function ($row) use (&$errors, $kept) {
                    $holder = $kept->get(self::key($row['code']));

                    if ($holder === null || $holder->product_id === $row['product']->id) {
                        return true;
                    }

                    $errors[] = $this->error($row, 'Код уже занят другим товаром: '.$this->productLabel($holder->product).'.');

                    return false;
                }));
            } while (count($valid) !== $before);
        }

        $add = [];
        $update = [];
        $unchanged = 0;

        foreach ($valid as $row) {
            $current = $existing->get($row['product']->id);

            if ($current === null) {
                $add[] = $row;
            } elseif ($current->code !== $row['code']) {
                $update[] = $row + ['old' => $current->code];
            } else {
                $unchanged++;
            }
        }

        $inFile = array_map(fn ($row) => $row['product']->id, $valid);
        $delete = $mode === self::MODE_REPLACE
            ? $existing->except($inFile)->sortBy(fn ($code) => $code->product?->sku)->values()->all()
            : [];

        usort($errors, fn ($a, $b) => $a['row'] <=> $b['row']);

        return [
            'mode' => $mode,
            'add' => $add,
            'update' => $update,
            'delete' => $delete,
            'unchanged' => $unchanged,
            'errors' => $errors,
            'total' => count($rows),
            'can_apply' => ($add || $update || $delete) && ! ($mode === self::MODE_REPLACE && $errors),
        ];
    }

    /**
     * Применение в транзакции. Проверка — заново по текущей базе (между предпросмотром
     * и «Применить» коды могли изменить в карточке товара).
     *
     * @param  list<array{row: int, article: string, brand: string, code: string}>  $rows
     * @return array preview() — что применено
     *
     * @throws RuntimeException применить нельзя (replace с ошибками или нечего менять)
     */
    public function apply(Organization $organization, array $rows, string $mode): array
    {
        return DB::transaction(function () use ($organization, $rows, $mode) {
            // Блокировка кодов организации — параллельная правка ждёт конца транзакции
            ProductClientCode::where('organization_id', $organization->id)->lockForUpdate()->get(['id']);

            $preview = $this->preview($organization, $rows, $mode);

            if (! $preview['can_apply']) {
                throw new RuntimeException($mode === self::MODE_REPLACE && $preview['errors']
                    ? 'В режиме «'.self::MODES[self::MODE_REPLACE].'» файл должен быть без ошибок — исправьте строки и загрузите снова.'
                    : 'Применять нечего: коды в файле совпадают с текущими.');
            }

            // Сначала удаляются старые записи изменяемых и удаляемых товаров, затем вставляются
            // новые — иначе обмен кодами между двумя товарами упёрся бы в уникальный индекс
            $productIds = array_merge(
                array_map(fn ($row) => $row['product']->id, $preview['update']),
                array_map(fn ($code) => $code->product_id, $preview['delete']),
            );

            ProductClientCode::where('organization_id', $organization->id)
                ->whereIn('product_id', $productIds)
                ->delete();

            $now = now();

            foreach (array_chunk(array_merge($preview['add'], $preview['update']), 500) as $chunk) {
                ProductClientCode::insert(array_map(fn ($row) => [
                    'organization_id' => $organization->id,
                    'product_id' => $row['product']->id,
                    'code' => $row['code'],
                    'code_key' => ProductClientCode::key($row['code']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }

            return $preview;
        });
    }

    /** Ключ сравнения: без регистра и пробелов по краям */
    public static function key(string $value): string
    {
        return ProductClientCode::key($value);
    }

    public function productLabel(?Product $product): string
    {
        if (! $product) {
            return 'удалённый товар';
        }

        $brand = trim((string) $product->brand);

        return ($brand !== '' ? mb_strtoupper($brand).' · ' : '').'Арт. '.$product->displayArticle().' — '.$product->title();
    }

    /** «БРЕНД · Арт. L4755-2» — в перечне неоднозначных совпадений */
    public static function choiceLabel(Product $product): string
    {
        return (trim((string) $product->brand) !== '' ? mb_strtoupper(trim($product->brand)) : 'Без бренда').' · Арт. '.$product->displayArticle();
    }

    /**
     * Перечень «БРЕНД · Арт. …» через запятую; одинаковые подписи (дубль в каталоге) —
     * с названием товара: «SAVOL · Арт. X-1 («Сифон»)».
     *
     * @param  list<Product>  $products
     */
    private static function choiceList(array $products): string
    {
        $labels = array_map(fn (Product $p) => self::choiceLabel($p), $products);
        $counts = array_count_values($labels);

        return implode(', ', array_map(
            fn (Product $p, string $label) => $counts[$label] > 1 ? $label.' («'.$p->title().'»)' : $label,
            $products,
            $labels,
        ));
    }

    /**
     * Товар по артикулу (и бренду) строки: [товар, null] или [null, текст ошибки].
     *
     * @param  array{sku: array, key: array, article: array}  $catalog
     * @return array{?Product, ?string}
     */
    private function match(array $catalog, string $article, string $brand): array
    {
        $several = fn (array $products) => [null, 'Артикул «'.$article.'» есть у нескольких товаров: '
            .self::choiceList($products)
            .($brand === '' ? ' — укажите бренд в столбце «'.self::BRAND_HEADER.'»' : '')
            .' или задайте код в карточке нужного товара.'];

        // 1. SKU сайта — точно
        if ($product = $catalog['sku'][self::key($article)] ?? null) {
            return [$product, null];
        }

        // 2. Бренд + артикул
        if ($brand !== '' && ($key = Product::matchKey($brand, $article)) !== null) {
            $found = $catalog['key'][$key] ?? [];

            if (count($found) === 1) {
                return [$found[0], null];
            }

            if (count($found) > 1) {
                return $several($found);
            }
        }

        // 3. Артикул без бренда — только у одного товара
        $found = $catalog['article'][Product::normalizeKeyPart($article)] ?? [];

        if ($found === []) {
            return [null, $brand !== '' ? 'Товар с брендом «'.$brand.'» и артикулом «'.$article.'» не найден в каталоге.' : 'Артикул не найден в каталоге.'];
        }

        if (count($found) > 1) {
            return $several($found);
        }

        // Бренд в файле есть, но не тот: код не должен уйти чужому товару
        if ($brand !== '' && Product::normalizeKeyPart($brand) !== Product::normalizeKeyPart($found[0]->brand)) {
            return [null, 'Артикул «'.$article.'» есть только у товара '.self::choiceLabel($found[0]).', а в файле бренд «'.$brand.'».'];
        }

        return [$found[0], null];
    }

    /**
     * Строка заголовков и номера столбцов «Артикул», «Ваш код» и «Бренд» (нет — null);
     * прежние «Наш артикул», «Код клиента» — тоже.
     *
     * @param  list<list<string>>  $rows
     * @return array{int, int, int, ?int}
     */
    private function columns(array $rows): array
    {
        $normalize = fn ($value) => str_replace('ё', 'е', mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $value))));
        $aliases = [];

        foreach ([self::CODE_HEADER, self::ARTICLE_HEADER, self::BRAND_HEADER] as $header) {
            $aliases[$normalize($header)] = $header;
        }

        foreach (self::LEGACY_HEADERS as $legacy => $header) {
            $aliases[$normalize($legacy)] = $header;
        }

        foreach (array_slice($rows, 0, 10, true) as $index => $cells) {
            $found = [];

            foreach ($cells as $column => $cell) {
                $header = $aliases[$normalize($cell)] ?? null;

                if ($header !== null && ! isset($found[$header])) {
                    $found[$header] = $column;
                }
            }

            if (isset($found[self::ARTICLE_HEADER], $found[self::CODE_HEADER])) {
                return [$index, $found[self::ARTICLE_HEADER], $found[self::CODE_HEADER], $found[self::BRAND_HEADER] ?? null];
            }
        }

        throw new RuntimeException('В файле нет столбцов «'.self::CODE_HEADER.'» и «'.self::ARTICLE_HEADER.'» — скачайте шаблон или выгрузку ассортимента и заполните её.');
    }

    /**
     * Строки первого листа как массивы строк (пустые ячейки — ''); номер строки в файле —
     * индекс + 1 (пустые строки сохраняются).
     *
     * @return list<list<string>>
     *
     * @throws RuntimeException файл не читается — текст для пользователя
     */
    private function rows(string $path, string $extension): array
    {
        $extension = strtolower($extension);

        if ($extension === 'xls') {
            throw new RuntimeException('Формат .xls не поддерживается — сохраните файл в Excel как .xlsx или .csv.');
        }

        $head = (string) @file_get_contents($path, false, null, 0, 65536);

        // По содержимому, а не только по расширению: .ods — zip, первый файл которого
        // mimetype «application/vnd.oasis.opendocument.spreadsheet»
        $kind = match (true) {
            $extension === 'ods' || (str_starts_with($head, "PK\x03\x04") && str_contains(substr($head, 0, 100), 'opendocument.spreadsheet')) => 'ods',
            $extension === 'xlsx' || str_starts_with($head, "PK\x03\x04") => 'xlsx',
            default => 'csv',
        };

        if ($kind === 'ods') {
            $options = new OdsOptions;
            $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
            $reader = new OdsReader($options);
        } elseif ($kind === 'xlsx') {
            $options = new XlsxOptions;
            $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
            $reader = new XlsxReader($options);
        } else {
            $reader = new CsvReader($this->csvOptions($path, $head));
        }

        $rows = [];

        try {
            $reader->open($path);

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_map(fn (Cell $cell) => $this->cellText($cell), $row->getCells());

                    // Лишнее не читается: read() всё равно откажет при числе строк > MAX_ROWS
                    if (count($rows) > self::MAX_ROWS + 10) {
                        break;
                    }
                }

                break; // только первый лист
            }
        } catch (Throwable) {
            throw new RuntimeException(match ($kind) {
                'xlsx' => 'Файл Excel повреждён или это не .xlsx — сохраните его в Excel заново как .xlsx или .csv.',
                'ods' => 'Файл .ods повреждён — сохраните его заново как .ods, .xlsx или .csv.',
                default => 'Файл CSV не читается — сохраните его заново как .csv или .xlsx.',
            });
        } finally {
            $reader->close();
        }

        return $rows;
    }

    /**
     * Кодировка и разделитель CSV: без BOM и не UTF-8 — cp1251 (так сохраняет Excel в русской
     * Windows); разделитель — тот, которого больше всего в первой строке.
     */
    private function csvOptions(string $path, string $head): CsvOptions
    {
        $options = new CsvOptions;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;

        $bom = "\xEF\xBB\xBF";

        if (! str_starts_with($head, $bom) && ! mb_check_encoding((string) file_get_contents($path), 'UTF-8')) {
            $options->ENCODING = 'Windows-1251';
        }

        $first = (string) strtok(str_starts_with($head, $bom) ? substr($head, 3) : $head, "\n");
        $counts = [';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t")];
        $options->FIELD_DELIMITER = max($counts) > 0 ? (string) array_search(max($counts), $counts, true) : ';';

        return $options;
    }

    /** Значение ячейки текстом: целые числа без «.0» и экспоненты (12345.0 → 12345), даты — дд.мм.гггг */
    private function cellText(Cell $cell): string
    {
        $value = $cell instanceof Cell\FormulaCell ? $cell->getComputedValue() : $cell->getValue();

        return trim(match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_float($value) && floor($value) === $value && abs($value) < 1e15 => number_format($value, 0, '.', ''),
            $value instanceof DateTimeInterface => $value->format('d.m.Y'),
            $value instanceof DateInterval => $value->format('%h:%I:%S'),
            default => (string) $value,
        });
    }

    /**
     * Каталог для сопоставления одной выборкой (удалённые не участвуют): sku → товар,
     * match_key → товары, нормализованный артикул (как в match_key) → товары.
     *
     * @return array{sku: array<string, Product>, key: array<string, list<Product>>, article: array<string, list<Product>>}
     */
    private function catalog(): array
    {
        $catalog = ['sku' => [], 'key' => [], 'article' => []];

        foreach (Product::query()->select(['id', 'sku', 'article', 'brand', 'name', 'display_name', 'match_key'])->lazyById(2000) as $product) {
            $catalog['sku'][self::key((string) $product->sku)] ??= $product;

            if ($product->match_key !== null) {
                $catalog['key'][$product->match_key][] = $product;
            }

            if (($article = Product::normalizeKeyPart($product->article)) !== '') {
                $catalog['article'][$article][] = $product;
            }
        }

        return $catalog;
    }

    private function error(array $row, string $message): array
    {
        return ['row' => $row['row'], 'article' => $row['article'], 'brand' => $row['brand'] ?? '', 'code' => $row['code'], 'message' => $message];
    }
}
