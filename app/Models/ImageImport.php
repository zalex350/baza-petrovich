<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Массовая загрузка фото товаров — запись журнала (App\Services\Images\BulkImport\BulkImages).
 *
 * plan: items — [{entry, name, product_id, order}] по товарам и порядку в галерее; found — число товаров;
 * report: not_found — [{name, reason}], ambiguous — [{name, products: [{id, brand, article, sku}]}],
 * skipped — имена не-картинок, errors — [{name, message}] при обработке.
 */
class ImageImport extends Model
{
    public const SOURCE_ZIP = 'zip';

    public const SOURCE_DIR = 'dir';

    public const SOURCE_CSV = 'csv';

    public const SOURCES = [
        self::SOURCE_ZIP => 'ZIP',
        self::SOURCE_DIR => 'Папка на сервере',
        self::SOURCE_CSV => 'CSV со ссылками',
    ];

    public const MODE_APPEND = 'append';

    public const MODE_REPLACE = 'replace';

    public const MODES = [
        self::MODE_APPEND => 'Добавить к существующим',
        self::MODE_REPLACE => 'Заменить фото товара',
    ];

    public const DRAFT = 'draft';

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const STATUSES = [
        self::DRAFT => 'Предпросмотр',
        self::QUEUED => 'В очереди',
        self::RUNNING => 'Обрабатывается',
        self::DONE => 'Готово',
        self::FAILED => 'Ошибка',
        self::EXPIRED => 'Не применён',
    ];

    /** Ошибок в отчёте, остальные — только счётчиком */
    public const ERRORS_LIMIT = 1000;

    protected $attributes = [
        'status' => self::DRAFT,
    ];

    protected $fillable = [
        'user_id', 'source', 'name', 'path', 'mode', 'status', 'plan', 'report',
        'files_total', 'products_total', 'processed', 'added', 'duplicates', 'failed', 'removed',
        'image_id_floor', 'message', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'plan' => 'array',
        'report' => 'array',
        'files_total' => 'integer',
        'products_total' => 'integer',
        'processed' => 'integer',
        'added' => 'integer',
        'duplicates' => 'integer',
        'failed' => 'integer',
        'removed' => 'integer',
        'image_id_floor' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** Тон бейджа статуса (x-badge) */
    public function statusTone(): string
    {
        return match ($this->status) {
            self::DONE => $this->failed > 0 ? 'warning' : 'success',
            self::FAILED => 'danger',
            self::QUEUED, self::RUNNING => 'info',
            default => 'neutral',
        };
    }

    public function percent(): int
    {
        return $this->files_total > 0 ? (int) floor($this->processed * 100 / $this->files_total) : 0;
    }
}
