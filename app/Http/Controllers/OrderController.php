<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;

class OrderController extends Controller
{
    public function index()
    {
        $activeOrders = Order::where('user_id', auth()->id())
            ->whereIn('status', [Order::STATUS_NEW, Order::STATUS_PROCESSING])
            ->latest()
            ->get();

        $archivedOrders = Order::where('user_id', auth()->id())
            ->where('status', Order::STATUS_SHIPPED)
            ->latest()
            ->get();

        return view('orders', compact('activeOrders', 'archivedOrders'));
    }

    public function show($id)
    {
        $order = Order::where('user_id', auth()->id())
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
                'Only new orders can be deleted'
            );

        }
        foreach ($order->items as $item) {

            $product = $item->product;

            $product->stock += $item->quantity;

            $product->save();

        }
        $order->status = Order::STATUS_DELETED;

        $order->save();

        return redirect('/orders')
            ->with('success', 'Order deleted');
    }

    public function reorder($id)
    {
        $oldOrder = Order::findOrFail($id);

        if ($oldOrder->user_id !== auth()->id()) {
            abort(403);
        }

        $draft = Order::firstOrCreate([
            'user_id' => auth()->id(),
            'status' => Order::STATUS_DRAFT,
        ]);

        $warnings = [];

        foreach ($oldOrder->items as $item) {

            $product = $item->product;

            if (!$product || !$product->is_active) {

                $warnings[] = 'недоступен: ' . ($product?->name ?? 'Товар');

                continue;

            }

            if ($product->stock <= 0) {

                $warnings[] = 'недоступен: ' . $product->name;

                continue;

            }

            $requestedQty = (int) $item->quantity;

            $qtyToAdd = min($requestedQty, $product->stock);

            $existingItem = OrderItem::where('order_id', $draft->id)
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

                $existingItem->price = $product->price;

                $existingItem->save();

            } else {

                OrderItem::create([
                    'order_id' => $draft->id,
                    'product_id' => $product->id,
                    'quantity' => $qtyToAdd,
                    'price' => $product->price,
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