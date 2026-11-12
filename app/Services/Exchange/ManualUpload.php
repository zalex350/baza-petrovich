<?php

namespace App\Services\Exchange;

use App\Enums\ExchangeJobType;
use App\Models\ExchangeJob;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ручная загрузка файла обмена в админке: файл → черновик (диск exchange, uploads/<токен>.csv|xlsx;
 * CSV приводится к UTF-8 без BOM, cp1251 из Excel перекодируется) → предпросмотр (plan обработчика,
 * база не меняется) → «Применить»: задание в общем журнале (source = manual, автор), обработка
 * сразу. Черновики старше суток удаляет exchange:prune. Цены — ManualPricesUpload, номенклатура —
 * ManualCatalogUpload.
 */
abstract class ManualUpload
{
    public const DIR = 'uploads';

    /** Строк в таблицах предпросмотра */
    public const PREVIEW_LIMIT = 200;

    public function __construct(protected readonly ExchangeJobs $jobs) {}

    /** Тип заданий этой загрузки */
    abstract protected function type(): ExchangeJobType;

    /** Сохранить файл черновиком; токен — для предпросмотра и применения */
    public function store(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
        $token = (string) Str::uuid();
        $path = self::DIR."/{$token}.{$extension}";

        if ($extension === 'csv') {
            $content = (string) file_get_contents($file->getRealPath());
            $content = str_starts_with($content, "\u{FEFF}") ? substr($content, 3) : $content;

            if (! mb_check_encoding($content, 'UTF-8')) {
                $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
            }

            Storage::disk('exchange')->put($path, $content);
        } else {
            Storage::disk('exchange')->putFileAs(self::DIR, $file, "{$token}.xlsx");
        }

        return $token;
    }

    public function path(string $token): ?string
    {
        foreach (['csv', 'xlsx'] as $extension) {
            $path = self::DIR."/{$token}.{$extension}";

            if (preg_match('/^[0-9a-f-]{36}$/', $token) && Storage::disk('exchange')->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /** Применить: задание в журнале (вручную, автор), обработка сразу; черновик уходит в архив */
    public function apply(string $path, string $mode, User $user, ?string $ip): ExchangeJob
    {
        $disk = Storage::disk('exchange');
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return $this->jobs->accept([
            'path' => $disk->path($path),
            'size' => $disk->size($path),
            'content_type' => $extension === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv',
            'gzip' => false,
            'extension' => $extension,
        ], $this->type(), $mode, null, null, $ip, $user);
    }

    public function discard(string $path): void
    {
        Storage::disk('exchange')->delete($path);
    }

    /** Черновики старше суток (не применённые и не отменённые) — всех ручных загрузок */
    public function pruneDrafts(): int
    {
        $disk = Storage::disk('exchange');
        $count = 0;

        foreach ($disk->files(self::DIR) as $file) {
            if ($disk->lastModified($file) < now()->subDay()->getTimestamp()) {
                $disk->delete($file);
                $count++;
            }
        }

        return $count;
    }
}
