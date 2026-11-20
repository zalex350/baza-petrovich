<?php

namespace App\Observers;

use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Models\Redirect;
use App\Models\Work;
use Illuminate\Database\Eloquent\Model;

/**
 * Сменился адрес товара, раздела, страницы, статьи или работы — старый адрес запоминается
 * редиректом 301 на новый (App\Models\Redirect): ссылки из поиска, рекламы и закладок не ломаются.
 */
class UrlChangeObserver
{
    public function updated(Model $model): void
    {
        if (! $model->wasChanged(['slug', 'type'])) {
            return;
        }

        $old = $this->url($model, (string) $model->getOriginal('slug'), $model->getOriginal('type'));
        $new = $this->url($model, (string) $model->slug, $model->type ?? null);

        if ($old && $new && $old !== $new) {
            Redirect::remember($old, $new, 'Смена адреса: '.class_basename($model).' #'.$model->getKey());
        }
    }

    private function url(Model $model, string $slug, ?string $type): ?string
    {
        if ($slug === '') {
            return null;
        }

        return match (true) {
            $model instanceof Product => '/product/'.$slug,
            $model instanceof Category => '/catalog/'.$slug,
            $model instanceof Work => '/nashi-raboty/'.$slug,
            $model instanceof Page => (new Page(['slug' => $slug, 'type' => $type]))->url(),
            default => null,
        };
    }
}
