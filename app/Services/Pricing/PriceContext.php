<?php

namespace App\Services\Pricing;

use App\Models\Organization;
use App\Models\PriceType;
use App\Models\Product;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Какая цена у товара для текущего покупателя.
 *
 * Розница: цена по умолчанию — со скидкой, если она есть (products.sale_price, App\Services\Pricing\Discounts);
 * прежняя цена — Product::old_price. К особым типам цен скидки не применяются.
 *
 * Тип цены — «Применяемая цена» организации пользователя (organizations.price_type_id); не задан,
 * скрыт или удалён — тип по умолчанию. Цены нет у товара в этом типе — цена по умолчанию
 * (products.price), нет и её — «Цена уточняется» (null). В админке, в очереди и в консоли — всегда
 * цена по умолчанию (нет покупателя); выгрузка для конкретного клиента — using().
 *
 * Где применяется:
 *  - Product::price (аксессор) — цена для показа, корзины, заказа: витрина, поиск, корзина, заказ;
 *  - списки — scope Product::withEffectivePrice (join, без запроса на товар);
 *  - сортировка и фильтр по цене — expression() (тот же join).
 *
 * Тип цены по категориям (позже): typeIdFor(Product) получает товар — правило «категория → тип»
 * добавляется здесь, а expression() становится CASE по category_id; места применения не меняются.
 */
class PriceContext
{
    /** Псевдоним join цен в запросах */
    public const ALIAS = 'eff_price';

    /** @var Collection<int, PriceType>|null */
    private ?Collection $types = null;

    /** Принудительный тип (using): выгрузка для клиента, тесты */
    private ?int $forced = null;

    /** @return Collection<int, PriceType> id => тип */
    public function types(): Collection
    {
        return $this->types ??= PriceType::query()->ordered()->get()->keyBy('id');
    }

    /** Сбросить кэш справочника (после изменений в админке, обмене) */
    public function flush(): void
    {
        $this->types = null;
    }

    public function defaultType(): ?PriceType
    {
        return $this->types()->firstWhere('is_default', true) ?? $this->types()->first();
    }

    public function defaultId(): ?int
    {
        return $this->defaultType()?->id;
    }

    /**
     * Тип цены организации; null — по умолчанию (не задан, скрыт, удалён). Без «Применяемой цены»
     * справочник не читается.
     */
    public function typeIdForOrganization(?Organization $organization): ?int
    {
        if (! $organization?->price_type_id) {
            return null;
        }

        $type = $this->types()->get($organization->price_type_id);

        return $type && ! $type->is_hidden && ! $type->is_default ? $type->id : null;
    }

    /** Тип цены текущего покупателя; null — по умолчанию (гость, админка, API, очередь, консоль) */
    public function typeId(): ?int
    {
        if ($this->forced !== null) {
            return $this->forced ?: null;
        }

        $request = request();
        $user = auth()->user();

        if (! $user || $request->is('admin', 'admin/*', 'api/*')) {
            return null;
        }

        // На один запрос — один раз
        $key = 'price_type_id:'.$user->getAuthIdentifier();
        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, $this->typeIdForOrganization($user->organization));
        }

        return $request->attributes->get($key);
    }

    /**
     * Тип цены для товара. Сейчас — тип покупателя; сюда добавится правило по категории товара.
     */
    public function typeIdFor(?Product $product = null): ?int
    {
        return $this->typeId();
    }

    /** Действует не цена по умолчанию */
    public function isSpecial(?int $typeId): bool
    {
        return $typeId !== null && $typeId !== $this->defaultId();
    }

    /**
     * Выполнить с заданным типом цены (выгрузка ассортимента для клиента и т.п.).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function using(?int $typeId, callable $callback): mixed
    {
        $previous = $this->forced;
        // 0 — «по умолчанию» (null в forced означал бы «не задано»)
        $this->forced = $typeId ?? 0;

        try {
            return $callback();
        } finally {
            $this->forced = $previous;
        }
    }

    /**
     * Цена товара для покупателя (аксессор Product::price). $default — products.price.
     */
    public function priceOf(Product $product, mixed $default): mixed
    {
        if (! $this->isSpecial($this->typeIdFor($product))) {
            // Розничная цена со скидкой (Discounts → products.sale_price)
            $sale = $product->getAttributes()['sale_price'] ?? null;

            return $sale !== null && $default !== null ? $sale : $default;
        }

        return $product->clientPrice?->price ?? $default;
    }

    /**
     * SQL-выражение цены покупателя для сортировки и фильтров; при особом типе добавляет к запросу
     * left join цен этого типа (один раз).
     */
    public function expression(BuilderContract $query, ?int $typeId = null): string
    {
        $typeId ??= $this->typeId();

        if (! $this->isSpecial($typeId)) {
            return 'COALESCE(products.sale_price, products.price)';
        }

        $base = method_exists($query, 'getQuery') ? $query->getQuery() : $query;
        $joined = collect($base->joins ?? [])->contains(fn (JoinClause $join) => $join->table === 'product_prices as '.self::ALIAS);

        if (! $joined) {
            $query->leftJoin('product_prices as '.self::ALIAS, function (JoinClause $join) use ($typeId) {
                $join->on(self::ALIAS.'.product_id', '=', 'products.id')->where(self::ALIAS.'.price_type_id', '=', $typeId);
            });
        }

        return 'COALESCE('.self::ALIAS.'.price, products.price)';
    }

    /**
     * Выбрать цену покупателя колонкой price (списки товаров): аксессор её уже не пересчитывает.
     */
    public function select(BuilderContract $query, ?int $typeId = null): void
    {
        $typeId ??= $this->typeId();

        if (! $this->isSpecial($typeId)) {
            return;
        }

        $base = method_exists($query, 'getQuery') ? $query->getQuery() : $query;
        if ($base->columns === null) {
            $query->select('products.*');
        }

        $query->addSelect([
            DB::raw($this->expression($query, $typeId).' as price'),
            DB::raw('1 as '.Product::PRICE_RESOLVED),
        ]);
    }
}
