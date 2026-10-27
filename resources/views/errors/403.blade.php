@extends('errors.layout', [
    'code' => 403,
    'icon' => 'lock-closed',
    'heading' => 'Доступ запрещён',
    // abort(403, 'Текст') с русским текстом — показать его; стандартное английское — нет
    'text' => preg_match('/\p{Cyrillic}/u', $exception->getMessage())
        ? $exception->getMessage()
        : 'У вашей учётной записи нет доступа к этой странице.',
])
