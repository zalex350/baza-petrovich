<?php

namespace App\Observers;

use App\Models\Category;
use App\Models\Product;
use App\Services\CatalogMenu;
use Illuminate\Database\Eloquent\Model;

/**
 * Сбрасывает кэш дерева меню каталога при изменении категорий и товаров.
 * Для товаров — только если изменилось то, что влияет на меню
 * (изменение остатка при оформлении заказа кэш не трогает).
 * Массовые Product::query()->update() observer не видят — после них
 * вызывайте CatalogMenu::flush() вручную (сделано в командах импорта).
 */
class CatalogMenuObserver
{
    private const PRODUCT_FIELDS = ['is_active', 'is_published', 'category_id', 'deleted_at'];

    public function created(Model $model): void
    {
        CatalogMenu::flush();
    }

    /**
     * wasRecentlyCreated здесь не годится: у модели, созданной в этом же
     * запросе, он остаётся true и при последующих сохранениях.
     */
    public function updated(Model $model): void
    {
        if ($model instanceof Category
            || ($model instanceof Product && $model->wasChanged(self::PRODUCT_FIELDS))) {
            CatalogMenu::flush();
        }
    }

    public function deleted(Model $model): void
    {
        CatalogMenu::flush();
    }

    public function restored(Model $model): void
    {
        CatalogMenu::flush();
    }

    public function forceDeleted(Model $model): void
    {
        CatalogMenu::flush();
    }
}
