<?php

namespace App\Services\Images\BulkImport;

use RuntimeException;

/** Временный файл для одного фото (удаляет вызывающий) */
final class TempFile
{
    public static function create(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bulk-img-');

        if ($path === false) {
            throw new RuntimeException('Не удалось создать временный файл');
        }

        return $path;
    }
}
