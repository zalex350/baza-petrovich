<?php

namespace App\Services\Exchange;

use App\Enums\ExchangeJobType;
use App\Exceptions\ExchangeApiException;
use Illuminate\Http\Request;

/**
 * Приём тела запроса API обмена (docs/1C_EXCHANGE_SPEC.md, раздел 2): тело = сам файл,
 * text/csv или application/json, можно Content-Encoding: gzip, не больше
 * shop.exchange_1c.max_body_mb (и сжатое, и распакованное) — иначе 413.
 * Проверки до постановки в очередь (400): кодировка UTF-8 (BOM допускается и снимается),
 * у CSV — заголовок с обязательными колонками типа, у JSON — синтаксис.
 * Результат — временный файл с распакованным содержимым без BOM.
 */
class ExchangeUpload
{
    private const CHUNK = 1 << 20;

    /**
     * @param  list<string>  $contentTypes  допустимые типы: text/csv, application/json
     * @return array{path: string, size: int, content_type: string, gzip: bool, extension: string}
     *
     * @throws ExchangeApiException
     */
    public function receive(Request $request, ExchangeJobType $type, array $contentTypes): array
    {
        $limit = self::limitBytes();
        $length = $request->headers->get('Content-Length');

        if ($length !== null && (int) $length > $limit) {
            throw ExchangeApiException::tooLarge(self::limitMb());
        }

        $contentType = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));

        if (! in_array($contentType, $contentTypes, true)) {
            throw ExchangeApiException::badRequest('unsupported_content_type',
                'Content-Type должен быть '.implode(' или ', $contentTypes).", получено «{$contentType}»");
        }

        $encoding = strtolower(trim((string) $request->headers->get('Content-Encoding')));

        if (! in_array($encoding, ['', 'identity', 'gzip'], true)) {
            throw ExchangeApiException::badRequest('unsupported_encoding', "Content-Encoding «{$encoding}» не поддерживается, допустим gzip");
        }

        $raw = $this->copyBody($request, $limit);
        $gzip = $encoding === 'gzip';

        try {
            $path = $gzip ? $this->gunzip($raw, $limit) : $raw;
        } finally {
            if ($gzip) {
                @unlink($raw);
            }
        }

        try {
            $this->validate($path, $type, $contentType);
        } catch (ExchangeApiException $e) {
            @unlink($path);

            throw $e;
        }

        return [
            'path' => $path,
            'size' => filesize($path),
            'content_type' => $contentType,
            'gzip' => $gzip,
            'extension' => $contentType === 'application/json' ? 'json' : 'csv',
        ];
    }

    public static function limitMb(): int
    {
        return (int) config('shop.exchange_1c.max_body_mb', 50);
    }

    public static function limitBytes(): int
    {
        return self::limitMb() * 1024 * 1024;
    }

    /** Тело — во временный файл по частям, с проверкой предела (Content-Length может не быть) */
    private function copyBody(Request $request, int $limit): string
    {
        $path = tempnam(sys_get_temp_dir(), 'x1c');
        $out = fopen($path, 'wb');
        $in = $request->getContent(true);
        $size = 0;

        try {
            while (! feof($in)) {
                $chunk = fread($in, self::CHUNK);

                if ($chunk === false) {
                    break;
                }

                $size += strlen($chunk);

                if ($size > $limit) {
                    throw ExchangeApiException::tooLarge(self::limitMb());
                }

                fwrite($out, $chunk);
            }
        } catch (ExchangeApiException $e) {
            fclose($out);
            @unlink($path);

            throw $e;
        }

        fclose($out);

        if ($size === 0) {
            @unlink($path);

            throw ExchangeApiException::badRequest('empty_body', 'Пустое тело запроса: ожидается файл');
        }

        return $path;
    }

    /** Распаковка gzip с пределом на распакованный размер (защита от «zip-бомбы») */
    private function gunzip(string $gz, int $limit): string
    {
        $head = (string) file_get_contents($gz, false, null, 0, 2);

        if ($head !== "\x1f\x8b") {
            throw ExchangeApiException::badRequest('bad_gzip', 'Content-Encoding: gzip, но тело не в формате gzip');
        }

        $path = tempnam(sys_get_temp_dir(), 'x1c');
        $in = fopen($gz, 'rb');
        $out = fopen($path, 'wb');
        $inflate = inflate_init(ZLIB_ENCODING_GZIP);
        $size = 0;

        try {
            while (! feof($in)) {
                $data = @inflate_add($inflate, (string) fread($in, self::CHUNK), feof($in) ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);

                if ($data === false) {
                    throw ExchangeApiException::badRequest('bad_gzip', 'Повреждённый gzip: распаковать не удалось');
                }

                $size += strlen($data);

                if ($size > $limit) {
                    throw ExchangeApiException::tooLarge(self::limitMb());
                }

                fwrite($out, $data);
            }

            if (inflate_get_status($inflate) !== ZLIB_STREAM_END) {
                throw ExchangeApiException::badRequest('bad_gzip', 'Повреждённый gzip: архив обрывается');
            }
        } catch (ExchangeApiException $e) {
            fclose($out);
            @unlink($path);

            throw $e;
        } finally {
            fclose($in);
        }

        fclose($out);

        return $path;
    }

    private function validate(string $path, ExchangeJobType $type, string $contentType): void
    {
        $content = (string) file_get_contents($path);

        if (str_starts_with($content, "\u{FEFF}")) {
            $content = substr($content, 3);
            file_put_contents($path, $content);
        }

        if (trim($content) === '') {
            throw ExchangeApiException::badRequest('empty_body', 'Файл пустой');
        }

        if (! mb_check_encoding($content, 'UTF-8')) {
            throw ExchangeApiException::badRequest('bad_encoding', 'Файл не в кодировке UTF-8');
        }

        if ($contentType === 'application/json') {
            if (! json_validate($content)) {
                throw ExchangeApiException::badRequest('bad_json', 'Тело не является корректным JSON: '.json_last_error_msg());
            }

            return;
        }

        $firstLine = rtrim(strtok($content, "\n"), "\r");
        $columns = array_map(fn ($c) => strtolower(trim($c, " \t\"")), explode(';', $firstLine));
        $missing = array_values(array_diff($type->requiredColumns(), $columns));

        if ($missing !== []) {
            throw ExchangeApiException::badRequest('missing_columns',
                'Нет обязательных колонок в заголовке: '.implode(', ', $missing).' (разделитель — «;»)');
        }
    }
}
