<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;

class AdminOrderController extends Controller
{
    public function index()
    {
        $orders = Order::latest()->get();

        return view(
            'admin.orders',
            compact('orders')
        );
    }

    public function show($id)
    {
        $order = Order::findOrFail($id);

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