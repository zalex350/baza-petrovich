# Модули smesitel.by

По каждому модулю: назначение, маршруты, код, ключевые правила и тесты. Общая картина — `docs/ARCHITECTURE.md`, полная карта «URL → шаблон» — `docs/UI_INVENTORY.md`.

Пути шаблонов даны от `resources/views/`, тесты — от `tests/Feature/`. Номера BR-… и SRS §… — из ТЗ заказчика, они же стоят в комментариях кода.

---

## 1. Каталог и фильтры

**Назначение.** Дерево категорий, список товаров категории с фильтрами и сортировкой, меню каталога.

| | |
|---|---|
| Маршруты | `GET /catalog` (разделы), `GET /catalog/{slug}` (категория), `GET /catalog/menu.json` (дерево для мобильного меню), `GET /` (главная) |
| Контроллеры | `CatalogController` (`index`, `show`, `menu`), `HomeController` |
| Сервисы | `Catalog\CatalogListing` (запрос, фасеты, чипы, сортировки), `Catalog\CatalogFilters` (набор фильтров категории), `Catalog\KeyAttributes` (характеристики в строке списка), `CatalogMenu` (дерево с кэшем) |
| Модели | `Category`, `CategoryFilter`, `Product`, `ProductAttributeValue` |
| Шаблоны | `catalog_index`, `catalog`, `partials/catalog_filters`, `partials/catalog_results`, `partials/menu`, `home`, компонент `x-product-row` |
| JS | `catalogFilters`, `megaMenu`, `mobileMenu` |

Ключевые правила:

| Правило | Суть |
|---|---|
| Видимость | На витрине только товары с `is_active` и `is_published`. Пустые категории в меню не попадают; счётчик категории включает подкатегории |
| BR-016 | У категории свой набор фильтров (`category_filters`), без него — набор ближайшего родителя, иначе стандартный: производитель, цена, наличие |
| BR-017 | Значения фильтра считаются по всей категории и не зависят от выбора в других фильтрах |
| BR-018 | Между фильтрами — И, между значениями одного фильтра — ИЛИ |
| BR-029, BR-030 | Фильтры, сортировка и страница хранятся в URL (`brand[]`, `price[from/to]`, `stock[]`, `a[ID][]`, `a[ID][from/to]`, `sort`, `page`) |
| Цены у гостя | Гостю фильтр по цене и сортировки по цене не показываются (`withPrices`) |
| BR-084 | «← Продолжить покупки» и крошки карточки возвращают к последнему списку с фильтрами (`CatalogController::CONTINUE_URL` в сессии) |
| Кэш меню | `CatalogMenuObserver` сбрасывает кэш при изменении категорий и товаров; после массовых `update()` — `CatalogMenu::flush()` |

Тесты: `CatalogFiltersTest`, `Storefront/CatalogMenuTest`, `Storefront/ParentCategoryTest`, `Storefront/ProductRowTest`, `Storefront/ContinueShoppingTest`, `Storefront/HeaderTest`.

---

## 2. Поиск

**Назначение.** Полнотекстовый поиск по товарам и живые подсказки в шапке.

| | |
|---|---|
| Маршруты | `GET /search?q=`, `GET /search/suggest?q=` (JSON) |
| Контроллер | `SearchController` (`index`, `suggest`) |
| Шаблоны | `search`, `x-product-row` |
| JS | `search-suggest.js` (шапка), `skuSuggest` (корзина, тот же `/search/suggest`) |

Ключевые правила:

| Правило | Суть |
|---|---|
| Поиск | Каждое слово запроса ищется в `sku`, `name`, `brand`, `description` (`LIKE`, без учёта регистра); слова через И |
| Порядок | Сначала точное совпадение артикула, затем по названию; по 24 на страницу |
| Подсказки | От 2 символов: до 5 товаров (без описания) и категории |
| Видимость | Только `is_active` и `is_published` |
| Тесты | SQLite не приводит кириллицу к нижнему регистру — в тестах поиска латиница |

Тесты: `SearchTest`.

---

## 3. Карточка товара

**Назначение.** Страница товара: галерея, цена с НДС, наличие, покупка, характеристики, описание.

| | |
|---|---|
| Маршрут | `GET /product/{id}` |
| Контроллер | `ProductController::show`, общие данные — `ProductController::cardData()` (их же использует предпросмотр в админке) |
| Модели | `Product` (`title()`, `gallery()`, `specRows()`), `ProductImage`, `ProductAttributeValue` |
| Шаблоны | `product`, `partials/product_gallery`; компоненты `x-breadcrumbs`, `x-stock`, `x-qty`, `x-product-badge`, `x-price`, `x-copy` (артикул) |
| JS | `productGallery`, `cart-actions.js` |

Ключевые правила:

| Правило | Суть |
|---|---|
| Доступ | 404, если товар скрыт, не опубликован или удалён |
| Цена | Только авторизованным; в БД без НДС, на карточке — «без НДС» и НДС отдельно; пустая цена — «Цена уточняется» |
| BR-051, BR-052 | Основное фото первым, остальные — в порядке из админки |
| BR-061, BR-062 | Характеристики в заданном порядке, пустые не выводятся |
| Наличие | `x-stock`: «Много» / «Мало» / «N шт.» по порогам `shop.stock_many` и `shop.stock_few`; при нуле — «Под заказ» (`on_order`) или «Нет в наличии» |
| Описание | Сгенерированное импортом (`description_generated`) на сайте не показывается до одобрения |
| Название | `display_name` → `name` → «БРЕНД артикул» (`Product::title()`) |
| Крошки | Цепочка категории самого товара (BR-084) |

Тесты: `Storefront/ProductPageTest`, `ProductImageStorefrontTest`, `ProductVatTest`.

---

## 4. Корзина и CartValidator

**Назначение.** Корзина клиента в БД; проверка позиций перед показом и оформлением.

| | |
|---|---|
| Маршруты (auth) | `GET /order`; `POST /order/add/{id}`, `/order/update/{id}`, `/order/delete/{id}`, `/order/select/{id}`, `/order/select-all`, `/order/clear`, `/order/quick-add`, `/order/accept-prices` |
| Контроллер | `CartController` |
| Сервисы | `CartValidator` → `CartValidationResult` (+ `CartProblem`), `CartSummary` (сводка для шапки и строк списков), `Vat\VatCalculator` |
| Модели | `Cart` (одна на пользователя), `CartItem` (`price_at_add`, `selected`, `product_name`) |
| Enum | `CartState` (Пустая / Требует корректировки / Требует обновления / Готова), `CartProblemType` (коды ERR-013…ERR-018) |
| Шаблоны | `order` (корзина и оформление), `x-cart-row`, `x-cart-quick-add`, `x-qty` |
| JS | `cart-page.js`, `cart-actions.js`, `qty-sync.js`, `skuSuggest` |

Ключевые правила:

| Правило | Суть |
|---|---|
| BR-121 | Корзина только у клиента; администратору вместо «В корзину» — переход к товару в админке |
| BR-099 | Корзина не резервирует остаток |
| BR-101 | Валидатор возвращает **все** проблемы всех позиций сразу, а не первую |
| BR-123…BR-125 | Проверки позиции: товар существует, активен, опубликован, есть цена, кратность (`min_qty`, `qty_step`), остаток, цена не изменилась с `price_at_add` |
| Состояние | Изменение цены — «Требует обновления», пользователь принимает новые цены кнопкой (`accept-prices`); остальное — «Требует корректировки», пользователь исправляет сам |
| Добавление | Проверяется итоговое количество (уже в корзине + добавляемое); строка корзины блокируется, параллельные добавления не дублируют позицию |
| «Добавить по артикулу» | Артикул + количество (пусто — `default_qty`); ошибки у полей |
| Выбор | В заказ идут только позиции с галочкой (`selected`); добавленная позиция отмечается автоматически |
| Ответы | При `Accept: application/json` — JSON со сводкой для шапки, иначе редирект назад |

Тесты: `CartValidatorTest`, `CartQuantityRuleTest`, `CartSelectionTest`, `CartOrderSplitTest`, `CartOrderSplitMigrationTest`, `Storefront/ListAddToCartTest`.

---

## 5. Оформление и CheckoutService

**Назначение.** Создание заказа из отмеченных позиций корзины в одной транзакции.

| | |
|---|---|
| Маршруты (auth) | `POST /order/checkout` (форма на странице корзины; `GET /order/checkout` → редирект в `/order`), `GET /order/{id}/placed` |
| Контроллеры | `CartController::checkout`, `OrderController::placed` |
| Сервисы | `CheckoutService::place`, `CartValidator`, `OrderStatusService::recordPlaced`, `Vat\VatCalculator` |
| Модели | `Order`, `OrderItem`, `DeliveryAddress`, `Product` |
| Исключение | `CheckoutException` (пустая корзина, ничего не выбрано, нет адреса, чужой адрес, перечень проблем) |
| Событие | `OrderPlaced` → письма администраторам |
| Шаблоны | `order`, `order_placed`, `partials/vat_totals`, `partials/order_delivery` |

Ключевые правила:

| Правило | Суть |
|---|---|
| BR-121 | Оформляет только клиент, только на свою организацию |
| BR-123, BR-124, BR-134 | Транзакция: блокировка корзины и товаров (в порядке id), повторная проверка, списание остатка, удаление оформленных позиций; неотмеченные остаются |
| BR-135 | Любая проблема отменяет оформление с полным перечнем причин |
| BR-128, BR-129 | Адрес доставки обязателен и должен принадлежать организации; в заказ копируется снимком |
| BR-132 | Номер `SM-ГГГГ-000001`, сквозной в пределах года (`order_sequences`, атомарный upsert) |
| Повтор формы | Одноразовый `checkout_token`: повторная отправка возвращает уже созданный заказ |
| НДС | Снимок по позициям: цена без НДС × количество, НДС по позиции с округлением до копеек, итог — сумма позиций; `vat_rate = null` — «Без НДС» |
| Комментарий | Необязателен, пустой сохраняется как `null` |

Тесты: `CheckoutValidationTest`, `CheckoutDeliveryTest`, `CheckoutOrganizationTest`, `OrderVatTest`, `Unit/MoneyTest`.

---

## 6. Заказы и статусы

**Назначение.** Жизненный цикл заказа, история, запрос клиента на отмену.

| | |
|---|---|
| Сервис | `OrderStatusService` (`change`, `requestCancel`, `approveCancel`, `rejectCancel`, `recordPlaced`) |
| Модели | `Order`, `OrderItem`, `OrderStatusHistory` (только добавляется) |
| Enum | `OrderStatus` (переходы, цвета), `OrderHistoryEvent` (запрос отмены, отказ) |
| Исключения | `InvalidStatusTransition`, `CancelRequestException` |
| Событие | `CancelRequested` → письма администраторам |
| Шаблоны | `partials/order_items`, `partials/order_history`, `partials/order_status`, `partials/cancel_request_badge` |

Переходы статусов (`OrderStatus::allowedTransitions`):

| Из | В |
|---|---|
| Новый | Подтверждён, Отменён |
| Подтверждён | Передан в 1С, Отменён |
| Передан в 1С | В работе |
| В работе | Отгружен |
| Отгружен | Выполнен |
| Выполнен, Отменён | — (финальные) |

Ключевые правила:

| Правило | Суть |
|---|---|
| SRS §10.7.5 | Каждая смена статуса и событие отмены пишется в `order_status_history` с автором и комментарием |
| Отмена | Возвращает остаток на склад (`stock_returned_at` защищает от повторного возврата) |
| Запрос отмены | Клиент сам не отменяет (BR-133: и не удаляет): запрос с причиной до 1000 символов, только оформивший, только в статусах «Новый» и «Подтверждён», один открытый запрос. Решает администратор: одобрить (→ Отменён) или отклонить с комментарием |
| Ручные статусы | `SHOP_ORDERS_MANUAL_STATUS=false` отключает смену статусов в админке (на будущее — статусы из 1С) |

Тесты: `OrderStatusTest`, `OrderCancelRequestTest`, `OrderStatusMigrationTest`, `OrderItemWithoutProductTest`.

---

## 7. Кабинет клиента

**Назначение.** Заказы организации, повтор заказа, адреса доставки, профиль.

| | |
|---|---|
| Маршруты (auth) | `GET /orders`, `GET /order/{id}`, `POST /order/{id}/cancel-request`, `POST /order/{id}/reorder`, `GET/PATCH /profile`, `PUT /password`, `/account` (→ `/orders`) |
| Маршруты (auth + `role:client`) | `GET /addresses`, `/addresses/create`, `/addresses/{id}/edit`; `POST /addresses`, `/addresses/{id}`, `/addresses/{id}/default`, `/addresses/{id}/delete` |
| Контроллеры | `OrderController`, `DeliveryAddressController`, `ProfileController` |
| Модели | `Order` (`visibleTo`), `DeliveryAddress` (`createFor`, правила полей общие с админкой) |
| Шаблоны | `orders` (+ `partials/orders_table`: активные и архив), `order_show`, `addresses`, `address_form`, `partials/address_list`, `partials/address_fields`, `profile/edit` |

Ключевые правила:

| Правило | Суть |
|---|---|
| BR-113, BR-114 | Клиент видит все заказы своей организации; без организации — только свои |
| Архив | Заказы в финальных статусах (Выполнен, Отменён) — отдельным списком |
| Повтор | `reorder` добавляет в корзину позиции старого заказа; недоступные и отсутствующие на складе пропускаются с предупреждением |
| UC-032 | Адреса — только своей организации; у организации ровно один адрес по умолчанию, пока адреса есть; первый адрес становится им сам; при удалении адреса по умолчанию им становится самый старый из оставшихся |
| Профиль | Удаления своей учётной записи нет: B2B-учётки блокирует администратор |

Тесты: `OrganizationOrdersTest`, `DeliveryAddressTest`, `ProfileTest`, `FormatsTest`.

---

## 8. Админка

Все маршруты — `routes/admin.php`, middleware `auth` + `role:admin`, layout `layouts/admin`. Меню: Товары, Фильтры каталога, Характеристики, Заказы, Клиенты, Пользователи. Общие тесты: `AdminLayoutTest`, `Middleware/EnsureUserHasRoleTest`, `Ui/*`.

### 8.1. Товары

| | |
|---|---|
| Маршруты | `GET /admin/products` (фильтры `?category=none`, `?name=none`, `?description=unapproved`), `GET /admin/product/create`, `POST /admin/product`, `GET/POST /admin/product/{id}`, `POST …/draft`, `…/draft/delete`, `…/publish`, `GET …/preview`, `POST …/approve-description`, `…/toggle-visibility`, `…/delete`, `…/images*` |
| Контроллеры | `AdminProductController`, `AdminProductImageController` (+ трейт `UploadsProductImages`) |
| Сервисы | `ProductAttributes`, `Images\ProductImageService`, `Vat\VatCalculator` |
| Модели | `Product`, `ProductDraft`, `ProductImage`, `ProductAttributeValue` |
| Шаблоны | `admin/products`, `admin/product_form`, `partials/admin_product_attributes`, `partials/admin_product_attribute_row`, `partials/admin_product_images`, `partials/image_queue`, `partials/vat_rate_select`; предпросмотр — витринный `product` |
| JS | `adminImages`, `imageQueue`, `attributeRows` |

| Правило | Суть |
|---|---|
| Три группы полей | Поля 1С (`ERP_FIELDS`) сохраняются сразу; категория, slug, ХИТ, НОВИНКА — сразу; тексты сайта (`DRAFT_FIELDS`) — в черновик, на сайт после «Опубликовать» |
| `manual_products` | При `false` поля 1С только для чтения, создание товара — 403 |
| `is_new_locked` | «Новинка», заданная в админке, фиксируется, и импорт её не меняет |
| Видимость | «Скрыть/показать» переключает `is_active`; удаление мягкое |
| Описание | Сгенерированное импортом показывается на сайте после «Одобрить» (`description_generated = false`) |
| Фото (BR-051…054) | webp `thumb` 300 / `medium` 800 / `large` 1600, ориентация по EXIF, оригинал не хранится; до 10 МБ, JPEG, PNG или WebP (MIME по содержимому); ровно одно основное; изменения сразу, без черновика |

Тесты: `AdminProductFormTest`, `AdminCreateProductTest`, `AdminDeleteProductTest`, `AdminProductValidationTest`, `AdminProductVisibilityTest`, `AdminProductBadgeFlowTest`, `AdminProductQtyBadgesTest`, `ProductDraftPublishTest`, `ProductImageAdminTest`, `ProductGuidTest`.

### 8.2. Характеристики

| | |
|---|---|
| Маршруты | `GET /admin/attributes`, `GET /admin/attribute/create`, `POST /admin/attribute`, `GET /admin/attribute/{id}/edit`, `POST /admin/attribute/{id}`, `POST /admin/attributes/merge`, `POST /admin/attribute/{id}/delete` |
| Контроллер | `AdminAttributeController` |
| Сервис | `ProductAttributes` (разбор строк формы, запись, объединение дублей) |
| Модели | `ProductAttribute` (таблица `attributes`), `ProductAttributeValue` |
| Шаблоны | `admin/attributes`, `admin/attribute_form` |

| Правило | Суть |
|---|---|
| Уникальность | Название уникально без учёта регистра, пробелов и двоеточия в конце (`name_key`): «Цвет», «цвет », «ЦВЕТ:» — одно и то же |
| Тип | Текст или число; для числа хранится `value_number` (фильтр «от–до») |
| Значения | До 1000 символов (`ProductAttributes::VALUE_MAX`); у товара один ключ один раз (BR-059), порядок строк задаётся в форме (BR-061) |
| Нечисловое в числовой | Неизменённое значение сохраняется как есть, у поля предупреждение; новое или изменённое должно быть числом |
| Флаги | `is_filterable` (доступна в фильтрах), `is_logistics`, `is_admin_only` |
| Удаление | Только неиспользуемых; используемую сначала объединить с другой (merge) |

Тесты: `ProductAttributesTest`.

### 8.3. Категории и фильтры каталога

Экрана редактирования дерева категорий в админке нет. Категории создаёт и переносит импорт каталога (`catalog:import-db`) и миграции данных; служебная скрытая категория «Без категории» (`Category::UNCATEGORIZED`, `is_hidden`) собирает неразложенные товары. Категорию товару админ задаёт в форме товара.

| | |
|---|---|
| Маршруты | `GET /admin/catalog-filters`, `GET /admin/catalog-filters/{id}`, `POST /admin/catalog-filters/{id}` |
| Контроллер | `AdminCatalogFilterController` |
| Сервис | `Catalog\CatalogFilters` (`forCategory`, `own`, `save`, `available`) |
| Модели | `Category`, `CategoryFilter` |
| Шаблоны | `admin/catalog_filters`, `admin/catalog_filter_form` |
| Правила | BR-016: состав и порядок фильтров категории; подкатегория без своей настройки наследует фильтры родителя; характеристика без `is_filterable` из набора выпадает |
| Команды | `categories:capitalize` (названия с заглавной), `catalog:group-categories` |

Тесты: `CatalogFiltersTest`, `CategoryNameTest`.

### 8.4. Клиенты (организации)

| | |
|---|---|
| Маршруты | `GET /admin/organizations`, `/admin/organization/create`, `/admin/organization/{id}`, `/admin/organization/{id}/edit`; `POST /admin/organization`, `/admin/organization/{id}`, `/admin/organization/{id}/delete`; адреса — `/admin/organization/{org}/address/…` (create, edit, default, delete) |
| Контроллеры | `AdminOrganizationController`, `AdminDeliveryAddressController` |
| Модели | `Organization`, `DeliveryAddress` |
| Шаблоны | `admin/organizations`, `admin/organization`, `admin/organization_form`, `admin/address_form` |
| Правила | SRS §10.6. Удалить можно только организацию без пользователей и заказов. `guid` (1С) только для просмотра. Правила адресов — как в кабинете (UC-032) |

Тесты: `AdminOrganizationTest`, `AdminDeliveryAddressTest`.

### 8.5. Пользователи и приглашения

| | |
|---|---|
| Маршруты | `GET /admin/users`, `/admin/user/create`, `/admin/user/{id}`, `/admin/user/{id}/edit`; `POST /admin/user`, `/admin/user/{id}`, `…/block`, `…/unblock`, `…/reset-password`, `…/notifications`, `…/invitation` |
| Контроллер | `AdminUserController` |
| Сервис | `UserInvitations` (`issue`, `pending`) |
| Нотификация | `UserInvitation` (наследует `ResetPassword`, брокер `invitations`, срок 72 ч) |
| Модели | `User`, таблица `user_invitation_tokens` |
| Шаблоны | `admin/users`, `admin/user`, `admin/user_form` |
| Консоль | `php artisan user:create` |

| Правило | Суть |
|---|---|
| SRS §10.5, BR-115 | Удаления нет — блокировка (`blocked_at`); заблокированный не входит, активная сессия завершается; себя заблокировать нельзя |
| BR-111 | Клиент обязан быть привязан к организации, администратор — нет |
| Создание | Пароль вручную или «Пригласить»: случайный пароль и письмо со ссылкой на установку пароля |
| Ссылка приглашения | В карточке, пока приглашение не принято: поле + `x-copy`, «Действует до ДД.ММ.ГГГГ ЧЧ:ММ». Токен хранится хешем, для показа — зашифрованная копия (`token_encrypted`) |
| Новая ссылка | «Выпустить новую ссылку» (`x-confirm`): старая перестаёт действовать, новая уходит письмом; у принятого приглашения и у пользователя с паролем — ошибка |
| Сброс пароля | «Сбросить пароль» — письмо со ссылкой; текущий пароль действует до смены |
| Уведомления | Галочки «Новый заказ» и «Запрос на отмену» — только у администраторов |

Тесты: `AdminUserTest`, `AdminUserInvitationLinkTest`, `Auth/InvitationBrokerTest`, `Auth/BlockedUserTest`, `Auth/*`, `CreateUserCommandTest`.

### 8.6. Заказы (админка)

| | |
|---|---|
| Маршруты | `GET /admin/orders` (поиск, фильтры, по 50), `GET /admin/order/{id}`, `POST /admin/order/{id}` (отметка «оплачен», `is_paid`), `…/status`, `…/cancel-request/approve`, `…/cancel-request/reject` |
| Контроллер | `AdminOrderController` |
| Сервис | `OrderStatusService` |
| Шаблоны | `admin/orders`, `admin/order` |
| Правила | Поиск по номеру, организации (название, УНП) и пользователю (имя, email); фильтр «только с открытым запросом на отмену». Статусы — по переходам из §6 |

Тесты: `AdminOrderSearchTest`, `AdminOrderPaginationTest`, `OrderStatusTest`, `OrderCancelRequestTest`.

---

## 9. Уведомления

**Назначение.** Письма администраторам о новых заказах и запросах на отмену; письма пользователям (приглашение, сброс пароля).

| | |
|---|---|
| События | `OrderPlaced`, `CancelRequested` (`ShouldDispatchAfterCommit`) |
| Слушатель | `NotifyAdmins` (автообнаружение) |
| Нотификации | `AdminOrderNotification` (база, `ShouldQueue`): `NewOrderNotification`, `CancelRequestNotification`; `UserInvitation` (без очереди) |
| Настройка | `users.notify_new_order`, `users.notify_cancel_request` — карточка администратора, `POST /admin/user/{id}/notifications` |
| Инфраструктура | `QUEUE_CONNECTION=database`, сервис `queue`; локально почта в Mailpit (`http://localhost:8025`), на стенде `MAIL_MAILER=log` |

| Правило | Суть |
|---|---|
| Получатели | Активные (не заблокированные) администраторы с включённой галочкой |
| Надёжность | Повтор через 1 и 5 минут, затем `failed_jobs`; сбой постановки в очередь не ломает оформление |
| Безопасность | Текст пользователя (комментарий, причина отмены, названия) в письме — одной строкой, разметка Markdown экранируется (`AdminOrderNotification::text`) |

Тесты: `AdminNotificationsTest`.

---

## 10. Импорт каталога

**Назначение.** Загрузка структуры каталога, текстов и характеристик из `catalog.db` (SQLite от поставщика) по маппингам заказчика. Подробно — `docs/CATALOG_ANALYSIS.md`.

| | |
|---|---|
| Команда | `catalog:import-db <path> --dry-run` (отчёт `docs/catalog/import-report.md` + `uncategorized.csv`), боевой — `--backup=<дамп>` |
| Сервисы | `CatalogImport\CatalogImportPlanner` (только чтение, строит план), `CatalogImportPlan`, `CatalogImportApplier` (применяет), `CatalogImportReport`, `CatalogMapping`, `ColumnLimits` |
| Маппинги | `database/data/catalog/`: `brands.php`, `categories.php` (`map`, `rules`, `split`, `products`), `attributes.php`, `filters.php`, `cleanup.php` |
| Прочие команды | `attributes:from-specifications` (текст `specifications` → справочник), `catalog:import`, `catalog:fill-test-data` (наследие), `demo:clear` |

| Правило | Суть |
|---|---|
| Ключ | `products.sku = catalog.sku` без учёта регистра |
| Идемпотентность | Повторный dry-run после применения даёт 0 изменений |
| Безопасность | `catalog.db` открывается только на чтение; боевой прогон — только с полным дампом (последняя строка `-- Dump completed`); длины проверяются до записи (`ColumnLimits`) |
| Чего не трогает | Цены, НДС, остатки, кратность (они из 1С); тексты сайта, если уже заполнены; «Новинку», зафиксированную в админке (`is_new_locked`) |
| Категория | Порядок: `products` (по sku) → `split` (по полю и regex, первое совпадение) → `map` / `rules`. Нет совпадения — товар остаётся на месте или попадает в служебную «без категории» |
| Новые товары | Создаются черновиками (`is_published = false`) без цены |
| Описания | Сгенерированные помечаются `description_generated` и не показываются до одобрения |
| Перенос данных | Ручные раскладки оформляются идемпотентной миграцией по артикулам; перед ней — бэкап (правило в `CLAUDE.md`) |

Тесты: `CatalogImport/CatalogImportCommandTest`, `CatalogImport/CatalogImportSiteTest`, `CatalogImport/CatalogMappingTest`, `CatalogImport/MisplacedProductsMigrationTest`, `ImportCatalogCommandTest`, `DemoClearTest`, `DemoSeederTest`.

---

## 11. Коды клиентов

**Назначение.** Собственные артикулы организации для наших товаров. Сейчас есть только структура данных; интерфейса нет, задачи — в `docs/BACKLOG.md`.

| | |
|---|---|
| Таблица | `product_client_codes` (`product_id`, `organization_id`, `code`); снимок в заказе — `order_items.client_code` (пока не заполняется) |
| Модель | `ProductClientCode`; связи `Product::clientCodes()`, `Organization::productCodes()` |
| API | `Product::codeFor($organization)`; для списков без N+1 — `Product::query()->withClientCodeFor($org)`, `Product::loadClientCodesFor($products, $org)` |
| Маршруты, шаблоны | Нет |

| Правило | Суть |
|---|---|
| Уникальность | Один код на товар у организации `(organization_id, product_id)`; код не повторяется у организации `(organization_id, code)` |
| Удаление | Каскадно вместе с товаром или организацией |
| Регистр | В MySQL `(organization_id, code)` не различает регистр, в SQLite различает — при импорте и в формах сравнивать без учёта регистра |
| В планах | Поиск по коду, «Ваш код» в списках, карточке, корзине и заказе, снимок при оформлении, блок в админке товара, загрузка из Excel |

Тесты: `ProductClientCodeTest`.
