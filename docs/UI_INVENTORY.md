# UI-инвентаризация

Срез: master `66ea19e`, октябрь 2026. Только факты из кода `resources/views`, `app/Http/Controllers`,
`routes/`. POST-маршруты без своего экрана (редирект с flash) указаны в колонке «Действия».

## 1. Маршруты → контроллер → шаблон

Обёртки: **app** — `layouts/app` (`@extends`), **admin** — `layouts/admin`, **guest** — `<x-guest-layout>`
(компонент `App\View\Components\GuestLayout` → `layouts/guest`).

### Витрина

| URL | Контроллер | Шаблон | Обёртка | Действия (POST) |
|---|---|---|---|---|
| `/` | `HomeController@index` | `home` | app | — |
| `/catalog` | `CatalogController@index` | `catalog_index` | app | — |
| `/catalog/{slug}` | `CatalogController@show` | `catalog` (+ `partials/catalog_filters`, `catalog_results`) | app | — |
| `/catalog/menu.json` | `CatalogController@menu` | JSON (мега-меню) | — | — |
| `/product/{id}` | `ProductController@show` | `product` | app | — |
| `/search` | `SearchController@index` | `search` | app | — |
| `/search/suggest` | `SearchController@suggest` | JSON (подсказки) | — | — |

### Кабинет клиента (auth)

| URL | Контроллер | Шаблон | Действия (POST) |
|---|---|---|---|
| `/order` (корзина) | `CartController@index` | `order` | `order/add/{id}`, `update/{id}`, `delete/{id}`, `accept-prices`, `checkout` |
| `/order/{id}/placed` | `OrderController@placed` | `order_placed` | — |
| `/orders` | `OrderController@index` | `orders` (+ `partials/orders_table` ×2) | — |
| `/order/{id}` | `OrderController@show` | `order_show` | `order/{id}/cancel-request`, `order/{id}/reorder` |
| `/addresses` | `DeliveryAddressController@index` | `addresses` (+ `partials/address_list`) | `addresses/{id}/default`, `addresses/{id}/delete` |
| `/addresses/create`, `/addresses/{id}/edit` | `…@create`, `…@edit` | `address_form` (+ `partials/address_fields`) | `addresses`, `addresses/{id}` |
| `/profile` | `ProfileController@edit` | `profile/edit` (+ 2 partials на Breeze-компонентах) | `PATCH profile`, `PUT password` |
| `/dashboard` | замыкание | редирект на `/catalog` | — |

### Админка (`/admin`, auth + admin)

| URL | Контроллер | Шаблон | Действия (POST) |
|---|---|---|---|
| `/admin/products` | `AdminProductController@index` | `admin/products` | `product/{id}/toggle-visibility`, `product/{id}/delete` |
| `/admin/product/create`, `/admin/product/{id}` | `…@create`, `…@show` | `admin/product_form` (+ `vat_rate_select`, `admin_product_attributes`, `admin_product_images`, `image_queue`) | `product`, `product/{id}`, `draft`, `draft/delete`, `publish`, `images…` (store/reorder/main/move/replace/delete), `documents…` (store/reorder/update/delete) |
| `/admin/product/{id}/preview` | `…@preview` | `product` (витринный, `$preview`) | — |
| `/admin/orders` | `AdminOrderController@index` | `admin/orders` | — |
| `/admin/order/{id}` | `…@show` | `admin/order` (+ `order_items`, `order_delivery`, `order_history`) | `order/{id}`, `status`, `cancel-request/approve`, `cancel-request/reject` |
| `/admin/organizations` | `AdminOrganizationController@index` | `admin/organizations` | — |
| `/admin/organization/{id}` | `…@show` | `admin/organization` (+ `address_list`) | `organization/{id}/delete` |
| `/admin/organization/create`, `…/{id}/edit` | `…@create`, `…@edit` | `admin/organization_form` | `organization`, `organization/{id}` |
| `/admin/organization/{org}/address/create`, `…/{id}/edit` | `AdminDeliveryAddressController` | `admin/address_form` (+ `address_fields`) | `address`, `address/{id}`, `default`, `delete` |
| `/admin/users` | `AdminUserController@index` | `admin/users` | — |
| `/admin/user/{id}` | `…@show` | `admin/user` | `block`, `unblock`, `reset-password` |
| `/admin/user/create`, `…/{id}/edit` | `…@create`, `…@edit` | `admin/user_form` | `user`, `user/{id}` |
| `/admin/attributes` | `AdminAttributeController@index` | `admin/attributes` | `attributes/merge`, `attribute/{id}/delete` |
| `/admin/attribute/create`, `…/{id}/edit` | `…@create`, `…@edit` | `admin/attribute_form` | `attribute`, `attribute/{id}` |
| `/admin/catalog-filters` | `AdminCatalogFilterController@index` | `admin/catalog_filters` | — |
| `/admin/catalog-filters/{id}` | `…@edit` | `admin/catalog_filter_form` | `catalog-filters/{id}` |

### Auth (Breeze)

| URL | Контроллер | Шаблон | Обёртка |
|---|---|---|---|
| `/login` | `AuthenticatedSessionController@create` | `auth/login` | guest |
| `/forgot-password` | `PasswordResetLinkController@create` | `auth/forgot-password` | guest |
| `/reset-password/{token}` | `NewPasswordController@create` | `auth/reset-password` | guest |
| `/confirm-password` | `ConfirmablePasswordController@show` | `auth/confirm-password` | guest |
| `/verify-email` | `EmailVerificationPromptController` | `auth/verify-email` | guest |

### Шаблоны без маршрута (мёртвые)

| Шаблон | Почему |
|---|---|
| `welcome` | стандартная страница Laravel, нигде не рендерится |
| `dashboard` | `/dashboard` — редирект; шаблон на `<x-app-layout>` |
| `auth/register` | регистрация отключена (SRS §3), маршрута нет |
| `partials/alerts` | дубль `partials/flash` на inline-стилях, нигде не подключён |
| `partials/menu` | старое меню на inline-стилях, нигде не подключён (заменён мега-меню) |

## 2. Blade-компоненты

Правило проекта (CLAUDE.md) — без `<x-…>`. На деле компоненты Breeze остались в auth и профиле.

| Компонент | Где используется |
|---|---|
| `x-guest-layout` (класс `GuestLayout`) | все 6 `auth/*` |
| `x-app-layout` (класс `AppLayout`) | только мёртвый `dashboard` |
| `x-application-logo` | `layouts/guest` |
| `x-auth-session-status` | `auth/login`, `auth/forgot-password` |
| `x-input-label`, `x-text-input`, `x-input-error` | `auth/*` (кроме verify-email), `profile/partials/*` |
| `x-primary-button` | `auth/*`, `profile/partials/*` |
| `x-danger-button`, `x-secondary-button`, `x-modal`, `x-dropdown`, `x-dropdown-link`, `x-nav-link`, `x-responsive-nav-link` | **не используются** |

Вместо компонентов фактически работают partials (`@include`) — это и есть текущая «библиотека»:

| Partial | Назначение | Подключается в |
|---|---|---|
| `flash` | flash + `$errors` | `layouts/app`, `layouts/admin` |
| `order_status` | бейдж статуса заказа | `admin/orders`, `admin/order`, `order_placed`, `order_show`, `orders_table`, `order_history` |
| `cancel_request_badge` | бейдж запроса на отмену | `admin/orders`, `order_show`, `orders_table` |
| `stock_badge` | наличие | `product` (в списках — `x-stock`) |
| `vat_totals` | итог без НДС / НДС / с НДС | `order`, `order_placed`, `order_items` |
| `vat_rate_select` | выбор ставки НДС | `admin/product_form` |
| `product_image` | картинка-заглушка | `home`, `order`, `order_items`, `product_gallery`, `x-product-row` |
| `orders_table`, `order_items`, `order_delivery`, `order_history` | блоки заказа | кабинет + админка |
| `address_fields`, `address_list` | адреса | кабинет + админка |

## 3. Расхождения

### Кнопки — 40 вариантов классов на 77 `<button>`

| Роль | Варианты | Где |
|---|---|---|
| Основная | `bg-slate-800 px-4 py-2 rounded` · `px-5 py-2 rounded-xl` · `px-5 py-2 rounded` · `px-5 py-2.5 rounded` · `px-5 py-2.5 rounded-xl` · `px-6 py-2.5 rounded` · `px-6 py-3 rounded` · `px-3 py-1 rounded text-sm` | списки админки · `address_form`, `admin/order(s)` · `admin/address_form` · `product_form` · `order_show` · `attribute_form`, `catalog_filter_form` · `organization_form`, `user_form` · `order` |
| Основная (Breeze) | `bg-gray-800 rounded-md text-xs uppercase tracking-widest` | `auth/*`, `profile` |
| «Успех» | `bg-green-700 rounded` · `bg-green-600` | `product_form` (опубликовать), `admin/user` (разблокировать) · `product` |
| Второстепенная | `border border-slate-300 rounded` · `… rounded-lg text-sm` · `bg-slate-100 rounded` | `product_form` · `admin_product_attributes` · `admin/user` |
| Опасная | `bg-red-600 rounded-xl` · `border border-red-300 text-red-700 rounded` · `… rounded-xl` · `bg-red-50` · ссылка `text-red-600 underline` | `admin/order` · `admin/user` · `order_show` · `admin_product_images` · `order` (удалить из корзины) |
| Ссылка-кнопка | `bg-slate-800 rounded` (админка) · `rounded-xl` (`addresses`) · `border rounded-xl` (`order_placed`) · `px-8 py-4 rounded-xl` (`home`) | — |

### Поля

| Элемент | Варианты | Где |
|---|---|---|
| input | `border px-3 py-2 rounded w-full` (серая граница по умолчанию) · `border border-slate-300 …` · `border px-2 py-1` · `px-2 py-1.5 text-sm` · `rounded-lg text-sm` · `$input` в `@php` | большинство форм · `admin/orders` · `catalog_filter_form` · `catalog_filters` · `header/search` · `product_form` |
| input (Breeze) | `border-gray-300 focus:ring-indigo-500 rounded-md shadow-sm` | `auth/*`, `profile` |
| select | 7 вариантов, в т.ч. `bg-slate-100` (disabled-вид) и `rounded-lg` | — |
| textarea | `border border-slate-300 …` и `border …` без цвета | `order`, `order_show`, `admin/order` · `organization_form` |
| label | `block mb-2 font-semibold` (32) · `block text-sm font-medium text-slate-600 [mb-1]` (9) · Breeze `x-input-label` (gray) | формы админки · корзина, заказ, фильтры · auth |
| Ошибка поля | `p.text-red-600.text-sm` с `mt-1` / `mb-2` / без отступа · блок `bg-red-50 rounded-xl` · `x-input-error` (`ul`) | — |
| Обязательное | `<span class="text-red-600">*</span>` только в 9 местах; в остальных формах `required` без пометки | — |

### Таблицы — 3 стиля

| Стиль | Шапка | Ячейки | Где |
|---|---|---|---|
| Новый | `thead bg-slate-50 border-b`, `th px-4 py-3 text-sm font-semibold text-slate-600` | `tbody divide-y divide-slate-200` | `orders_table`, `order`, `search`, `admin/orders`, `order_items` |
| Старый (сетка) | `thead bg-gray-100`, `th border p-3` | `td border p-3` | `admin/products`, `admin/users`, `admin/organizations`, `admin/organization` |
| Компактный | `th py-2 pr-4` / `th p-3` | `text-sm` | `admin/attributes`, `admin/catalog_filters`, `address_list`, `order_history`, `product` (характеристики) |

### Flash-сообщения — 4 механизма

| Механизм | Вид | Где |
|---|---|---|
| `partials/flash` (`success`/`error`/`warning` + `$errors->any()`) | `bg-green-100 border-green-400 rounded` (стиль Bootstrap-алерта, не карточка `rounded-2xl`) | обе раскладки |
| `cart-actions.js` | тот же блок `[data-flash]` поверх страницы (`[data-flash][data-floating]`), исчезает через 4 с | «В корзину» из списков (fetch, без перезагрузки) |
| `session('status')` + `x-auth-session-status` | зелёный текст без фона | `login`, `forgot-password` |
| `status` = `profile-updated` / `password-updated` | «Saved.» (`__('Saved.')`) с Alpine-исчезновением | `profile` |
| `partials/alerts` | inline-стили | не используется |

Ошибки валидации выводятся дважды: списком в `flash` (`$errors->any()`) и под полем (`@error`).

### Цена

Все суммы — `<x-price>` (элемент со стилем и подписью `vat="net"` / `vat="gross"`) или `money($x)` в тексте
(`App\Support\Money`): `1 234,50 BYN`, неразрывные пробелы. Пустая цена — витрина «Цена уточняется»
(по умолчанию у `x-price` и `money()`), админка — «—» (`placeholder="—"`, `money($x, empty: '—')`).
Исключение — фильтр цены `catalog_filters`: значение поля ввода (`1234.5`), не вывод суммы.

### НДС

| Место | Подпись |
|---|---|
| `product` | «Цена без НДС» / «НДС (20%)» / «Цена с НДС» |
| `catalog_product_card`, `search` | крупно цена без НДС без подписи + «(с НДС: …)» или «(без НДС)» — «(без НДС)» означает «ставка: без НДС», читается как «цена без НДС» |
| `home` | только цена, без НДС и без пометки, что это цена без НДС |
| шапка | итог корзины с НДС, НДС — только в `title` |
| `order`, `order_items`, `orders_table`, `admin/orders` | колонки «Без НДС» / «НДС» / «С НДС» или «Сумма без НДС» / «Сумма НДС» / «Сумма с НДС» — названия колонок отличаются |

### Дата

Все даты — `date_ru($d)`: списки (`orders_table`, `admin/orders`) — `d.m.Y`; карточки заказа, клиента,
история (`order_show`, `order_placed`, `admin/order`, `admin/user`, `order_history`) — `date_ru($d, time: true)`, `d.m.Y H:i`.

### Бейджи

| Бейдж | Размер | Цвета |
|---|---|---|
| `order_status` | `px-3 py-1 text-sm` | из `OrderStatus::color()`: blue/cyan/indigo/yellow/purple/green/slate |
| `stock_badge` | `px-3 py-1 text-sm` | green / amber / red |
| `cancel_request_badge` | `px-2 py-0.5 text-xs` | orange |
| Инлайн (видимость/черновик товара, блокировка пользователя, «по умолчанию», «Основное» фото) | `px-2 py-0.5 text-xs` | green/slate/amber/blue/red — разметка скопирована в 8 файлах |
| Проблемы корзины | `px-3 py-1 text-sm` | red / yellow |
| Наличие в `home` | не бейдж, а цветной текст `text-green-600 text-sm` | дублирует `stock_badge` иначе |

«В процессе» (`yellow`) и «Под заказ» (`amber`) и проблема корзины (`yellow`) визуально почти одинаковы.

### Заголовки страниц

`h1`: 10 вариантов (`text-3xl font-bold` с `mb-6` / `mt-4 mb-6` / `mb-2` / без отступа, `text-4xl text-slate-800`, Breeze `font-medium`). В админке часть заголовков начинается с «Админка — …».

## 4. Пробелы

### Нет пустого состояния

| Экран | Что видно при пустом списке |
|---|---|
| `admin/products` | пустая таблица с шапкой |
| `admin/catalog_filters` | пустая таблица |
| `home` | пустые секции «Категории» и «Популярные товары» |
| `catalog_index` | пустая сетка |
| `orders_table` (partial) | обработано снаружи в `orders`; сам partial пустое не показывает |
| `address_list` (partial) | обработано снаружи в `addresses`, `admin/organization` |

С пустым состоянием: `admin/attributes`, `admin/orders`, `admin/organizations`, `admin/users`, `admin/organization`, `orders`, `order`, `addresses`, `search`, `catalog_results`, `order_history`, `admin_product_images`.

### Нет подтверждения у необратимых действий

| Действие | Экран |
|---|---|
| «Удалить» позицию корзины (2 формы) | `order` |
| «Отклонить запрос на отмену» | `admin/order` (есть обязательная причина, но без confirm) |
| «Сбросить пароль» (отправляет письмо) | `admin/user` |
| Удаление строки характеристики / фото из очереди (на клиенте, до сохранения) | `admin_product_attributes`, `image_queue` |

С `confirm()`: удаление товара (список и форма), черновика, фото, клиента, адреса, характеристики, объединение характеристик, блокировка пользователя, одобрение отмены. Везде нативный `confirm()`, модального окна нет (`x-modal` есть, но не используется).

### Таблицы без мобильной адаптации

Обёртка `overflow-x-auto` есть только в `order_items`. Остальные 13 таблиц — в `overflow-hidden` (лишние колонки обрезаются) или без обёртки; карточного вида на `sm` нет ни у одной.

| Таблица | Колонок | Обёртка |
|---|---|---|
| `admin/orders` | 10 | `overflow-hidden` |
| `order` (корзина) | 10 | `overflow-hidden` |
| `orders_table` (кабинет) | 9 | `overflow-hidden` |
| `admin/products`, `admin/users`, `admin/organizations`, `admin/attributes`, `search` | 8 | `overflow-hidden` |
| `address_list`, `order_history` | 5 | нет |
| `admin/organization`, `admin/catalog_filters` | 4 | нет / `overflow-hidden` |

## 5. Предложение: базовый набор

Учитывая правило «без `<x-…>`», два пути: (а) разрешить анонимные компоненты `resources/views/components/ui/*` — одна точка правки, слоты, `$attributes->merge`; (б) остаться на `@include('ui.*', [...])`. Рекомендую (а): partials уже упираются в передачу классов и слотов (`product_form` держит `$input` в `@php`), а Breeze-компоненты всё равно есть.

| Компонент | Заменяет | Параметры |
|---|---|---|
| `ui.button` | 40 вариантов кнопок, Breeze `primary/secondary/danger-button` | `variant` primary/secondary/success/danger/link, `size` sm/md, `href` (рендерит `<a>`), `confirm` |
| `ui.input`, `ui.select`, `ui.textarea` | 7+7+3 варианта, `$input` | `name`, `label`, `required` (рисует `*`), `hint`, ошибка из `$errors` внутри |
| `ui.field-error` | 4 варианта `@error`, `x-input-error` | `name` |
| `ui.alert` | `partials/flash`, `alerts`, `auth-session-status`, «Saved.» | `type` success/error/warning/info, `dismissible`; flash — тонкая обёртка над ним |
| `ui.badge` | инлайн-бейджи в 8 файлах; `order_status`, `stock_badge`, `cancel_request_badge` — поверх него | `color`, `size` |
| `ui.table` | 3 стиля таблиц | слоты `head`/`body`, всегда `overflow-x-auto`; опционально `stack` (карточки на `sm`) |
| `ui.empty` | разные «пока нет» | `title`, `text`, слот действия |
| `ui.card` | `bg-white rounded-2xl border border-slate-200 p-6` (копируется десятки раз) | `padding` |
| `ui.page-header` | 10 вариантов `h1` | `title`, слот кнопок справа, хлебные крошки |
| `ui.confirm-form` | `onclick="return confirm(...)"` | `action`, `message`, `method`; позже — переход на модалку без правки экранов |
| Хелперы `money()`, `date_ru()` | 5 форматов цены, 2 формата даты | `money($x)` → `1 234,50 BYN`, `null` → «Цена уточняется»; `date_ru($d, time: true)` |
| `ui.price` | подписи НДС в карточках/поиске/главной | `product`, `mode` card/full: явные «без НДС» / «с НДС» |

Порядок внедрения: хелперы `money`/`date` (механическая замена, проверяется тестами) → `alert` + `button` + `field` → `table` + `empty` (заодно мобильная адаптация) → `badge`/`page-header`. Мёртвые шаблоны (`welcome`, `dashboard`, `register`, `alerts`, неиспользуемые Breeze-компоненты) удалить отдельным коммитом.
