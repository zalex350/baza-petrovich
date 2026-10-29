<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Баннер слайдера на главной. Картинки — webp на диске фото (App\Services\Images\WebpImage);
 * мобильной нет — на телефоне показывается десктопная.
 */
class Banner extends Model
{
    protected $fillable = ['title', 'image_desktop', 'image_mobile', 'url', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeShown($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    public function desktopUrl(): string
    {
        return Storage::disk(ProductImage::disk())->url($this->image_desktop);
    }

    public function mobileUrl(): string
    {
        return $this->image_mobile ? Storage::disk(ProductImage::disk())->url($this->image_mobile) : $this->desktopUrl();
    }
}
