<?php

namespace App\Http\Controllers;

use App\Enums\CartProblemType;
use App\Exceptions\CheckoutException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Services\ArticleSearch;
use App\Services\CartSummary;
use App\Services\CartValidator;
use App\Services\CheckoutService;
use App\Services\ClientCodes;
use App\Services\CurrentCart;
use App\Support\OrderQuantity;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class CartController extends Controller
{
    /** Сессия: выбор товара при неоднозначном артикуле (форма без JS) — x-cart-quick-add */
    public const QUICK_CHOICES = 'quick_add_choices';

    public function __construct(
        private readonly CartValidator $validator,
        private readonly CartSummary $summary,
        private readonly ClientCodes $codes,
        private readonly CurrentCart $current,
    ) {}

    /**
     * Корзина и оформление — одна страница (макеты Main, CartMobile): слева позиции с галочкой
     * «в заказ», справа панель «Ваш заказ» по отмеченным. Позиция, которую сейчас нельзя
     * заказать (нехватка остатка, товар недоступен), снимается с заказа автоматически.
     */
    public function index()
    {
        $cart = $this->current->get()?->load(['items.product.mainImage', 'items.product.clientPrice']);
        $items = $cart?->items ?? collect();

        // «Ваш код» в позициях — коды организации одной выборкой
        $this->codes->load($items->pluck('product'));

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

        // Адреса доставки организации для выбора при оформлении (BR-128); гость — розничная форма
        $addresses = auth()->user()?->organization?->deliveryAddresses ?? collect();
        $retail = $this->current->isGuest();

        $continueUrl = CatalogController::continueUrl();

        // «Добавить по вашему коду» — если у организации есть коды, иначе «по артикулу»
        $byCode = $this->codes->has();

        return view('order', compact('cart', 'items', 'validation', 'order', 'shortages', 'checkoutToken', 'addresses', 'continueUrl', 'byCode', 'retail'));
    }

    /**
     * Добавление из карточки и из списков товаров. Обычная форма возвращает на ту же
     * страницу (с фильтрами и пагинацией); запрос с Accept: application/json (cart-actions.js)
     * получает JSON: сообщение, сводку для шапки и новый блок покупки строки списка.
     */
    public function add($id)
    {
        // BR-121: оформлять заказы могут только клиенты — корзина администратору не нужна
        if (! $this->current->canBuy()) {
            return $this->addResult('Оформлять заказы могут только клиенты');
        }

        $product = Product::find($id);
        $qty = max(1, (int) request('qty', 1));

        if (! $product) {
            return $this->addResult(CartProblemType::Missing->title());
        }

        $hint = null;
        $error = $this->addProduct($product, $qty, $hint);

        return $this->addResult($error, $product, $hint);
    }

    /**
     * Добавить товар в корзину клиента: итоговое количество округляется вверх до допустимого
     * (OrderQuantity; $hint — «Округлено до 6 — кратно 3»), затем проверяется (остаток,
     * доступность). Добавленная позиция отмечается «в заказ». null — успех, иначе текст ошибки.
     */
    private function addProduct(Product $product, int $qty, ?string &$hint = null): ?string
    {
        $cart = $this->current->getOrCreate();

        // Блокировка строки корзины сериализует параллельные добавления одного товара:
        // вторая вставка не упирается в unique(cart_id, product_id), а увеличивает количество.
        return DB::transaction(function () use ($cart, $product, $qty, &$hint) {
            Cart::whereKey($cart->id)->lockForUpdate()->first();

            $item = CartItem::where('cart_id', $cart->id)
                ->where('product_id', $product->id)
                ->first();

            $inCart = $item?->quantity ?? 0;

            // Итог — вверх до допустимого: добавляется недостающее до него
            $rule = OrderQuantity::for($product);
            $total = $rule->roundUp($inCart + $qty);
            if ($total !== $inCart + $qty) {
                $hint = $rule->roundedHint($total);
                $qty = $total - $inCart;
            }

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
                    'product_name' => $product->title(),
                    'quantity' => $qty,
                    'price_at_add' => $product->price,
                ]);
            }

            return null;
        });
    }

    /**
     * «Добавить по артикулу» на странице корзины: артикул + количество (пусто — количество товара
     * по умолчанию). Поиск: product_id (выбор из подсказки или из списка) → код организации
     * клиента → артикул или sku (ArticleSearch::whereExact). Артикул не уникален между брендами:
     * несколько товаров — не добавляется ничего, ответ — выбор с брендом (x-cart-quick-choices).
     * Не найден — ошибка, при варианте без дефисов — «Возможно, вы искали …» (не добавляется).
     * Ошибки — под полями: не найден / недоступен — у артикула, кратность и остаток — у количества.
     * JSON (cart-page.js) — {ok, message, cart, item_id} или {ok: false, errors: {sku|qty: текст},
     * choices_html?}; обычная форма — назад в корзину с ошибками у полей (выбор — в сессии).
     */
    public function quickAdd(ArticleSearch $articles)
    {
        if (! $this->current->canBuy()) {
            return $this->quickAddResult(['sku' => 'Оформлять заказы могут только клиенты']);
        }

        $validator = Validator::make(request()->all(), [
            'sku' => ['required', 'string', 'max:100'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'product_id' => ['nullable', 'integer'],
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

        // «Добавить по вашему коду»: сначала код организации клиента (без учёта регистра),
        // затем наш артикул
        $shown = fn () => Product::query()->where('is_active', true)->where('is_published', true);
        $codeProductId = $this->codes->productIdByCode($sku);

        $product = ! empty($data['product_id']) ? $shown()->find($data['product_id']) : null;
        $product ??= $codeProductId ? $shown()->find($codeProductId) : null;

        if (! $product) {
            $found = $articles->whereExact($shown(), $sku)->orderBy('brand')->orderBy('id')->limit(20)->get();

            if ($found->count() > 1) {
                return $this->quickAddResult(
                    ['sku' => "Артикул «{$sku}» есть у нескольких товаров — выберите нужный"],
                    $found->map(fn (Product $p) => [
                        'id' => $p->id,
                        'article' => $p->displayArticle(),
                        'brand' => $p->brand,
                        'name' => $p->title(),
                    ])->all(),
                );
            }

            $product = $found->first();
        }

        if (! $product) {
            $message = $this->codes->has()
                ? "Товар с кодом или артикулом «{$sku}» не найден"
                : "Товар с артикулом «{$sku}» не найден";
            $hints = $articles->didYouMean($shown(), $sku);

            return $this->quickAddResult(['sku' => $hints ? $message.'. Возможно, вы искали '.implode(', ', $hints) : $message]);
        }

        $hint = null;
        $error = $this->addProduct($product, (int) ($data['qty'] ?? $product->default_qty ?? 1), $hint);

        if ($error) {
            return $this->quickAddResult([$product->price === null ? 'sku' : 'qty' => $error]);
        }

        $message = 'Добавлено в корзину: '.($product->title()).($hint ? ". {$hint}" : '');
        $item = $this->summary->items()->get($product->id);

        if (request()->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message,
                'cart' => $this->summary->forHeader(),
                'item_id' => $item?->id,
            ], 200, [], JSON_UNESCAPED_UNICODE);
        }

        return redirect('/order')->with('success', $message);
    }

    /**
     * @param  array<string, string>  $errors  поле (sku | qty) → текст
     * @param  list<array{id: int, article: string, brand: ?string, name: string}>  $choices  товары с этим артикулом
     */
    private function quickAddResult(array $errors, array $choices = [])
    {
        if (request()->expectsJson()) {
            $data = ['ok' => false, 'errors' => $errors];

            if ($choices) {
                $data['choices_html'] = Blade::render('<x-cart-quick-choices :choices="$choices" />', ['choices' => $choices]);
            }

            return response()->json($data, 422, [], JSON_UNESCAPED_UNICODE);
        }

        return redirect('/order')->withErrors($errors)->withInput()->with(self::QUICK_CHOICES, $choices);
    }

    /**
     * Ответ на добавление в корзину: $error = null — успех.
     */
    private function addResult(?string $error, ?Product $product = null, ?string $hint = null)
    {
        $added = 'Товар добавлен в корзину'.($hint ? ". {$hint}" : '');

        if (request()->expectsJson()) {
            $data = ['ok' => $error === null, 'message' => $error ?? $added];

            if ($error === null) {
                $data['cart'] = $this->summary->forHeader();
                $data['toast'] = Blade::render('<x-alert type="success">{{ $message }}</x-alert>', ['message' => $data['message']]);
                // Строка списка переходит в состояние «В корзине»: количество и «перейти»
                // layout: row — строка списка, card — карточка товара, tile — плитка (поле формы, по умолчанию row)
                $data['html'] = Blade::render('<x-product-row.buy :product="$product" :in-cart="$item" :layout="$layout" />', [
                    'layout' => in_array(request('layout'), ['card', 'tile'], true) ? request('layout') : 'row',
                    'product' => $product,
                    'item' => $this->summary->items()->get($product->id),
                ]);
            }

            return response()->json($data, $error === null ? 200 : 422, [], JSON_UNESCAPED_UNICODE);
        }

        return $error
            ? back()->with('error', $error)
            : back()->with('success', $added);
    }

    public function update($id)
    {
        $item = $this->ownItem($id);

        // Ручной ввод — вверх до допустимого (x-qty округляет так же до отправки)
        $rule = OrderQuantity::for($item->product);
        $typed = max(1, (int) request('quantity'));
        $quantity = $item->product ? $rule->roundUp($typed) : $typed;
        $hint = $quantity !== $typed ? $rule->roundedHint($quantity) : null;

        // Цена здесь не сравнивается: её изменение не мешает менять количество
        $problem = $this->validator->check($item->product, $quantity, null, $item->title())[0] ?? null;

        if ($problem) {
            $error = match (true) {
                // Остаток (SRS §7.11): значение не применяется, в строке — «Доступно N шт.»
                $problem->type->isShortage() => "Доступно {$problem->available} шт.",
                // Кратность: «Количество должно быть не меньше N и кратно M»
                $problem->type->isQuantityRule() => $problem->text(),
                default => $problem->fullMessage(),
            };

            return $this->updateResult($error, $item);
        }

        $item->quantity = $quantity;
        $item->save();

        return $this->updateResult(null, $item, $hint);
    }

    /**
     * Ответ на изменение количества: обычная форма — назад с сообщением; JSON (+/− в списках) —
     * сообщение, количество в корзине (после ошибки — прежнее) и сводка для шапки.
     */
    private function updateResult(?string $error, CartItem $item, ?string $hint = null)
    {
        $updated = $hint ?? 'Корзина обновлена';

        if (request()->expectsJson()) {
            return response()->json([
                'ok' => $error === null,
                'message' => $error ?? $updated,
                // Округление сервером (без x-qty или из поля без кратности) — подсказка у строки
                'hint' => $error === null ? $hint : null,
                'quantity' => $item->quantity,
                'cart' => $this->summary->forHeader(),
            ], $error === null ? 200 : 422, [], JSON_UNESCAPED_UNICODE);
        }

        return $error
            ? back()->with('error', $error)
            : back()->with('success', $updated);
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
        $cart = $this->current->get()?->load(['items.product.clientPrice']);
        $items = $cart?->items ?? collect();

        // «Ваш код» в позициях — коды организации одной выборкой
        $this->codes->load($items->pluck('product'));
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
        $this->current->get()?->items()->delete();

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
                'cart' => $this->summary->forHeader(),
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
        $cart = $this->current->get()?->load(['items.product.clientPrice']);

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

        if ($this->current->isGuest()) {
            return $this->checkoutRetail($checkout, $token);
        }

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
     * Розничное оформление гостя: контакт, способ получения (самовывоз Минск / Брест, доставка с адресом),
     * оплата, согласие на обработку персональных данных. Успех — страница заказа по ссылке /o/{token}.
     */
    private function checkoutRetail(CheckoutService $checkout, ?string $token)
    {
        $delivery = collect(config('shop.checkout.delivery'))->filter(fn ($m) => $m['enabled'] ?? true);
        $payment = collect(config('shop.checkout.payment'))->filter(fn ($m) => $m['enabled'] ?? true);
        $needsAddress = $delivery->filter(fn ($m) => $m['needs_address'] ?? false)->keys()->implode(',');

        $validator = Validator::make(request()->all(), [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50', 'regex:/^[0-9+()\-\s]{7,}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'delivery_method' => ['required', 'in:'.$delivery->keys()->implode(',')],
            'address' => ['nullable', 'string', 'max:500', 'required_if:delivery_method,'.$needsAddress],
            'payment_method' => ['required', 'in:'.$payment->keys()->implode(',')],
            'comment' => ['nullable', 'string', 'max:1000'],
            'consent' => ['accepted'],
        ], [
            'name.required' => 'Укажите имя',
            'phone.required' => 'Укажите телефон',
            'phone.regex' => 'Телефон — цифры, например +375 29 123-45-67',
            'email.email' => 'Проверьте email',
            'delivery_method.required' => 'Выберите способ получения',
            'delivery_method.in' => 'Выберите способ получения',
            'address.required_if' => 'Укажите адрес доставки',
            'payment_method.required' => 'Выберите способ оплаты',
            'payment_method.in' => 'Выберите способ оплаты',
            'comment.max' => 'Комментарий к заказу не длиннее 1000 символов',
            'consent.accepted' => 'Нужно согласие на обработку персональных данных',
        ]);

        if ($validator->fails()) {
            return redirect('/order')->withErrors($validator)->withInput();
        }

        $cart = $this->current->get();

        if (! $cart) {
            return redirect('/order')->with('error', CheckoutException::emptyCart()->getMessage());
        }

        try {
            $order = $checkout->placeRetail($cart, $validator->validated(), $token);
        } catch (CheckoutException $e) {
            $redirect = redirect('/order')->with('error', $e->getMessage())->withInput();

            return $e->problems ? $redirect->withErrors($e->problems) : $redirect;
        } catch (Throwable $e) {
            report($e);

            return redirect('/order')->with('error', CheckoutException::CREATION_FAILED)->withInput();
        }

        return redirect('/o/'.$order->public_token)->with('success', 'Заказ оформлен');
    }

    /**
     * Позиция корзины текущего посетителя (CurrentCart); чужая — 403.
     */
    private function ownItem($id): CartItem
    {
        $item = CartItem::with(['cart', 'product'])->findOrFail($id);

        if (! $this->current->owns($item)) {
            abort(403);
        }

        return $item;
    }
}
