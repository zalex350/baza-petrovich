<?php

namespace App\Http\Controllers;

use App\Models\CartItem;
use App\Models\Order;

class OrderController extends Controller
{
    public function index()
    {
        $activeOrders = Order::where('user_id', auth()->id())
            ->whereIn('status', [Order::STATUS_NEW, Order::STATUS_PROCESSING])
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->get();

        $archivedOrders = Order::where('user_id', auth()->id())
            ->where('status', Order::STATUS_SHIPPED)
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->get();

        return view('orders', compact('activeOrders', 'archivedOrders'));
    }

    public function show($id)
    {
        $order = Order::with('items.product')
            ->where('user_id', auth()->id())
            ->where('id', $id)
            ->firstOrFail();

        return view('order_show', compact('order'));
    }

    public function delete($id)
    {
        $order = Order::findOrFail($id);

        if ($order->user_id !== auth()->id()) {
            abort(403);
        }

        if ($order->status !== Order::STATUS_NEW) {

            return back()->with(
                'error',
                'Удалить можно только новый заказ'
            );

        }
        foreach ($order->items as $item) {

            $product = $item->product;

            // Товар мог быть удалён после оформления — возвращать остаток некуда
            if (! $product) {
                continue;
            }

            $product->stock += $item->quantity;

            $product->save();

        }
        $order->status = Order::STATUS_DELETED;

        $order->save();

        return redirect('/orders')
            ->with('success', 'Заказ удалён');
    }

    public function reorder($id)
    {
        $oldOrder = Order::findOrFail($id);

        if ($oldOrder->user_id !== auth()->id()) {
            abort(403);
        }

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
