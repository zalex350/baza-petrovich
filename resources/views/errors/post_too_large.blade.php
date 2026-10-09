{{-- Запрос больше post_max_size: сессии нет, поэтому страница без общего макета. Данные: $limit --}}
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Слишком большой объём файлов</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f8fafc; color: #1e293b; margin: 0; padding: 16px; }
        main { max-width: 560px; margin: 10vh auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 24px; }
        a { color: #2563eb; }
    </style>
</head>
<body>
    <main>
        <h1>Слишком большой объём файлов</h1>
        <p>Общий размер загружаемых файлов больше {{ $limit }}. Загрузите фото несколькими частями — каждый файл не больше {{ (int) round(config('shop.images.max_kb') / 1024) }} МБ.</p>
        <p><a href="javascript:history.back()">← Вернуться назад</a></p>
    </main>
</body>
</html>
