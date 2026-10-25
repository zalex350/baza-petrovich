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

    /**
     * Корзина и оформление — одна страница (макеты Main, CartMobile): слева позиции с галочкой
     * «в заказ», справа панель «Ваш заказ» по отмеченным. Позиция, которую сейчас нельзя
     * заказать (нехватка остатка, товар недоступен), снимается с заказа автоматически.
     */
    public function index()
    {
        $cart = auth()->user()->cart()->with('items.product.mainImage')->first();
        $items = $cart?->items ?? collect();

        // Все позиции — для строк (причины, «Доступно N шт.»)
        $validation = $this->validator->validateItems($items);

        $unselect = $items->filter(fn (CartItem $item) => $item->selected
            && ($validation->isUnavailable($item) || $validation->shortage($item)));

        if ($unselect->isNotEmpty()) {
            CartItem::whereKey($unselect->modelKeys())->update(['selected' => false]);
            // foreach, не each(): стрелочная функция вернула бы false и прервала обход
            foreach ($unselect as $item) {
                $item->selected = false;
            }
        }

        // Панель «Ваш заказ», состояние и кнопка — только по отмеченным
        $order = $this->validator->validateItems($items->where('selected', true)->values());

        // Жёлтый баннер: позиции, снятые из-за нехватки остатка
        $shortages = $items->filter(fn (CartItem $item) => $validation->shortage($item) && ! $validation->isUnavailable($item))->values();

        // Одноразовый токен формы оформления — защита от двойной отправки
        $checkoutToken = (string) Str::uuid();

        // Адреса доставки организации для выбора при оформлении (BR-128)
        $addresses = auth()->user()->organization?->deliveryAddresses ?? collect();

        $continueUrl = CatalogController::continueUrl();

        return view('order', compact('cart', 'items', 'validation', 'order', 'shortages', 'checkoutToken', 'addresses', 'continueUrl'));
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

        return $this->addResult($this->addProduct($product, $qty), $product);
    }

    /**
     * Добавить товар в корзину клиента: проверяется итоговое количество (кратность, остаток,
     * доступность). Добавленная позиция отмечается «в заказ». null — успех, иначе текст ошибки.
     */
    private function addProduct(Product $product, int $qty): ?string
    {
        $cart = auth()->user()->cart()->firstOrCreate();

        // Блокировка строки корзины сериализует параллельные добавления одного товара:
        // вторая вставка не упирается в unique(cart_id, product_id), а увеличивает количество.
        return DB::transaction(function () use ($cart, $product, $qty) {
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
                $item->selected = true;
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
    }

    /**
     * «Добавить по артикулу» на странице корзины: артикул + количество (пусто — количество товара
     * по умолчанию). Ошибки — под полями: не найден / недоступен — у артикула, кратность и
     * остаток — у количества. JSON (cart-page.js) — {ok, message, cart, item_id} или {ok: false,
     * errors: {sku|qty: текст}}; обычная форма — назад в корзину с ошибками у полей.
     */
    public function quickAdd()
    {
        if (! auth()->user()->isClient()) {
            return $this->quickAddResult(['sku' => 'Оформлять заказы могут только клиенты']);
        }

        $validator = Validator::make(request()->all(), [
            'sku' => ['required', 'string', 'max:100'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ], [
            'sku.required' => 'Укажите артикул',
            'qty.integer' => 'Количество — целое число',
            'qty.min' => 'Количество — не меньше 1',
        ]);

        if ($validator->fails()) {
            return $this->quickAddResult(collect($validator->errors()->messages())->map(fn ($m) => $m[0])->all());
        }

        $data = $validator->validated();
        $sku = trim($data['sku']);

        $product = Product::query()
            ->whereRaw('LOWER(sku) = ?', [mb_strtolower($sku)])
            ->where('is_active', true)
            ->where('is_published', true)
            ->first();

        if (! $product) {
            return $this->quickAddResult(['sku' => "Товар с артикулом «{$sku}» не найден"]);
        }

        $error = $this->addProduct($product, (int) ($data['qty'] ?? $product->default_qty ?? 1));

        if ($error) {
            return $this->quickAddResult([$product->price === null ? 'sku' : 'qty' => $error]);
        }

        $message = 'Добавлено в корзину: '.($product->display_name ?: $product->name);
        $item = $this->summary->items(auth()->user())->get($product->id);

        if (request()->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message,
                'cart' => $this->summary->forHeader(auth()->user()),
                'item_id' => $item?->id,
            ], 200, [], JSON_UNESCAPED_UNICODE);
        }

        return redirect('/order')->with('success', $message);
    }

    /**
     * @param  array<string, string>  $errors  поле (sku | qty) → текст
     */
    private function quickAddResult(array $errors)
    {
        if (request()->expectsJson()) {
            return response()->json(['ok' => false, 'errors' => $errors], 422, [], JSON_UNESCAPED_UNICODE);
        }

        return redirect('/order')->withErrors($errors)->withInput();
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
                // layout: row — строка списка, card — карточка товара (поле формы, по умолчанию row)
                $data['html'] = Blade::render('<x-product-row.buy :product="$product" :in-cart="$item" :layout="$layout" />', [
                    'layout' => request('layout') === 'card' ? 'card' : 'row',
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
            $error = match (true) {
                $problem->type === CartProblemType::InsufficientStock => $problem->title().": доступно {$problem->available}",
                // Кратность: «Количество должно быть не меньше N и кратно M»
                $problem->type->isQuantityRule() => $problem->text(),
                default => $problem->fullMessage(),
            };

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

    /**
     * Галочка «в заказ». Отметить можно только позицию, которую сейчас можно заказать
     * (кратность, остаток, доступность — CartValidator); иначе выбор не меняется.
     */
    public function select($id)
    {
        $item = $this->ownItem($id);
        $selected = request()->boolean('selected');

        if ($selected && ($problem = $this->orderProblem($item))) {
            return $this->cartResult($problem, ['selected' => false]);
        }

        $item->update(['selected' => $selected]);

        return $this->cartResult(null, ['selected' => $selected], $selected ? 'Позиция отмечена' : 'Позиция снята с заказа');
    }

    /**
     * «Выбрать все»: отмечаются позиции, которые можно заказать; остальные остаются снятыми.
     */
    public function selectAll()
    {
        $cart = auth()->user()->cart()->with('items.product')->first();
        $items = $cart?->items ?? collect();
        $selected = request()->boolean('selected');

        if (! $selected) {
            CartItem::whereKey($items->modelKeys())->update(['selected' => false]);

            return $this->cartResult(null, [], 'Все позиции сняты с заказа');
        }

        $ok = $items->reject(fn (CartItem $item) => $this->orderProblem($item));
        CartItem::whereKey($ok->modelKeys())->update(['selected' => true]);

        $skipped = $items->count() - $ok->count();

        return $this->cartResult(null, [], $skipped
            ? "Отмечено позиций: {$ok->count()}. Нельзя заказать сейчас: {$skipped}"
            : 'Все позиции отмечены');
    }

    public function delete($id)
    {
        $this->ownItem($id)->delete();

        return $this->cartResult(null, [], 'Товар удалён из корзины');
    }

    /**
     * «Очистить корзину» (с подтверждением).
     */
    public function clear()
    {
        auth()->user()->cart()->first()?->items()->delete();

        return redirect('/order')->with('success', 'Корзина очищена');
    }

    /**
     * Почему позицию нельзя заказать в её количестве (цена не сравнивается — её принимают отдельно), или null.
     */
    private function orderProblem(CartItem $item): ?string
    {
        $problem = $this->validator->check($item->product, (int) $item->quantity, null, $item->title())[0] ?? null;

        return match (true) {
            $problem === null => null,
            $problem->type->isShortage() => "Доступно {$problem->available} шт.",
            $problem->type->isQuantityRule() => $problem->text(),
            default => $problem->title(),
        };
    }

    /**
     * Ответ действий на странице корзины: JSON (cart-page.js затем обновляет строки и панель)
     * или обычная форма — назад с сообщением.
     */
    private function cartResult(?string $error, array $extra = [], string $success = 'Корзина обновлена')
    {
        if (request()->expectsJson()) {
            return response()->json([
                'ok' => $error === null,
                'message' => $error ?? $success,
                'cart' => $this->summary->forHeader(auth()->user()),
            ] + $extra, $error === null ? 200 : 422, [], JSON_UNESCAPED_UNICODE);
        }

        return $error ? back()->with('error', $error) : back()->with('success', $success);
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
