<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class CartController extends Controller
{
    public function index()
    {
        $cart = auth()->user()->cart()->with('items.product')->first();

        return view('order', compact('cart'));
    }

    public function add($id)
    {
        $product = Product::findOrFail($id);
        $qty = (int) request('qty', 1);

        if ($qty < 1) {
            $qty = 1;
        }

        if ($product->stock < 1) {
            return back()->with('error', 'Товара нет в наличии');
        }

        $cart = auth()->user()->cart()->firstOrCreate();

        // Блокировка строки корзины сериализует параллельные добавления одного товара:
        // вторая вставка не упирается в unique(cart_id, product_id), а увеличивает количество.
        $added = DB::transaction(function () use ($cart, $product, $qty) {
            Cart::whereKey($cart->id)->lockForUpdate()->first();

            $item = CartItem::where('cart_id', $cart->id)
                ->where('product_id', $product->id)
                ->first();

            if (! $item) {
                CartItem::create([
                    'cart_id'      => $cart->id,
                    'product_id'   => $product->id,
                    'quantity'     => $qty,
                    'price_at_add' => $product->price,
                ]);

                return true;
            }

            if (($item->quantity + $qty) > $product->stock) {
                return false;
            }

            $item->quantity += $qty;
            $item->save();

            return true;
        });

        if (! $added) {
            return back()->with('error', 'Недостаточно товара на складе');
        }

        return back()->with('success', 'Товар добавлен в корзину');
    }

    public function update($id)
    {
        $item = $this->ownItem($id);

        if (! $item->isAvailable()) {
            return back()->with('error', 'Товар больше недоступен. Удалите его из корзины.');
        }

        $quantity = (int) request('quantity');

        if ($quantity < 1) {
            $quantity = 1;
        }

        if ($quantity > $item->product->stock) {
            return back()->with('error', 'Недостаточно товара на складе');
        }

        $item->quantity = $quantity;
        $item->save();

        return back()->with('success', 'Корзина обновлена');
    }

    public function delete($id)
    {
        $this->ownItem($id)->delete();

        return back()->with('success', 'Товар удалён из корзины');
    }

    public function checkout()
    {
        $user = auth()->user();

        // BR-114: заказ клиента оформляется на его организацию
        if ($user->isClient() && ! $user->organization_id) {
            return back()->with('error', 'Пользователь не привязан к организации');
        }

        $cart = $user->cart()->with('items.product')->first();

        if (! $cart || $cart->items->isEmpty()) {
            return back()->with('error', 'Корзина пуста');
        }

        foreach ($cart->items as $item) {

            if (! $item->isAvailable()) {
                return back()->with(
                    'error',
                    'В корзине есть недоступные товары. Удалите их, чтобы оформить заказ.'
                );
            }

            if ($item->quantity > $item->product->stock) {
                return back()->with(
                    'error',
                    'Недостаточно товара на складе: ' . ($item->product->display_name ?: $item->product->name)
                );
            }

        }

        DB::transaction(function () use ($user, $cart) {
            $placedAt = now();

            $order = Order::create([
                'number'          => Order::nextNumber($placedAt),
                'user_id'         => $user->id,
                'organization_id' => $user->organization_id,
                'status'          => Order::STATUS_NEW,
                'total'           => $cart->items->sum('subtotal'),
                'placed_at'       => $placedAt,
            ]);

            foreach ($cart->items as $item) {
                $product = $item->product;

                OrderItem::create([
                    'order_id'     => $order->id,
                    'product_id'   => $product->id,
                    'product_name' => $product->display_name ?: $product->name,
                    'product_sku'  => $product->sku,
                    'quantity'     => $item->quantity,
                    'price'        => $item->price_at_add,
                    'vat_rate'     => $product->vat_rate,
                ]);

                $product->stock -= $item->quantity;
                $product->save();
            }

            $cart->items()->delete();
        });

        return redirect('/orders')
            ->with('success', 'Заказ оформлен');
    }

    /**
     * Позиция корзины текущего пользователя; чужая — 403.
     */
    private function ownItem($id): CartItem
    {
        $item = CartItem::with(['cart', 'product'])->findOrFail($id);

        if ($item->cart->user_id !== auth()->id()) {
            abort(403);
        }

        return $item;
    }
}
