<?php

namespace App\Http\Controllers;

use App\Enums\CartProblemType;
use App\Exceptions\CheckoutException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Services\CartValidator;
use App\Services\CheckoutService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CartController extends Controller
{
    public function __construct(private readonly CartValidator $validator)
    {
    }

    public function index()
    {
        $cart = auth()->user()->cart()->with('items.product')->first();

        $validation = $this->validator->validate($cart);

        // Одноразовый токен формы оформления — защита от двойной отправки
        $checkoutToken = (string) Str::uuid();

        return view('order', compact('cart', 'validation', 'checkoutToken'));
    }

    public function add($id)
    {
        $product = Product::find($id);
        $qty = max(1, (int) request('qty', 1));

        if (! $product) {
            return back()->with('error', 'Товар больше недоступен');
        }

        $cart = auth()->user()->cart()->firstOrCreate();

        // Блокировка строки корзины сериализует параллельные добавления одного товара:
        // вторая вставка не упирается в unique(cart_id, product_id), а увеличивает количество.
        $error = DB::transaction(function () use ($cart, $product, $qty) {
            Cart::whereKey($cart->id)->lockForUpdate()->first();

            $item = CartItem::where('cart_id', $cart->id)
                ->where('product_id', $product->id)
                ->first();

            $inCart = $item?->quantity ?? 0;

            // Проверяется итоговое количество — и для новой позиции, и для существующей
            $problem = $this->validator->check($product, $inCart + $qty)[0] ?? null;

            if ($problem) {
                return $problem->type === CartProblemType::InsufficientStock
                    ? "Недостаточно товара на складе: доступно {$problem->available}"
                        . ($inCart ? ", в корзине уже {$inCart}" : '')
                    : $problem->message;
            }

            if ($item) {
                $item->quantity += $qty;
                $item->save();
            } else {
                CartItem::create([
                    'cart_id'      => $cart->id,
                    'product_id'   => $product->id,
                    'product_name' => $product->display_name ?: $product->name,
                    'quantity'     => $qty,
                    'price_at_add' => $product->price,
                ]);
            }

            return null;
        });

        if ($error) {
            return back()->with('error', $error);
        }

        return back()->with('success', 'Товар добавлен в корзину');
    }

    public function update($id)
    {
        $item = $this->ownItem($id);

        $quantity = max(1, (int) request('quantity'));

        // Цена здесь не сравнивается: её изменение не мешает менять количество
        $problem = $this->validator->check($item->product, $quantity, null, $item->title())[0] ?? null;

        if ($problem) {
            return back()->with('error', $problem->type === CartProblemType::InsufficientStock
                ? "Недостаточно товара на складе: доступно {$problem->available}"
                : $problem->fullMessage());
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

    /**
     * BR-125: пользователь соглашается с изменившимися ценами — price_at_add
     * приравнивается к текущей цене товара.
     */
    public function acceptPrices()
    {
        $cart = auth()->user()->cart()->with('items.product')->first();

        $validation = $this->validator->validate($cart);

        $updated = 0;

        foreach ($cart?->items ?? [] as $item) {
            $change = $validation->priceChange($item);

            if ($change) {
                $item->price_at_add = $change->newPrice;
                $item->save();
                $updated++;
            }
        }

        return redirect('/order')->with(
            'success',
            $updated ? 'Новые цены приняты' : 'Цены в корзине актуальны'
        );
    }

    public function checkout(CheckoutService $checkout)
    {
        $token = request('checkout_token');
        $token = is_string($token) && $token !== '' && strlen($token) <= 64 ? $token : null;

        try {
            $checkout->place(auth()->user(), $token);
        } catch (CheckoutException $e) {
            $redirect = redirect('/order')->with('error', $e->getMessage());

            return $e->problems ? $redirect->withErrors($e->problems) : $redirect;
        }

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
