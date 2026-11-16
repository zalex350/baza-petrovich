<?php

namespace App\Services\Images\BulkImport;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Фото по ссылкам: CSV «код;ссылка» (код — SKU, артикул или БРЕНД_АРТИКУЛ; разделитель «;», «,» или
 * табуляция; заголовок необязателен; UTF-8 или cp1251 из Excel). Несколько строк одного товара — фото
 * в порядке строк. Скачивается при обработке: только http(s), до 10 МБ, 30 секунд на файл.
 */
class CsvSource implements Source
{
    public const TIMEOUT = 30;

    public function __construct(private readonly string $path)
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('CSV-файл не найден');
        }
    }

    public function entries(): iterable
    {
        $content = (string) file_get_contents($this->path);
        $content = str_starts_with($content, "\u{FEFF}") ? substr($content, 3) : $content;
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
        }

        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $first = (string) ($lines[0] ?? '');
        $delimiter = collect([';', "\t", ','])->sortByDesc(fn ($d) => substr_count($first, $d))->first();

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $cols = array_map(fn ($v) => trim((string) $v), str_getcsv($line, $delimiter, '"', ''));
            $code = $cols[0] ?? '';
            $url = $cols[1] ?? '';
            $row = $index + 1;

            // Заголовок: первая строка без ссылки
            if ($index === 0 && ! preg_match('#^https?://#i', $url)) {
                continue;
            }

            $name = "строка {$row}: {$code}";

            if ($code === '') {
                yield ['entry' => $url, 'name' => $name, 'skip' => 'нет кода товара'];
            } elseif (! self::validUrl($url)) {
                yield ['entry' => $url, 'name' => $name, 'skip' => 'нет ссылки http(s)'];
            } else {
                yield ['entry' => $url, 'name' => "{$code} — {$url}", 'code' => $code];
            }
        }
    }

    public function fetch(string $entry): string
    {
        if (! self::validUrl($entry)) {
            throw new RuntimeException('Ссылка не http(s)');
        }

        $max = (int) config('shop.images.max_kb') * 1024;
        $tooBig = 'Файл больше '.round($max / 1048576).' МБ';

        try {
            $response = Http::timeout(self::TIMEOUT)->connectTimeout(10)
                ->withOptions([
                    'allow_redirects' => ['max' => 3, 'protocols' => ['http', 'https']],
                    // Большой файл обрываем по заголовку, не скачивая
                    'on_headers' => function ($response) use ($max, $tooBig) {
                        if ((int) $response->getHeaderLine('Content-Length') > $max) {
                            throw new RuntimeException($tooBig);
                        }
                    },
                ])
                ->get($entry);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Не удалось скачать: '.self::reason($e));
        } catch (Throwable $e) {
            $tooLarge = $e->getPrevious() instanceof RuntimeException ? $e->getPrevious()->getMessage() : null;

            throw new RuntimeException($tooLarge ?? ('Не удалось скачать: '.self::reason($e)));
        }

        if (! $response->successful()) {
            throw new RuntimeException("Не удалось скачать: ответ {$response->status()}");
        }

        $body = $response->body();
        if (strlen($body) > $max) {
            throw new RuntimeException($tooBig);
        }

        $tmp = TempFile::create();
        file_put_contents($tmp, $body);

        return $tmp;
    }

    public function close(): void {}

    private static function validUrl(string $url): bool
    {
        return (bool) preg_match('#^https?://[^\s/]+#i', $url) && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private static function reason(Throwable $e): string
    {
        return mb_strimwidth(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 200, '…');
    }
}
