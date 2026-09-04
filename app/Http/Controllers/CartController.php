<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;

class CartController extends Controller
{
    public function index()
    {
        $order = Order::where('user_id', auth()->id())
            ->where('status', Order::STATUS_DRAFT)
            ->first();

        return view('order', compact('order'));
    }
    public function add($id)
    {
        $product = Product::findOrFail($id);
        $qty = (int) request('qty', 1);

            if ($qty < 1) {
            $qty = 1;
        }

        if ($product->stock < 1) {
            return back()->with('error', 'Product out of stock');
        }

        $order = Order::firstOrCreate(
            [
                'user_id' => auth()->id(),
                'status' => Order::STATUS_DRAFT,
            ]
        );

        $item = OrderItem::where('order_id', $order->id)
            ->where('product_id', $product->id)
            ->first();

        if ($item) {

            if (($item->quantity + $qty) > $product->stock) {
                return back()->with('error', 'Not enough stock');
            }

            $item->quantity += $qty;
            $item->save();

        } else {

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'quantity' => $qty,
                'price' => $product->price,
            ]);

        }

        return back()->with('success', 'Product added to cart');
    }
    public function update($id)
    {
        $item = OrderItem::findOrFail($id);

        if ($item->order->status !== Order::STATUS_DRAFT) {
            abort(403);
        }

        if ($item->order->user_id !== auth()->id()) {
            abort(403);
        }

        $quantity = (int) request('quantity');

        if ($quantity < 1) {
            $quantity = 1;
        }

        if ($quantity > $item->product->stock) {
            return back()->with('error', 'Not enough stock');
        }

        $item->quantity = $quantity;
        $item->save();

        return back()->with('success', 'Cart updated');
    }
    public function delete($id)
    {
        $item = OrderItem::findOrFail($id);

        if ($item->order->status !== Order::STATUS_DRAFT) {
            abort(403);
        }

        if ($item->order->user_id !== auth()->id()) {
            abort(403);
        }

        $item->delete();

        return back()->with('success', 'Item removed');
    }
    public function checkout()
    {
        $order = Order::where('user_id', auth()->id())
            ->where('status', Order::STATUS_DRAFT)
            ->first();

        if (!$order || !$order->items->count()) {
            return back()->with('error', 'Cart is empty');
        }

        foreach ($order->items as $item) {

            if ($item->quantity > $item->product->stock) {

                return back()->with(
                    'error',
                    'Not enough stock for: ' . $item->product->name
                );

            }

        }

        foreach ($order->items as $item) {

            $product = $item->product;

            $product->stock -= $item->quantity;

            $product->save();

        }

        $order->status = Order::STATUS_NEW;
        $order->save();

        return redirect('/orders')
            ->with('success', 'Order created');
    }
}
