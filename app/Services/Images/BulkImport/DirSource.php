<?php

namespace App\Services\Images\BulkImport;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Фото из папки на сервере (artisan images:import {папка}): со вложенными папками, скрытые файлы
 * пропускаются. Файлы не удаляются и не перемещаются.
 */
class DirSource implements Source
{
    public function __construct(private readonly string $dir)
    {
        if (! is_dir($dir) || ! is_readable($dir)) {
            throw new RuntimeException("Папка не найдена или недоступна: {$dir}");
        }
    }

    public function entries(): iterable
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && ! str_starts_with($file->getFilename(), '.')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        foreach ($files as $path) {
            yield FileEntry::make($path, basename($path));
        }
    }

    public function fetch(string $entry): string
    {
        $max = (int) config('shop.images.max_kb') * 1024;

        if (! is_file($entry) || ! is_readable($entry)) {
            throw new RuntimeException('Файл не найден или недоступен');
        }
        if (filesize($entry) > $max) {
            throw new RuntimeException('Файл больше '.round($max / 1048576).' МБ');
        }

        $tmp = TempFile::create();
        if (! copy($entry, $tmp)) {
            @unlink($tmp);

            throw new RuntimeException('Не удалось прочитать файл');
        }

        return $tmp;
    }

    public function close(): void {}
}
