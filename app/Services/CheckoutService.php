<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\CheckoutException;
use App\Models\Cart;
use App\Models\DeliveryAddress;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Оформление заказа из корзины (SRS §9).
 */
class CheckoutService
{
    public function __construct(
        private readonly CartValidator $validator,
        private readonly OrderStatusService $statuses,
    ) {
    }

    /**
     * Создаёт заказ из корзины пользователя в одной транзакции (BR-123, BR-124, BR-134):
     * блокирует корзину и товары, повторно проверяет все позиции, при любой проблеме
     * отменяет оформление с полным перечнем причин (BR-135), иначе создаёт заказ,
     * списывает остаток и очищает корзину.
     *
     * $token — одноразовый токен формы: повторная отправка той же формы возвращает
     * уже созданный заказ, а не создаёт второй.
     *
     * $addressId — выбранный адрес доставки: обязателен (BR-128) и должен принадлежать
     * организации пользователя (BR-129); в заказ копируется снимком.
     *
     * @throws CheckoutException
     */
    public function place(User $user, ?string $token = null, ?int $addressId = null, ?string $comment = null): Order
    {
        // BR-121: оформляет только клиент, и только на свою организацию
        if (! $user->isClient()) {
            throw new CheckoutException('Оформлять заказы могут только клиенты');
        }

        if (! $user->organization_id) {
            throw new CheckoutException('Пользователь не привязан к организации');
        }

        return DB::transaction(function () use ($user, $token, $addressId, $comment) {
            // Блокировка корзины ставит параллельные отправки одного пользователя в очередь
            $cart = Cart::where('user_id', $user->id)->lockForUpdate()->first();

            if ($token) {
                $placed = Order::where('user_id', $user->id)->where('checkout_token', $token)->first();

                if ($placed) {
                    return $placed;
                }
            }

            $items = $cart ? $cart->items()->orderBy('id')->get() : collect();

            if ($items->isEmpty()) {
                throw CheckoutException::emptyCart();
            }

            if (! $addressId) {
                throw CheckoutException::addressRequired();
            }

            // Поиск только среди адресов своей организации: чужой id — то же, что несуществующий
            $address = DeliveryAddress::where('organization_id', $user->organization_id)->find($addressId);

            if (! $address) {
                throw CheckoutException::addressForeign();
            }

            // Блокировка в порядке id — параллельные оформления с общими товарами не взаимоблокируются.
            // withTrashed: удалённый товар должен попасть в перечень проблем, а не исчезнуть из выборки.
            $products = Product::withTrashed()
                ->whereIn('id', $items->pluck('product_id')->filter())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Валидатор должен видеть именно заблокированные, только что прочитанные товары
            $items->each(fn ($item) => $item->setRelation('product', $products->get($item->product_id)));

            $validation = $this->validator->validate($cart->setRelation('items', $items));

            if (! $validation->isReady()) {
                throw CheckoutException::hasProblems($validation->messages());
            }

            $placedAt = now();

            $order = Order::create([
                'number'          => Order::nextNumber($placedAt),
                'user_id'         => $user->id,
                'organization_id' => $user->organization_id,
                // Снимок адреса: дальнейшие правки адреса заказ не меняют
                'delivery_title'         => $address->title,
                'delivery_address'       => $address->address,
                'delivery_contact_name'  => $address->contact_name,
                'delivery_contact_phone' => $address->contact_phone,
                'comment'         => ($comment = trim((string) $comment)) !== '' ? $comment : null,
                'status'          => OrderStatus::New,
                'total'           => $items->sum('subtotal'),
                'placed_at'       => $placedAt,
                'checkout_token'  => $token,
            ]);

            foreach ($items as $item) {
                $product = $products->get($item->product_id);

                OrderItem::create([
                    'order_id'     => $order->id,
                    'product_id'   => $product->id,
                    'product_name' => $product->display_name ?: $product->name,
                    'product_sku'  => $product->sku,
                    'quantity'     => $item->quantity,
                    'price'        => $item->price_at_add,
                    'vat_rate'     => $product->vat_rate,
                ]);

                // Остаток прочитан под блокировкой и проверен валидатором — в минус не уйдёт
                $product->stock -= $item->quantity;
                $product->save();
            }

            $cart->items()->delete();

            $this->statuses->recordPlaced($order, $user);

            return $order;
        });
    }
}
