<?php

namespace App\Services\Images\BulkImport;

/**
 * Источник фото массовой загрузки: ZIP, папка на сервере или CSV «код;ссылка».
 */
interface Source
{
    /** Расширения картинок (остальные файлы ZIP / папки — в «пропущено») */
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * Файлы источника. Картинка: entry — как достать (fetch), name — показать людям, stem — имя без
     * расширения (ZIP, папка) или code — код товара (CSV). skip — не картинка или строка без ссылки.
     *
     * @return iterable<array{entry: string, name: string, stem?: string, code?: string, skip?: string}>
     */
    public function entries(): iterable;

    /**
     * Файл во временную папку; вызывающий удаляет его сам.
     *
     * @throws \RuntimeException текст — в ошибки загрузки
     */
    public function fetch(string $entry): string;

    /** Освободить ресурсы (закрыть архив) */
    public function close(): void;
}
