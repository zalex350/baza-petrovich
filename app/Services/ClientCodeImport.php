<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductClientCode;
use App\Support\Spreadsheet;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Загрузка кодов товаров клиента из Excel / CSV: столбцы «Наш артикул» и «Код клиента»
 * (строка заголовков — любая из первых 10, порядок столбцов любой).
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

    public const SKU_HEADER = 'Наш артикул';

    public const CODE_HEADER = 'Код клиента';

    public const MAX_ROWS = 10000;

    public const MAX_KB = 5120;

    /**
     * Строки файла: [['row' => номер строки в файле, 'sku' => …, 'code' => …], …], без строки
     * заголовков и полностью пустых строк.
     *
     * @return list<array{row: int, sku: string, code: string}>
     *
     * @throws RuntimeException файл не читается или нет нужных столбцов — текст для пользователя
     */
    public function read(string $path, string $extension): array
    {
        $rows = Spreadsheet::rows($path, $extension);

        [$headerIndex, $skuColumn, $codeColumn] = $this->columns($rows);

        $result = [];

        foreach (array_slice($rows, $headerIndex + 1, null, true) as $index => $cells) {
            $sku = trim((string) ($cells[$skuColumn] ?? ''));
            $code = trim((string) ($cells[$codeColumn] ?? ''));

            if ($sku === '' && $code === '') {
                continue;
            }

            $result[] = ['row' => $index + 1, 'sku' => $sku, 'code' => $code];
        }

        if ($result === []) {
            throw new RuntimeException('В файле нет строк с кодами под заголовками «'.self::SKU_HEADER.'» и «'.self::CODE_HEADER.'».');
        }

        if (count($result) > self::MAX_ROWS) {
            throw new RuntimeException('В файле больше '.self::MAX_ROWS.' строк — разбейте его на части.');
        }

        return $result;
    }

    /**
     * Что изменится: add — новые коды, update — было → стало, delete — коды, которые режим
     * replace удалит, unchanged — число строк без изменений, errors — ошибки по строкам.
     *
     * @param  list<array{row: int, sku: string, code: string}>  $rows
     */
    public function preview(Organization $organization, array $rows, string $mode): array
    {
        $existing = ProductClientCode::with('product:id,sku,name')
            ->where('organization_id', $organization->id)
            ->get()
            ->keyBy('product_id')
            // except() у Eloquent-коллекции — по id моделей, здесь нужен по ключу product_id
            ->toBase();

        $products = $this->products(array_column($rows, 'sku'));

        $errors = [];
        $valid = [];
        $byCode = [];
        $byProduct = [];

        // Проверки строки по самому файлу
        foreach ($rows as $row) {
            $message = match (true) {
                $row['sku'] === '' && $row['code'] === '' => null,
                $row['sku'] === '' => 'Пустое поле «'.self::SKU_HEADER.'».',
                $row['code'] === '' => 'Пустое поле «'.self::CODE_HEADER.'».',
                mb_strlen($row['code']) > 100 => 'Код длиннее 100 символов.',
                ! isset($products[self::key($row['sku'])]) => 'Артикул не найден в каталоге.',
                count($products[self::key($row['sku'])]) > 1 => 'Артикул есть у нескольких товаров: '
                    .collect($products[self::key($row['sku'])])->map(fn ($product) => '«'.$product->name.'»')->implode(', ')
                    .' — код задайте в карточке нужного товара.',
                default => null,
            };

            if ($message !== null) {
                $errors[] = $this->error($row, $message);

                continue;
            }

            $row['product'] = $products[self::key($row['sku'])][0];
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
     * @param  list<array{row: int, sku: string, code: string}>  $rows
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
        return mb_strtolower(trim($value));
    }

    public function productLabel(?Product $product): string
    {
        return $product ? trim(($product->sku ?? '—').' — '.$product->name) : 'удалённый товар';
    }

    /**
     * Строка заголовков и номера столбцов «Наш артикул», «Код клиента».
     *
     * @param  list<list<string>>  $rows
     * @return array{int, int, int}
     */
    private function columns(array $rows): array
    {
        $normalize = fn ($value) => str_replace('ё', 'е', mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $value))));

        foreach (array_slice($rows, 0, 10, true) as $index => $cells) {
            $cells = array_map($normalize, $cells);
            $sku = array_search($normalize(self::SKU_HEADER), $cells, true);
            $code = array_search($normalize(self::CODE_HEADER), $cells, true);

            if ($sku !== false && $code !== false) {
                return [$index, $sku, $code];
            }
        }

        throw new RuntimeException('В файле нет столбцов «'.self::SKU_HEADER.'» и «'.self::CODE_HEADER.'» — скачайте шаблон и заполните его.');
    }

    /**
     * Товары по артикулам: ключ — артикул без регистра, значение — список (артикул в каталоге
     * не уникален). Удалённые товары не участвуют.
     *
     * @param  list<string>  $skus
     * @return array<string, list<Product>>
     */
    private function products(array $skus): array
    {
        $skus = array_values(array_unique(array_filter(array_map('trim', $skus), fn ($sku) => $sku !== '')));
        $products = [];

        foreach (array_chunk($skus, 500) as $chunk) {
            foreach (Product::whereIn('sku', $chunk)->get(['id', 'sku', 'name']) as $product) {
                $products[self::key($product->sku)][] = $product;
            }
        }

        return $products;
    }

    private function error(array $row, string $message): array
    {
        return ['row' => $row['row'], 'sku' => $row['sku'], 'code' => $row['code'], 'message' => $message];
    }
}
