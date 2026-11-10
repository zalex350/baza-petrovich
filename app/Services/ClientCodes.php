<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductClientCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Коды товаров клиента на витрине (docs/MODULES.md §11): «Ваш код» рядом с артикулом,
 * поиск и «Добавить по вашему коду». Видит только пользователь организации и только
 * её коды; гость и пользователь без организации — нет (ни одного запроса).
 *
 * Без N+1: контроллер списка вызывает load($products) — коды организации для всех
 * товаров страницы одной выборкой; шаблон берёт код через codeFor() / client_code().
 */
class ClientCodes
{
    /** @var array<int, bool> есть ли у организации коды — один запрос на организацию за запрос */
    private array $has = [];

    /** Организация, чьи коды видит пользователь, или null */
    public function organizationId(?User $user = null): ?int
    {
        $user ??= auth()->user();

        return $user?->organization_id ?: null;
    }

    /**
     * Коды организации пользователя для товаров (связь clientCodes — только её коды).
     * Гость и пользователь без организации — без запроса.
     *
     * @param  iterable<Product>  $products
     */
    public function load(iterable $products, ?User $user = null): void
    {
        $organizationId = $this->organizationId($user);

        if ($organizationId === null) {
            return;
        }

        $products = collect(is_array($products) ? $products : iterator_to_array($products, false))
            ->filter()
            ->reject(fn (Product $product) => $product->relationLoaded('clientCodes'));

        if ($products->isEmpty()) {
            return;
        }

        $codes = ProductClientCode::where('organization_id', $organizationId)
            ->whereIn('product_id', $products->pluck('id')->unique())
            ->get()
            ->groupBy('product_id');

        foreach ($products as $product) {
            $product->setRelation('clientCodes', $codes->get($product->id, collect())->values());
        }
    }

    /** Код товара у организации пользователя; без load() — отдельный запрос */
    public function codeFor(?Product $product, ?User $user = null): ?string
    {
        $organizationId = $this->organizationId($user);

        if ($product === null || $organizationId === null) {
            return null;
        }

        if ($product->relationLoaded('clientCodes')) {
            return $product->clientCodes->firstWhere('organization_id', $organizationId)?->code;
        }

        return $product->clientCodes()->where('organization_id', $organizationId)->value('code');
    }

    /** Есть ли у организации пользователя хотя бы один код */
    public function has(?User $user = null): bool
    {
        $organizationId = $this->organizationId($user);

        if ($organizationId === null) {
            return false;
        }

        return $this->has[$organizationId] ??= ProductClientCode::where('organization_id', $organizationId)->exists();
    }

    /**
     * Условие «код организации содержит $word» (без учёта регистра — по code_key) для
     * запроса товаров: добавляется через orWhere внутри группы условий поиска.
     */
    public function whereCodeContains(Builder $query, string $word, int $organizationId): Builder
    {
        return $query->whereExists(fn ($sub) => $sub->selectRaw('1')
            ->from('product_client_codes')
            ->whereColumn('product_client_codes.product_id', 'products.id')
            ->where('product_client_codes.organization_id', $organizationId)
            ->where('product_client_codes.code_key', 'like', '%'.ProductClientCode::key($word).'%'));
    }

    /** Товар по точному коду организации (без учёта регистра) или null */
    public function productIdByCode(string $code, ?User $user = null): ?int
    {
        $organizationId = $this->organizationId($user);

        if ($organizationId === null || trim($code) === '') {
            return null;
        }

        return ProductClientCode::where('organization_id', $organizationId)
            ->where('code_key', ProductClientCode::key($code))
            ->value('product_id');
    }
}
