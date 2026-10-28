<?php

namespace App\Services;

use App\Models\CartItem;
use App\Models\User;
use App\Services\Vat\VatCalculator;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Сводка корзины для шапки (HeaderComposer) и ответов «В корзину» / «+/−» без перезагрузки,
 * и позиции корзины по товарам — для строк списков (x-product-row: «В корзине · перейти»).
 */
class CartSummary
{
    public function __construct(private readonly VatCalculator $vat) {}

    /**
     * Шапка: count — позиций в корзине, total — их сумма с НДС, vat — НДС в ней; обе цифры —
     * по одним и тем же позициям (все доступные с ценой), независимо от галочки «в заказ».
     * Отмеченные к заказу считаются только в панели «Ваш заказ» на странице корзины.
     * НДС — по позициям (VatCalculator), как в панели.
     *
     * @return array{count: int, total: float, vat: float}
     */
    public function totals(?User $user): array
    {
        if (! $user) {
            return ['count' => 0, 'total' => 0.0, 'vat' => 0.0];
        }

        // Недоступные товары (удалён, скрыт, снят с публикации) в корзине помечены
        // и в сводку не входят — как и в «Итого» на странице корзины
        $rows = DB::table('cart_items')
            ->join('carts', 'carts.id', '=', 'cart_items.cart_id')
            ->join('products', 'products.id', '=', 'cart_items.product_id')
            ->where('carts.user_id', $user->id)
            ->whereNull('products.deleted_at')
            ->where('products.is_active', true)
            ->where('products.is_published', true)
            ->whereNotNull('products.price')
            ->get(['cart_items.quantity', 'cart_items.price_at_add', 'products.vat_rate']);

        $totals = $this->vat->total($rows->map(
            fn ($row) => $this->vat->line($row->price_at_add, (int) $row->quantity, $row->vat_rate)
        ));

        return [
            'count' => $rows->count(),
            'total' => $totals->gross,
            'vat' => $totals->vat,
        ];
    }

    /**
     * Для JSON-ответов: то же, что totals(), плюс готовые подписи шапки.
     *
     * @return array{count: int, total: float, vat: float, total_text: string, title: string, label: string}
     */
    public function forHeader(?User $user): array
    {
        $totals = $this->totals($user);

        return $totals + [
            'total_text' => Money::format($totals['total']),
            'title' => 'с НДС, в т.ч. НДС '.Money::format($totals['vat']),
            'label' => "Корзина: {$totals['count']} поз.",
        ];
    }

    /**
     * Позиции корзины клиента по id товара; не клиенту — пусто.
     *
     * @return Collection<int, CartItem>
     */
    public function items(?User $user): Collection
    {
        if (! $user?->isClient()) {
            return collect();
        }

        return CartItem::query()
            ->whereHas('cart', fn ($q) => $q->where('user_id', $user->id))
            ->whereNotNull('product_id')
            ->get(['id', 'cart_id', 'product_id', 'quantity'])
            ->keyBy('product_id');
    }
}
