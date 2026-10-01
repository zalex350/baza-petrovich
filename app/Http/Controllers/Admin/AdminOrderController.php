<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;

class AdminOrderController extends Controller
{
    public function index()
    {
        $orders = Order::with('user')
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->paginate(50);

        return view(
            'admin.orders',
            compact('orders')
        );
    }

    public function show($id)
    {
        $order = Order::with(['user', 'items'])->findOrFail($id);

        return view(
            'admin.order',
            compact('order')
        );
    }

    public function update($id)
    {
        $order = Order::findOrFail($id);

        $order->status = request('status');

        $order->is_paid = request()->boolean('is_paid');

        $order->save();

        return back()->with(
            'success',
            'Order updated'
        );
    }
}