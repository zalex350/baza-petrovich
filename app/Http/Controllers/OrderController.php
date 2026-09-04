<?php

namespace App\Http\Controllers;

use App\Models\Order;

class OrderController extends Controller
{
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
}