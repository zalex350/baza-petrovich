<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ошибка одной строки задания обмена: номер строки файла, GUID и артикул — для поиска в 1С.
 */
class ExchangeJobError extends Model
{
    public $timestamps = false;

    protected $fillable = ['exchange_job_id', 'line', 'guid', 'sku', 'message'];

    public function job()
    {
        return $this->belongsTo(ExchangeJob::class, 'exchange_job_id');
    }
}
