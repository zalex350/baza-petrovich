<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Events\OrderPlaced;
use App\Exceptions\CheckoutException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\DeliveryAddress;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductClientCode;
use App\Models\User;
use App\Services\Vat\VatCalculator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Оформление заказа из корзины (SRS §9).
 */
class CheckoutService
{
    public function __construct(
        private readonly CartValidator $validator,
        private readonly OrderStatusService $statuses,
        private readonly VatCalculator $vat,
        private readonly StockReserve $reserve,
    ) {}

    /**
     * Создаёт заказ из корзины пользователя в одной транзакции (BR-123, BR-124, BR-134):
     * блокирует корзину и товары, повторно проверяет отмеченные позиции (selected), при любой
     * проблеме отменяет оформление с полным перечнем причин (BR-135), иначе создаёт заказ
     * из отмеченных, списывает остаток и убирает их из корзины; неотмеченные остаются.
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

            if (! $cart || ! $cart->items()->exists()) {
                throw CheckoutException::emptyCart();
            }

            // В заказ — только отмеченные позиции
            $items = $cart->items()->where('selected', true)->orderBy('id')->get();

            if ($items->isEmpty()) {
                throw CheckoutException::nothingSelected();
            }

            if (! $addressId) {
                throw CheckoutException::addressRequired();
            }

            // Поиск только среди адресов своей организации: чужой id — то же, что несуществующий
            $address = DeliveryAddress::where('organization_id', $user->organization_id)->find($addressId);

            if (! $address) {
                throw CheckoutException::addressForeign();
            }

            return $this->createFromItems($cart, $items, $user, $token, [
                // Снимок адреса: дальнейшие правки адреса заказ не меняют
                'delivery_title' => $address->title,
                'delivery_address' => $address->address,
                'delivery_contact_name' => $address->contact_name,
                'delivery_contact_phone' => $address->contact_phone,
                'comment' => ($comment = trim((string) $comment)) !== '' ? $comment : null,
            ]);
        });
    }

    /**
     * Розничный заказ гостя (shop.retail): корзина по cookie (CurrentCart), контакт, способ получения
     * и оплаты — из формы (CartController::checkout, проверены там). Повтор формы с тем же токеном
     * возвращает уже созданный заказ. Остальное — как у клиента: проверка позиций, снимок, событие.
     *
     * @param  array{name: string, phone: string, email: ?string, delivery_method: string, address: ?string, payment_method: string, comment: ?string}  $customer
     *
     * @throws CheckoutException
     */
    public function placeRetail(Cart $cart, array $customer, ?string $token = null): Order
    {
        return DB::transaction(function () use ($cart, $customer, $token) {
            $cart = Cart::whereKey($cart->id)->lockForUpdate()->first();

            if ($token) {
                $placed = Order::whereNull('user_id')->where('checkout_token', $token)->first();

                if ($placed) {
                    return $placed;
                }
            }

            if (! $cart || ! $cart->items()->exists()) {
                throw CheckoutException::emptyCart();
            }

            $items = $cart->items()->where('selected', true)->orderBy('id')->get();

            if ($items->isEmpty()) {
                throw CheckoutException::nothingSelected();
            }

            $methods = config('shop.checkout.delivery');
            $delivery = $methods[$customer['delivery_method']] ?? null;

            return $this->createFromItems($cart, $items, null, $token, [
                'delivery_title' => $delivery['label'] ?? $customer['delivery_method'],
                // Самовывоз — адрес склада, доставка — адрес покупателя
                'delivery_address' => ($delivery['address'] ?? null) ?: ($customer['address'] ?? null),
                'delivery_contact_name' => $customer['name'],
                'delivery_contact_phone' => $customer['phone'],
                'customer_email' => $customer['email'] ?? null,
                'delivery_method' => $customer['delivery_method'],
                'payment_method' => $customer['payment_method'],
                'public_token' => Str::random(40),
                'comment' => ($comment = trim((string) ($customer['comment'] ?? ''))) !== '' ? $comment : null,
            ]);
        });
    }

    /**
     * Заказ из отмеченных позиций (корзина уже заблокирована): блокировка товаров, повторная проверка,
     * снимок цен и НДС, резерв, удаление позиций из корзины, история и событие OrderPlaced.
     * $user — клиент (B2B) или null (гость розницы).
     *
     * @param  Collection<int, CartItem>  $items
     * @param  array<string, mixed>  $attributes  поля заказа: доставка, контакт, комментарий
     *
     * @throws CheckoutException
     */
    private function createFromItems(Cart $cart, $items, ?User $user, ?string $token, array $attributes): Order
    {
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

        // Снимок НДС: ставка товара на момент оформления, расчёт по позиции, итог — сумма позиций
        $lines = $items->mapWithKeys(fn ($item) => [
            $item->id => $this->vat->line($item->price_at_add, (int) $item->quantity, $products->get($item->product_id)->vat_rate),
        ]);
        $totals = $this->vat->total($lines);

        $order = Order::create($attributes + [
            'number' => Order::nextNumber($placedAt),
            'user_id' => $user?->id,
            'organization_id' => $user?->organization_id,
            'status' => OrderStatus::New,
            'total' => $totals->net,
            'total_without_vat' => $totals->net,
            'total_vat' => $totals->vat,
            'total_with_vat' => $totals->gross,
            'placed_at' => $placedAt,
            'checkout_token' => $token,
        ]);

        // Снимок кодов клиента: код товара у организации на момент оформления
        // (дальнейшие правки кодов заказ не меняют); одна выборка на заказ
        $clientCodes = $user?->organization_id
            ? ProductClientCode::where('organization_id', $user->organization_id)
                ->whereIn('product_id', $items->pluck('product_id'))
                ->pluck('code', 'product_id')
            : collect();

        foreach ($items as $item) {
            $product = $products->get($item->product_id);

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_name' => $product->title(),
                'product_sku' => $product->sku,
                'product_article' => trim((string) $product->article) !== '' ? trim($product->article) : null,
                'product_guid' => $product->guid,
                'client_code' => $clientCodes->get($product->id),
                'quantity' => $item->quantity,
                'unit' => $product->unit,
                'price' => $item->price_at_add,
                'vat_rate' => $product->vat_rate,
                'amount_without_vat' => $lines[$item->id]->net,
                'vat_amount' => $lines[$item->id]->vat,
                'amount_with_vat' => $lines[$item->id]->gross,
            ]);
        }

        // Остаток не списывается: заказ ждёт передачи в 1С (pending) и резервирует его —
        // доступный остаток пересчитан от остатка 1С (StockReserve). Товары заблокированы выше
        $this->reserve->recalculate($products->keys());

        // Неотмеченные позиции остаются в корзине
        $cart->items()->whereKey($items->modelKeys())->delete();

        $this->statuses->recordPlaced($order, $user);

        // Только для нового заказа (повтор формы с тем же токеном выше события не даёт).
        // Слушатели — после коммита (ShouldDispatchAfterCommit), при откате события нет
        OrderPlaced::dispatch($order);

        return $order;
    }
}
