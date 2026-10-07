<?php

namespace App\Exceptions;

use DomainException;

/**
 * Фото не удалось принять (ERR-010): файл повреждён, не изображение или слишком большое разрешение.
 */
class ImageUploadException extends DomainException
{
    public static function broken(string $name): self
    {
        return new self("Файл «{$name}» повреждён или не является изображением");
    }

    public static function tooLarge(string $name, int $megapixels): self
    {
        return new self("Файл «{$name}»: слишком большое разрешение — не больше {$megapixels} Мп");
    }
}
