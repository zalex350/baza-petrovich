<?php

namespace App\Models;

use App\Support\Html;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    protected $fillable = ['title', 'slug', 'type', 'sort_order', 'body', 'seo_title', 'seo_description', 'is_published', 'published_at', 'image', 'excerpt'];

    /** Услуга (раздел «Услуги», /uslugi/{slug}) */
    public const SERVICE = 'service';

    /** Статья (раздел «Статьи», /stati/{slug}): дата, обложка, анонс */
    public const ARTICLE = 'article';

    /** Тип страницы в админке: null — обычная */
    public const TYPES = ['' => 'Страница', self::SERVICE => 'Услуга', self::ARTICLE => 'Статья'];

    protected $casts = [
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function scopeServices($query)
    {
        return $query->where('type', self::SERVICE)->orderBy('sort_order')->orderBy('title');
    }

    /** Статьи — новые сверху */
    public function scopeArticles($query)
    {
        return $query->where('type', self::ARTICLE)->orderByDesc('published_at')->orderByDesc('id');
    }

    public function url(): string
    {
        if ($this->type === self::SERVICE) {
            return '/uslugi/'.$this->slug;
        }

        if ($this->type === self::ARTICLE) {
            return '/stati/'.$this->slug;
        }

        return self::MENU[$this->slug] ?? '/page/'.$this->slug;
    }

    public function safeBody(): string
    {
        return Html::clean((string) $this->body);
    }

    /** Обложка статьи (путь на диске фото или полный адрес) или null */
    public function imageUrl(): ?string
    {
        if (! $this->image) {
            return null;
        }

        return preg_match('#^(https?:)?//|^/#', $this->image)
            ? $this->image
            : Storage::disk(ProductImage::disk())->url($this->image);
    }

    /** Анонс: заданный или начало текста */
    public function summary(int $limit = 220): string
    {
        $text = $this->excerpt ?: trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $this->body))));

        return Str::limit($text, $limit, '…');
    }
}
