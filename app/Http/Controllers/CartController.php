<?php

namespace App\Http\Controllers;

use App\Enums\CartProblemType;
use App\Exceptions\CheckoutException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Services\CartSummary;
use App\Services\CartValidator;
use App\Services\CheckoutService;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class CartController extends Controller
{
    public function __construct(
        private readonly CartValidator $validator,
        private readonly CartSummary $summary,
    ) {}

    public function index()
    {
        $cart = auth()->user()->cart()->with('items.product.mainImage')->first();

        $validation = $this->validator->validate($cart);

        // Одноразовый токен формы оформления — защита от двойной отправки
        $checkoutToken = (string) Str::uuid();

        // Адреса доставки организации для выбора при оформлении (BR-128)
        $addresses = auth()->user()->organization?->deliveryAddresses ?? collect();

        return view('order', compact('cart', 'validation', 'checkoutToken', 'addresses'));
    }

    /**
     * Добавление из карточки и из списков товаров. Обычная форма возвращает на ту же
     * страницу (с фильтрами и пагинацией); запрос с Accept: application/json (cart-actions.js)
     * получает JSON: сообщение, сводку для шапки и новый блок покупки строки списка.
     */
    public function add($id)
    {
        // BR-121: оформлять заказы могут только клиенты — корзина администратору не нужна
        if (! auth()->user()->isClient()) {
            return $this->addResult('Оформлять заказы могут только клиенты');
        }

        $product = Product::find($id);
        $qty = max(1, (int) request('qty', 1));

        if (! $product) {
            return $this->addResult(CartProblemType::Missing->title());
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
                    ? $problem->title().": доступно {$problem->available}"
                        .($inCart ? ", в корзине уже {$inCart}" : '')
                    : $problem->text();
            }

            if ($item) {
                $item->quantity += $qty;
                $item->save();
            } else {
                CartItem::create([
                    'cart_id' => $cart->id,
                    'product_id' => $product->id,
                    'product_name' => $product->display_name ?: $product->name,
                    'quantity' => $qty,
                    'price_at_add' => $product->price,
                ]);
            }

            return null;
        });

        return $this->addResult($error, $product);
    }

    /**
     * Ответ на добавление в корзину: $error = null — успех.
     */
    private function addResult(?string $error, ?Product $product = null)
    {
        if (request()->expectsJson()) {
            $data = ['ok' => $error === null, 'message' => $error ?? 'Товар добавлен в корзину'];

            if ($error === null) {
                $data['cart'] = $this->summary->forHeader(auth()->user());
                $data['toast'] = Blade::render('<x-alert type="success">{{ $message }}</x-alert>', ['message' => $data['message']]);
                // Строка списка переходит в состояние «В корзине»: количество и «перейти»
                $data['html'] = Blade::render('<x-product-row.buy :product="$product" :in-cart="$item" />', [
                    'product' => $product,
                    'item' => $this->summary->items(auth()->user())->get($product->id),
                ]);
            }

            return response()->json($data, $error === null ? 200 : 422, [], JSON_UNESCAPED_UNICODE);
        }

        return $error
            ? back()->with('error', $error)
            : back()->with('success', 'Товар добавлен в корзину');
    }

    public function update($id)
    {
        $item = $this->ownItem($id);

        $quantity = max(1, (int) request('quantity'));

        // Цена здесь не сравнивается: её изменение не мешает менять количество
        $problem = $this->validator->check($item->product, $quantity, null, $item->title())[0] ?? null;

        if ($problem) {
            $error = $problem->type === CartProblemType::InsufficientStock
                ? $problem->title().": доступно {$problem->available}"
                : $problem->fullMessage();

            return $this->updateResult($error, $item);
        }

        $item->quantity = $quantity;
        $item->save();

        return $this->updateResult(null, $item);
    }

    /**
     * Ответ на изменение количества: обычная форма — назад с сообщением; JSON (+/− в списках) —
     * сообщение, количество в корзине (после ошибки — прежнее) и сводка для шапки.
     */
    private function updateResult(?string $error, CartItem $item)
    {
        if (request()->expectsJson()) {
            return response()->json([
                'ok' => $error === null,
                'message' => $error ?? 'Корзина обновлена',
                'quantity' => $item->quantity,
                'cart' => $this->summary->forHeader(auth()->user()),
            ], $error === null ? 200 : 422, [], JSON_UNESCAPED_UNICODE);
        }

        return $error
            ? back()->with('error', $error)
            : back()->with('success', 'Корзина обновлена');
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

        $validator = Validator::make(request()->all(), [
            'delivery_address_id' => ['nullable', 'integer'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ], [
            'delivery_address_id.integer' => CheckoutException::ADDRESS_REQUIRED,
            'comment.max' => 'Комментарий к заказу не длиннее 1000 символов',
        ]);

        if ($validator->fails()) {
            return redirect('/order')->withErrors($validator)->withInput();
        }

        $data = $validator->validated();

        try {
            $order = $checkout->place(
                auth()->user(),
                $token,
                isset($data['delivery_address_id']) ? (int) $data['delivery_address_id'] : null,
                $data['comment'] ?? null,
            );
        } catch (CheckoutException $e) {
            // withInput: выбранный адрес и комментарий не теряются
            $redirect = redirect('/order')->with('error', $e->getMessage())->withInput();

            return $e->problems ? $redirect->withErrors($e->problems) : $redirect;
        } catch (Throwable $e) {
            // Транзакция уже откатилась: заказа нет, корзина и остатки не тронуты
            report($e);

            return redirect('/order')->with('error', CheckoutException::CREATION_FAILED)->withInput();
        }

        return redirect("/order/{$order->id}/placed")
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
