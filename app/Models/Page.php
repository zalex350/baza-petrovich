<?php

namespace App\Models;

use App\Support\Html;
use Illuminate\Database\Eloquent\Model;

/**
 * Информационная страница (Доставка и оплата, О компании, Контакты…): текст — HTML из
 * редактора (Trix), при выводе очищается App\Support\Html::clean().
 */
class Page extends Model
{
    /** Страницы главного меню и подвала: slug → адрес */
    public const MENU = [
        'delivery' => '/delivery',
        'about' => '/about',
        'contacts' => '/contacts',
    ];

    protected $fillable = ['title', 'slug', 'type', 'sort_order', 'body', 'seo_title', 'seo_description', 'is_published'];

    /** Услуга (раздел «Услуги», /uslugi/{slug}) — иначе обычная страница */
    public const SERVICE = 'service';

    protected $casts = [
        'is_published' => 'boolean',
    ];

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function scopeServices($query)
    {
        return $query->where('type', self::SERVICE)->orderBy('sort_order')->orderBy('title');
    }

    public function url(): string
    {
        if ($this->type === self::SERVICE) {
            return '/uslugi/'.$this->slug;
        }

        return self::MENU[$this->slug] ?? '/page/'.$this->slug;
    }

    public function safeBody(): string
    {
        return Html::clean((string) $this->body);
    }
}
