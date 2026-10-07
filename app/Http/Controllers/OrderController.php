<?php

namespace App\Http\Controllers;

use App\Exceptions\CancelRequestException;
use App\Models\CartItem;
use App\Models\Order;
use App\Services\OrderStatusService;

class OrderController extends Controller
{
    public function index()
    {
        // BR-113, BR-114: клиент видит заказы всей своей организации
        $orders = Order::visibleTo(auth()->user())
            ->with('user')
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->get();

        [$archivedOrders, $activeOrders] = $orders->partition(fn (Order $order) => $order->status->isFinal());

        return view('orders', compact('activeOrders', 'archivedOrders'));
    }

    public function show($id)
    {
        $order = Order::visibleTo(auth()->user())
            ->with(['items.product.mainImage', 'user', 'statusHistory.user'])
            ->where('id', $id)
            ->firstOrFail();

        return view('order_show', compact('order'));
    }

    /**
     * Страница после оформления: номер, доставка и итог с НДС.
     */
    public function placed($id)
    {
        $order = Order::visibleTo(auth()->user())->where('id', $id)->firstOrFail();

        return view('order_placed', compact('order'));
    }

    /**
     * Клиент заказ не удаляет (BR-133) и сам не отменяет: он запрашивает отмену своего
     * заказа с причиной, пока заказ «Новый» или «Подтверждён». Решает администратор.
     */
    public function requestCancel($id, OrderStatusService $statuses)
    {
        $order = Order::visibleTo(auth()->user())->where('id', $id)->firstOrFail();

        // Запросить отмену может только оформивший — не коллега по организации
        if ($order->user_id !== auth()->id()) {
            abort(403);
        }

        $data = request()->validate([
            'cancel_reason' => ['required', 'string', 'max:1000'],
        ], [
            'cancel_reason.required' => 'Укажите причину отмены',
            'cancel_reason.max'      => 'Причина не длиннее 1000 символов',
        ]);

        try {
            $statuses->requestCancel($order, auth()->user(), $data['cancel_reason']);
        } catch (CancelRequestException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect("/order/{$order->id}")
            ->with('success', "Запрос на отмену заказа {$order->number} отправлен менеджеру");
    }

    public function reorder($id)
    {
        $oldOrder = Order::visibleTo(auth()->user())->where('id', $id)->firstOrFail();

        $cart = auth()->user()->cart()->firstOrCreate();

        $warnings = [];

        foreach ($oldOrder->items as $item) {

            $product = $item->product;

            if (!$product || !$product->is_active) {

                $warnings[] = 'недоступен: ' . ($item->product_name ?? $product?->name ?? 'Товар');

                continue;

            }

            if ($product->stock <= 0) {

                $warnings[] = 'недоступен: ' . $product->name;

                continue;

            }

            $requestedQty = (int) $item->quantity;

            $qtyToAdd = min($requestedQty, $product->stock);

            $existingItem = CartItem::where('cart_id', $cart->id)
                ->where('product_id', $product->id)
                ->first();

            if ($existingItem) {

                $available = $product->stock - $existingItem->quantity;

                if ($available <= 0) {

                    $warnings[] = 'недоступен: ' . $product->name;

                    continue;

                }

                $qtyToAdd = min($requestedQty, $available);

                $existingItem->quantity += $qtyToAdd;

                $existingItem->price_at_add = $product->price;

                $existingItem->save();

            } else {

                CartItem::create([
                    'cart_id' => $cart->id,
                    'product_id' => $product->id,
                    'product_name' => $product->display_name ?: $product->name,
                    'quantity' => $qtyToAdd,
                    'price_at_add' => $product->price,
                ]);

            }

            if ($qtyToAdd < $requestedQty) {

                $warnings[] = 'Не удалось добавить полностью: ' . $product->name . ' (запрошено ' . $requestedQty . ', добавлено ' . $qtyToAdd . ')';

            }

        }

        $redirect = redirect('/order')->with('success', 'Товары добавлены в корзину');

        if (!empty($warnings)) {

            $redirect->with('warning', implode('; ', $warnings));

        }

        return $redirect;
    }
}
