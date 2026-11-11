<?php

namespace App\Models;

use App\Enums\ExchangeJobStatus;
use App\Enums\ExchangeJobType;
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

    protected $fillable = [
        'type', 'direction', 'mode', 'status', 'exchange_id', 'token_id', 'ip',
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

    public function errors()
    {
        return $this->hasMany(ExchangeJobError::class)->orderBy('id');
    }

    public function token()
    {
        return $this->belongsTo(ExchangeToken::class);
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
            'status' => $this->status->value,
            'received_at' => $this->received_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
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
