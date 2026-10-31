# Бэклог

Задачи, отложенные на потом. Перед началом — уточнить у заказчика актуальность и детали.

## Коды товаров клиентов

Структура есть (ветка `feature/client-codes`), админка — ветка `feature/client-codes-ui`: блок «Коды клиентов» в карточке товара, вкладка «Коды товаров» клиента с загрузкой из Excel / CSV (`docs/MODULES.md` §11):

- таблица `product_client_codes` (`product_id`, `organization_id`, `code`): один код организации
  на товар, код не повторяется у организации; удаляется вместе с товаром или организацией;
- модель `App\Models\ProductClientCode`, связи `Product::clientCodes()`, `Organization::productCodes()`;
- `Product::codeFor($organization)` — код у организации; для списков без N+1 —
  `Product::query()->withClientCodeFor($organization)` или `Product::loadClientCodesFor($products, $organization)`;
- `order_items.client_code` — снимок кода в позиции заказа (пока не заполняется).

Сделать:

1. **Поиск по коду клиента.** В поиске и подсказках (`SearchController::index`, `suggest`) и
   в «Добавить по артикулу» в корзине — искать и по кодам организации текущего пользователя.
2. **«Ваш код»** в строке товара (`x-product-row`), карточке товара, корзине (`x-cart-row`) и заказе
   (клиент и админка). В заказе — из снимка `order_items.client_code`.
3. **Снимок при оформлении.** `CheckoutService` записывает `client_code` = код организации
   на момент оформления.

Учесть: в MySQL уникальность `(organization_id, code)` не различает регистр (collation
`utf8mb4_unicode_ci`: «ab» = «AB»), а в тестах на SQLite — различает. При импорте и в форме
сравнивать коды без учёта регистра.
