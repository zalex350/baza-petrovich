<?php

namespace App\Support;

use RuntimeException;

/**
 * Таблицы из файлов Excel (.xlsx) и CSV — строки как массивы строк, без внешних библиотек и
 * без расширения zip (в контейнере и на стенде его нет): .xlsx — это zip-архив с XML, архив
 * читается по центральному каталогу, данные распаковываются zlib (gzinflate).
 * Берётся первый лист; формулы — их последнее вычисленное значение. Старый .xls не читается.
 * write() — простой .xlsx (один лист, все ячейки — текст), например шаблон для загрузки.
 */
class Spreadsheet
{
    public const EXTENSIONS = ['xlsx', 'csv', 'txt'];

    private const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /**
     * @return list<list<string>> строки листа; пустые ячейки — ''
     *
     * @throws RuntimeException файл не читается — текст для пользователя
     */
    public static function rows(string $path, string $extension): array
    {
        $extension = strtolower($extension);

        if ($extension === 'xls') {
            throw new RuntimeException('Формат .xls не поддерживается — сохраните файл в Excel как .xlsx или .csv.');
        }

        $content = (string) @file_get_contents($path);

        if ($extension === 'xlsx' || str_starts_with($content, "PK\x03\x04")) {
            return self::xlsxRows($content);
        }

        return self::csvRows($content);
    }

    /**
     * @return list<list<string>>
     */
    public static function csvRows(string $content): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        } elseif (! mb_check_encoding($content, 'UTF-8')) {
            // Excel в русской Windows сохраняет CSV в cp1251
            $content = (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
        }

        // Разделитель — тот, которого больше всего в первой строке (Excel в русской Windows ставит «;»)
        $first = strtok($content, "\n") ?: '';
        $counts = [';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t")];
        $delimiter = array_search(max($counts), $counts, true) ?: ';';

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $rows = [];

        while (($row = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(fn ($cell) => trim((string) $cell), $row);
        }

        fclose($stream);

        return $rows;
    }

    /**
     * @return list<list<string>>
     */
    public static function xlsxRows(string $content): array
    {
        $files = self::unzip($content);

        $sheetPath = self::firstSheetPath($files);

        if (! isset($files[$sheetPath])) {
            throw new RuntimeException('В файле Excel не найден лист с данными.');
        }

        $strings = [];

        if (isset($files['xl/sharedStrings.xml'])) {
            foreach (self::xml($files['xl/sharedStrings.xml'])->si as $item) {
                $strings[] = self::text($item);
            }
        }

        $rows = [];

        foreach (self::xml($files[$sheetPath])->sheetData->row as $row) {
            $number = (int) self::attr($row, 'r') ?: count($rows) + 1;
            $cells = [];

            foreach ($row->c as $cell) {
                $index = self::attr($cell, 'r') !== '' ? self::columnIndex(self::attr($cell, 'r')) : count($cells);

                $value = match (self::attr($cell, 't')) {
                    's' => $strings[(int) $cell->v] ?? '',
                    'inlineStr' => self::text($cell->is),
                    'b' => (string) $cell->v === '1' ? 'TRUE' : 'FALSE',
                    default => self::number((string) $cell->v),
                };

                $cells[$index] = trim($value);
            }

            if ($cells) {
                $cells += array_fill(0, max(array_keys($cells)) + 1, '');
                ksort($cells);
            }

            // Пропущенные пустые строки листа — пустыми, чтобы номера строк совпадали с Excel
            while (count($rows) < $number - 1) {
                $rows[] = [];
            }

            $rows[] = array_values($cells);
        }

        return $rows;
    }

    /**
     * Простой .xlsx: первая строка — заголовки (жирным), все ячейки — текст (формат «@»), чтобы
     * Excel не превращал коды вроде 00123 в число 123.
     *
     * @param  list<list<string>>  $rows
     * @param  list<int>  $widths  ширина столбцов в символах
     */
    public static function write(array $rows, array $widths = [], string $sheet = 'Лист1'): string
    {
        $esc = fn ($value) => htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $cols = '';
        foreach ($widths as $i => $width) {
            $cols .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.$width.'" style="1" customWidth="1"/>';
        }

        $data = '';
        foreach (array_values($rows) as $r => $row) {
            $data .= '<row r="'.($r + 1).'">';
            foreach (array_values($row) as $c => $value) {
                $data .= '<c r="'.self::columnName($c).($r + 1).'" t="inlineStr" s="'.($r === 0 ? 2 : 1).'"><is><t xml:space="preserve">'.$esc($value).'</t></is></c>';
            }
            $data .= '</row>';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n";
        $main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $pkg = 'http://schemas.openxmlformats.org/package/2006/relationships';

        return self::zip([
            '[Content_Types].xml' => $xml.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                .'</Types>',
            '_rels/.rels' => $xml.'<Relationships xmlns="'.$pkg.'">'
                .'<Relationship Id="rId1" Type="'.$rel.'/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',
            'xl/workbook.xml' => $xml.'<workbook xmlns="'.$main.'" xmlns:r="'.$rel.'">'
                .'<sheets><sheet name="'.$esc($sheet).'" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => $xml.'<Relationships xmlns="'.$pkg.'">'
                .'<Relationship Id="rId1" Type="'.$rel.'/worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId2" Type="'.$rel.'/styles" Target="styles.xml"/>'
                .'</Relationships>',
            'xl/styles.xml' => $xml.'<styleSheet xmlns="'.$main.'">'
                .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
                .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
                .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                .'<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
                .'<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
                .'<xf numFmtId="49" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/></cellXfs>'
                .'</styleSheet>',
            'xl/worksheets/sheet1.xml' => $xml.'<worksheet xmlns="'.$main.'">'
                .($cols ? '<cols>'.$cols.'</cols>' : '')
                .'<sheetData>'.$data.'</sheetData></worksheet>',
        ]);
    }

    /**
     * Файлы zip-архива: имя → содержимое (stored и deflate).
     *
     * @return array<string, string>
     */
    private static function unzip(string $content): array
    {
        $broken = new RuntimeException('Файл Excel повреждён или это не .xlsx — сохраните его в Excel заново как .xlsx или .csv.');

        // Конец центрального каталога — в последних 64 КБ (+22 байта заголовка)
        $end = strrpos(substr($content, -65557), "PK\x05\x06");

        if ($end === false) {
            throw $broken;
        }

        $end += max(0, strlen($content) - 65557);
        $eocd = unpack('vdisk/vcdisk/ventries/vtotal/Vsize/Voffset', substr($content, $end + 4, 16));

        $files = [];
        $pos = $eocd['offset'];

        for ($i = 0; $i < $eocd['total']; $i++) {
            if (substr($content, $pos, 4) !== "PK\x01\x02") {
                throw $broken;
            }

            $entry = unpack('vmethod/Vtime/Vcrc/Vcompressed/Vsize/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Voffset', substr($content, $pos + 10, 36));
            $name = substr($content, $pos + 46, $entry['name']);
            $pos += 46 + $entry['name'] + $entry['extra'] + $entry['comment'];

            $local = unpack('vname/vextra', substr($content, $entry['offset'] + 26, 4));
            $data = substr($content, $entry['offset'] + 30 + $local['name'] + $local['extra'], $entry['compressed']);

            $data = match ($entry['method']) {
                0 => $data,
                8 => @gzinflate($data),
                default => false,
            };

            if ($data === false) {
                throw $broken;
            }

            $files[$name] = $data;
        }

        return $files;
    }

    /**
     * @param  array<string, string>  $files
     */
    private static function zip(array $files): string
    {
        $body = '';
        $directory = '';
        // 01.01.2026 00:00 в формате DOS
        $time = 0;
        $date = ((2026 - 1980) << 9) | (1 << 5) | 1;

        foreach ($files as $name => $data) {
            $crc = crc32($data);
            $size = strlen($data);
            $offset = strlen($body);

            $header = pack('vvvvvVVVvv', 20, 0x0800, 0, $time, $date, $crc, $size, $size, strlen($name), 0);
            $body .= "PK\x03\x04".$header.$name.$data;
            $directory .= "PK\x01\x02".pack('vvvvvvVVVvvvvvVV', 20, 20, 0x0800, 0, $time, $date, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $offset).$name;
        }

        return $body.$directory."PK\x05\x06".pack('vvvvVVv', 0, 0, count($files), count($files), strlen($directory), strlen($body), 0);
    }

    /**
     * @param  array<string, string>  $files
     */
    private static function firstSheetPath(array $files): string
    {
        $workbook = $files['xl/workbook.xml'] ?? '';
        $rels = $files['xl/_rels/workbook.xml.rels'] ?? '';

        if (preg_match('/<(?:\w+:)?sheet\b[^>]*\br:id="([^"]+)"/', $workbook, $sheet)
            && preg_match('/<Relationship\b(?=[^>]*\bId="'.preg_quote($sheet[1], '/').'")[^>]*\bTarget="([^"]+)"/', $rels, $target)) {
            $path = ltrim($target[1], '/');

            return str_starts_with($path, 'xl/') ? $path : 'xl/'.$path;
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private static function xml(string $content): \SimpleXMLElement
    {
        // Узлы — в пространстве имён SpreadsheetML: и <sheetData>, и <x:sheetData> (так пишут некоторые программы)
        $xml = @simplexml_load_string($content, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT, self::MAIN);

        if ($xml === false) {
            throw new RuntimeException('Файл Excel повреждён — сохраните его в Excel заново как .xlsx или .csv.');
        }

        return $xml;
    }

    /** Атрибут без пространства имён (r, t) — при разборе с пространством SpreadsheetML иначе не виден */
    private static function attr(\SimpleXMLElement $node, string $name): string
    {
        return (string) $node->attributes('')->{$name};
    }

    /** Текст ячейки: <t> или куски форматированного текста <r><t> */
    private static function text(\SimpleXMLElement $node): string
    {
        if (isset($node->t)) {
            return (string) $node->t;
        }

        $text = '';
        foreach ($node->r as $run) {
            $text .= (string) $run->t;
        }

        return $text;
    }

    /** Число из Excel без хвоста «.0» и экспоненты для целых: 12345.0 → 12345, 1.2345E+5 → 123450 */
    private static function number(string $value): string
    {
        if (is_numeric($value) && preg_match('/[.eE]/', $value)) {
            $float = (float) $value;

            if (floor($float) === $float && abs($float) < 1e15) {
                return number_format($float, 0, '.', '');
            }
        }

        return $value;
    }

    private static function columnIndex(string $reference): int
    {
        $letters = preg_replace('/\d+/', '', strtoupper($reference));
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + ord($letter) - 64;
        }

        return $index - 1;
    }

    private static function columnName(int $index): string
    {
        $name = '';

        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $name = chr(65 + ($index - 1) % 26).$name;
        }

        return $name;
    }
}
