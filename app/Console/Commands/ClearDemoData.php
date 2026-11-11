<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\Images\ProductImageService;
use App\Services\StockReserve;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Удаление демо-данных DemoSeeder: пользователи @demo.test, демо-организации (с адресами),
 * их заказы и корзины, демо-товары DEMO-* и категория «Демо-товары». Каталог и остальные
 * данные не трогаются. Резерв реальных товаров из удаляемых неотменённых заказов
 * снимается — доступный остаток пересчитывается (StockReserve).
 */
class ClearDemoData extends Command
{
    protected $signature = 'demo:clear {--force : Удалить без подтверждения}';

    protected $description = 'Удалить демо-данные тестового стенда (DemoSeeder)';

    public function handle(ProductImageService $images): int
    {
        if (! app()->environment(DemoSeeder::ALLOWED_ENVIRONMENTS)) {
            $this->error('demo:clear работает только на тестовом стенде (APP_ENV=staging). Сейчас: '.app()->environment());

            return self::FAILURE;
        }

        $users = User::where('email', 'like', '%'.DemoSeeder::EMAIL_DOMAIN)->get();
        $organizations = Organization::whereIn('unp', DemoSeeder::ORGANIZATION_UNPS)->get();
        $products = Product::withTrashed()->whereIn('sku', DemoSeeder::PRODUCT_SKUS)->get();
        $category = Category::where('slug', DemoSeeder::CATEGORY_SLUG)->first();

        $orders = Order::query()
            ->where(fn ($q) => $q
                ->whereIn('user_id', $users->pluck('id'))
                ->orWhereIn('organization_id', $organizations->pluck('id'))
                ->orWhere('comment', 'like', DemoSeeder::MARK.'%'))
            ->with('items')
            ->get();

        $this->table(['Что', 'Сколько'], [
            ['Пользователи @demo.test', $users->count()],
            ['Демо-организации (с адресами доставки)', $organizations->count()],
            ['Заказы демо-пользователей и организаций', $orders->count()],
            ['Демо-товары DEMO-*', $products->count()],
            ['Категория «Демо-товары»', $category ? 1 : 0],
        ]);

        if ($users->isEmpty() && $organizations->isEmpty() && $orders->isEmpty() && $products->isEmpty() && ! $category) {
            $this->info('Демо-данных нет.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Удалить эти данные? Отменить будет нельзя', false)) {
            $this->warn('Ничего не удалено.');

            return self::FAILURE;
        }

        $returned = 0;

        DB::transaction(function () use ($orders, $users, $organizations, $products, $category, $images, &$returned) {
            $demoProductIds = $products->pluck('id')->flip();

            $realProductIds = [];

            foreach ($orders as $order) {
                // Резерв реальных товаров: неотменённый заказ его держал (StockReserve)
                foreach ($order->items as $item) {
                    if ($item->product_id && ! isset($demoProductIds[$item->product_id])) {
                        $realProductIds[] = $item->product_id;
                        $returned += $order->status !== OrderStatus::Cancelled ? $item->quantity : 0;
                    }
                }

                // Позиции и история удаляются вместе с заказом (cascade)
                $order->delete();
            }

            // Без удалённых заказов доступный остаток — остаток 1С за вычетом оставшегося резерва
            app(StockReserve::class)->recalculate($realProductIds);

            // Корзины — вместе с пользователями (cascade)
            User::whereIn('id', $users->pluck('id'))->delete();

            // Адреса доставки — вместе с организациями (cascade)
            Organization::whereIn('id', $organizations->pluck('id'))->delete();

            foreach ($products as $product) {
                $product->images()->get()->each(fn ($image) => $images->delete($image));
                $product->forceDelete();
            }

            if ($category && ! Product::withTrashed()->where('category_id', $category->id)->exists()) {
                $category->delete();
            }
        });

        $this->info('Демо-данные удалены.'.($returned ? " На склад возвращено единиц реальных товаров: {$returned}." : ''));

        return self::SUCCESS;
    }
}
