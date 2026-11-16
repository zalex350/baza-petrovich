<?php

namespace App\Services\Images\BulkImport;

use RuntimeException;
use ZipArchive;

/**
 * Фото из ZIP: файлы в любых папках архива, имя файла — артикул / SKU. Служебные файлы macOS
 * (__MACOSX, «._…») и скрытые пропускаются молча. Архив не распаковывается целиком: каждый
 * файл — во временный файл по одному.
 */
class ZipSource implements Source
{
    private ZipArchive $zip;

    public function __construct(string $path)
    {
        $this->zip = new ZipArchive;

        if ($this->zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Файл не похож на ZIP-архив или повреждён');
        }
    }

    public function entries(): iterable
    {
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $name = (string) $this->zip->getNameIndex($i);
            $base = basename(str_replace('\\', '/', $name));

            if (str_ends_with($name, '/') || $base === '' || str_starts_with($base, '.') || str_contains($name, '__MACOSX/')) {
                continue;
            }

            yield FileEntry::make($name, $base);
        }
    }

    public function fetch(string $entry): string
    {
        $max = (int) config('shop.images.max_kb') * 1024;
        $stat = $this->zip->statName($entry);

        if ($stat === false) {
            throw new RuntimeException('Файла нет в архиве');
        }
        if ($stat['size'] > $max) {
            throw new RuntimeException('Файл больше '.round($max / 1048576).' МБ');
        }

        $stream = $this->zip->getStream($entry);
        if ($stream === false) {
            throw new RuntimeException('Не удалось прочитать файл из архива');
        }

        $tmp = TempFile::create();
        $out = fopen($tmp, 'wb');
        // Размер в оглавлении архива можно подделать — читаем не больше лимита
        $copied = stream_copy_to_stream($stream, $out, $max + 1);
        fclose($out);
        fclose($stream);

        if ($copied === false || $copied > $max) {
            @unlink($tmp);

            throw new RuntimeException($copied === false ? 'Не удалось прочитать файл из архива' : 'Файл больше '.round($max / 1048576).' МБ');
        }

        return $tmp;
    }

    public function close(): void
    {
        @$this->zip->close();
    }
}
