<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidStatusTransition;
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
            ->with(['items.product', 'user', 'statusHistory'])
            ->where('id', $id)
            ->firstOrFail();

        return view('order_show', compact('order'));
    }

    /**
     * Клиент заказ не удаляет (BR-133), но может отменить свой заказ, пока он «Новый».
     */
    public function cancel($id, OrderStatusService $statuses)
    {
        $order = Order::visibleTo(auth()->user())->where('id', $id)->firstOrFail();

        // Отменить можно только заказ, который оформил сам, — не заказ коллеги по организации
        if ($order->user_id !== auth()->id()) {
            abort(403);
        }

        if ($order->status !== OrderStatus::New) {
            return back()->with('error', 'Отменить можно только заказ в статусе «Новый»');
        }

        try {
            $statuses->change($order, OrderStatus::Cancelled, auth()->user(), 'Отменён клиентом');
        } catch (InvalidStatusTransition $e) {
            // Статус успел измениться между проверкой и блокировкой заказа
            return back()->with('error', 'Отменить можно только заказ в статусе «Новый»');
        }

        return redirect('/orders')
            ->with('success', "Заказ {$order->number} отменён");
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
