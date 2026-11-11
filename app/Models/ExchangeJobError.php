<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ошибка одной строки задания обмена: номер строки файла, GUID и артикул — для поиска в 1С.
 */
class ExchangeJobError extends Model
{
    public $timestamps = false;

    /**
     * Длины колонок: guid и sku приходят из файла как есть, сообщение содержит значения ячеек.
     * Пишется массово (insert мимо модели) — обрезает ExchangeResult::error.
     */
    public const LENGTHS = ['guid' => 64, 'sku' => 255, 'message' => 2000];

    protected $fillable = ['exchange_job_id', 'line', 'guid', 'sku', 'message'];

    public function job()
    {
        return $this->belongsTo(ExchangeJob::class, 'exchange_job_id');
    }
}
