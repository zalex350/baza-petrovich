<?php

namespace App\Models;

use App\Enums\ProductDocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Документ товара (SRS §4.8): PDF на диске фото товаров (ProductImage::disk() — публичный,
 * открывается по прямой ссылке), тип, название, порядок в карточке.
 */
class ProductDocument extends Model
{
    /** Папка на диске: documents/{product_id}/{случайное имя}.pdf */
    public const DIRECTORY = 'documents';

    /** Максимальный размер файла, КБ (20 МБ) */
    public const MAX_KB = 20480;

    protected $fillable = ['product_id', 'title', 'type', 'path', 'original_name', 'size', 'sort_order'];

    protected $casts = [
        'type' => ProductDocumentType::class,
        'size' => 'integer',
        'sort_order' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function url(): string
    {
        return Storage::disk(ProductImage::disk())->url($this->path);
    }

    /** «PDF, 1,2 МБ» / «PDF, 340 КБ» */
    public function sizeLabel(): string
    {
        $kb = $this->size / 1024;

        return 'PDF, '.($kb >= 1024
            ? number_format($kb / 1024, 1, ',', ' ').' МБ'
            : max(1, (int) round($kb)).' КБ');
    }

    public function deleteWithFile(): void
    {
        $this->delete();
        Storage::disk(ProductImage::disk())->delete($this->path);
    }
}
