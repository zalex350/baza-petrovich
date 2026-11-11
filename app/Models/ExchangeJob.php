<?php

namespace App\Models;

use App\Enums\ExchangeJobStatus;
use App\Enums\ExchangeJobType;
use App\Support\ColumnLength;
use App\Support\ExchangeTime;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Задание обмена с 1С (docs/1C_EXCHANGE_SPEC.md, раздел 8): принятый файл или выдача данных.
 * id — UUID v7 (job_id в API). Файл — в архиве (диск exchange, ГГГГ/ММ/<id>.<ext>),
 * через 90 дней удаляется (exchange:prune), запись журнала остаётся.
 */
class ExchangeJob extends Model
{
    use HasUuids;

    /** Ошибок по строкам в ответе GET /jobs/{id}; полный список — в журнале админки */
    public const API_ERRORS_LIMIT = 1000;

    /** Источник: прислала 1С по API / загрузил администратор в админке */
    public const SOURCE_API = 'api';

    public const SOURCE_MANUAL = 'manual';

    /**
     * Длины строковых колонок (миграции create_exchange_tables, widen_exchange_jobs_content_type):
     * перед записью значения обрезаются — любой MIME или адрес не уронит приём файла.
     * message — TEXT, запас в символах.
     */
    public const LENGTHS = [
        'type' => 30, 'direction' => 10, 'source' => 10, 'mode' => 10, 'status' => 20,
        'exchange_id' => 64, 'ip' => 45, 'file_path' => 255, 'content_type' => 255, 'message' => 2000,
    ];

    protected $attributes = [
        'source' => self::SOURCE_API,
    ];

    protected $fillable = [
        'type', 'direction', 'source', 'mode', 'status', 'exchange_id', 'token_id', 'user_id', 'ip',
        'file_path', 'file_size', 'content_type', 'gzip',
        'rows_total', 'rows_ok', 'rows_skipped', 'rows_error', 'message',
        'received_at', 'started_at', 'finished_at', 'file_pruned_at',
    ];

    protected $casts = [
        'type' => ExchangeJobType::class,
        'status' => ExchangeJobStatus::class,
        'gzip' => 'boolean',
        'rows_total' => 'integer',
        'rows_ok' => 'integer',
        'rows_skipped' => 'integer',
        'rows_error' => 'integer',
        'file_size' => 'integer',
        'received_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'file_pruned_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Сырые значения (enum-касты уже приведены к строкам)
        static::saving(fn (ExchangeJob $job) => $job->attributes = ColumnLength::fit($job->attributes, self::LENGTHS));
    }

    public function errors()
    {
        return $this->hasMany(ExchangeJobError::class)->orderBy('id');
    }

    public function token()
    {
        return $this->belongsTo(ExchangeToken::class);
    }

    /** Автор ручной загрузки (source = manual) */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isManual(): bool
    {
        return $this->source === self::SOURCE_MANUAL;
    }

    public function hasFile(): bool
    {
        return $this->file_path !== null && Storage::disk('exchange')->exists($this->file_path);
    }

    /** Доля ошибочных строк, 0…1 */
    public function errorRate(): float
    {
        return $this->rows_total > 0 ? $this->rows_error / $this->rows_total : 0.0;
    }

    /**
     * Ответ GET /jobs/{id} (раздел 8).
     *
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        $errors = $this->errors()->limit(self::API_ERRORS_LIMIT)->get();

        return [
            'job_id' => $this->id,
            'type' => $this->type->value,
            'source' => $this->source,
            'status' => $this->status->value,
            'received_at' => ExchangeTime::format($this->received_at),
            'finished_at' => ExchangeTime::format($this->finished_at),
            'rows_total' => $this->rows_total,
            'rows_ok' => $this->rows_ok,
            'rows_skipped' => $this->rows_skipped,
            'rows_error' => $this->rows_error,
            'message' => $this->message,
            'errors' => $errors->map(fn (ExchangeJobError $e) => [
                'line' => $e->line, 'guid' => $e->guid, 'sku' => $e->sku, 'message' => $e->message,
            ])->all(),
            'errors_truncated' => $this->rows_error > $errors->count() && $errors->count() === self::API_ERRORS_LIMIT,
        ];
    }
}
