<?php

namespace App\Services\Images\BulkImport;

/**
 * Элемент списка файлов ZIP или папки: картинка по расширению — с именем без расширения (stem),
 * иначе — skip.
 */
final class FileEntry
{
    /** @return array{entry: string, name: string, stem?: string, skip?: string} */
    public static function make(string $entry, string $name): array
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (! in_array($extension, Source::EXTENSIONS, true)) {
            return ['entry' => $entry, 'name' => $name, 'skip' => 'не JPG, PNG или WebP'];
        }

        return ['entry' => $entry, 'name' => $name, 'stem' => pathinfo($name, PATHINFO_FILENAME)];
    }
}
