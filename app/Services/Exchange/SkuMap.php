<?php

namespace App\Services\Exchange;

use Illuminate\Support\Facades\DB;

/**
 * Карта «GUID 1С → код товара на сайте» для GET /catalog/sku-map (docs/1C_EXCHANGE_SPEC.md, раздел 4):
 * CSV «guid;sku» (UTF-8 без BOM, строки через \n) по всем не удалённым товарам с GUID,
 * в том числе черновикам и скрытым, по sku. 1С сверяет по ней коды сайта у себя.
 */
class SkuMap
{
    public function csv(): string
    {
        $lines = ['guid;sku'];

        DB::table('products')
            ->whereNotNull('guid')
            ->where('guid', '!=', '')
            ->whereNull('deleted_at')
            ->orderBy('sku')
            ->orderBy('id')
            ->select(['id', 'guid', 'sku'])
            ->lazy(2000)
            ->each(function ($row) use (&$lines) {
                $lines[] = self::cell($row->guid).';'.self::cell($row->sku);
            });

        return implode("\n", $lines)."\n";
    }

    /** Ячейка CSV: с «;», кавычкой или переводом строки — в кавычках, кавычки удваиваются */
    private static function cell(?string $value): string
    {
        $value = (string) $value;

        return strpbrk($value, ";\"\r\n") === false ? $value : '"'.str_replace('"', '""', $value).'"';
    }
}
