<?php

namespace App\Http\Controllers;

use App\Models\Order;

class OrdersController extends Controller
{
    public function index()
    {
        $orders = Order::where('user_id', auth()->id())
            ->where('status', '!=', Order::STATUS_DRAFT)
            ->where('status', '!=', Order::STATUS_DELETED)
            ->latest()
            ->get();

        return view('orders', compact('orders'));
    }

    public function show($id)
    {
        $order = Order::where('user_id', auth()->id())
            ->where('id', $id)
            ->firstOrFail();

        return view('order_show', compact('order'));
    }
}