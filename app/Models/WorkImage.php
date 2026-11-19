<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** Фото объекта «Наши работы» (webp на диске фото товаров, папка works) */
class WorkImage extends Model
{
    protected $fillable = ['work_id', 'path', 'sort_order'];

    public function work()
    {
        return $this->belongsTo(Work::class);
    }

    public function url(): string
    {
        return Storage::disk(ProductImage::disk())->url($this->path);
    }
}
