<?php

use App\Http\Controllers\Admin\AdminAttributeController;
use App\Http\Controllers\Admin\AdminBannerController;
use App\Http\Controllers\Admin\AdminCatalogFilterController;
use App\Http\Controllers\Admin\AdminCategoryController;
use App\Http\Controllers\Admin\AdminClientCodeController;
use App\Http\Controllers\Admin\AdminDeliveryAddressController;
use App\Http\Controllers\Admin\AdminExchangeController;
use App\Http\Controllers\Admin\AdminImageImportController;
use App\Http\Controllers\Admin\AdminNewFrom1cController;
use App\Http\Controllers\Admin\AdminOrderController;
use App\Http\Controllers\Admin\AdminOrganizationController;
use App\Http\Controllers\Admin\AdminPageController;
use App\Http\Controllers\Admin\AdminPartnerRequestController;
use App\Http\Controllers\Admin\AdminPriceTypeController;
use App\Http\Controllers\Admin\AdminProductController;
use App\Http\Controllers\Admin\AdminProductDocumentController;
use App\Http\Controllers\Admin\AdminProductImageController;
use App\Http\Controllers\Admin\AdminSearchSynonymController;
use App\Http\Controllers\Admin\AdminSettingsController;
use App\Http\Controllers\Admin\AdminUserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'role:admin'])->group(function () {

    // Синонимы поиска: правила строкой, «Проверить запрос» (?check=)
    Route::get('/admin/search-synonyms', [AdminSearchSynonymController::class, 'index']);
    Route::post('/admin/search-synonyms', [AdminSearchSynonymController::class, 'store']);
    Route::post('/admin/search-synonym/{id}', [AdminSearchSynonymController::class, 'update'])->whereNumber('id');
    Route::post('/admin/search-synonym/{id}/delete', [AdminSearchSynonymController::class, 'destroy'])->whereNumber('id');

    // Фильтры каталога по категориям (SRS §3.9, BR-016)
    Route::get('/admin/catalog-filters', [AdminCatalogFilterController::class, 'index']);
    Route::get('/admin/catalog-filters/{id}', [AdminCatalogFilterController::class, 'edit']);
    Route::post('/admin/catalog-filters/{id}', [AdminCatalogFilterController::class, 'update']);

    // Справочник характеристик (SRS §4.9, §4.18)
    Route::get('/admin/attributes', [AdminAttributeController::class, 'index']);
    Route::get('/admin/attribute/create', [AdminAttributeController::class, 'create']);
    Route::post('/admin/attribute', [AdminAttributeController::class, 'store']);
    Route::post('/admin/attributes/merge', [AdminAttributeController::class, 'merge']);
    Route::get('/admin/attribute/{id}/edit', [AdminAttributeController::class, 'edit']);
    Route::post('/admin/attribute/{id}', [AdminAttributeController::class, 'update']);
    Route::post('/admin/attribute/{id}/delete', [AdminAttributeController::class, 'destroy']);

    // Документы товара (SRS §4.8): загрузка PDF, название и тип, порядок, удаление
    Route::post('/admin/product/{id}/documents', [AdminProductDocumentController::class, 'store']);
    Route::post('/admin/product/{id}/documents/reorder', [AdminProductDocumentController::class, 'reorder']);
    Route::post('/admin/product/{id}/documents/{document}', [AdminProductDocumentController::class, 'update']);
    Route::post('/admin/product/{id}/documents/{document}/delete', [AdminProductDocumentController::class, 'destroy']);

    // Коды товара у клиентов (product_client_codes): добавить, изменить, удалить
    Route::post('/admin/product/{id}/client-codes', [AdminClientCodeController::class, 'store']);
    Route::post('/admin/product/{id}/client-codes/{code}', [AdminClientCodeController::class, 'update']);
    Route::post('/admin/product/{id}/client-codes/{code}/delete', [AdminClientCodeController::class, 'destroy']);

    // Фото товара (SRS §10.4.8): загрузка, порядок, основное, замена, удаление
    Route::post(
        '/admin/product/{id}/images',
        [AdminProductImageController::class, 'store']
    );

    Route::post(
        '/admin/product/{id}/images/reorder',
        [AdminProductImageController::class, 'reorder']
    );

    Route::post(
        '/admin/product/{id}/images/{image}/main',
        [AdminProductImageController::class, 'makeMain']
    );

    Route::post(
        '/admin/product/{id}/images/{image}/move',
        [AdminProductImageController::class, 'move']
    );

    Route::post(
        '/admin/product/{id}/images/{image}/replace',
        [AdminProductImageController::class, 'replace']
    );

    Route::post(
        '/admin/product/{id}/images/{image}/delete',
        [AdminProductImageController::class, 'destroy']
    );

    Route::get(
        '/admin/orders',
        [AdminOrderController::class, 'index']
    );

    Route::get(
        '/admin/order/{id}',
        [AdminOrderController::class, 'show']
    );

    Route::post(
        '/admin/order/{id}',
        [AdminOrderController::class, 'update']
    );

    Route::post(
        '/admin/order/{id}/status',
        [AdminOrderController::class, 'changeStatus']
    );

    // Решение по запросу клиента на отмену
    Route::post(
        '/admin/order/{id}/cancel-request/approve',
        [AdminOrderController::class, 'approveCancel']
    );

    Route::post(
        '/admin/order/{id}/cancel-request/reject',
        [AdminOrderController::class, 'rejectCancel']
    );

    // Заказ, отклонённый 1С: отменить (резерв снимается) или отправить в 1С повторно
    Route::post('/admin/order/{id}/exchange/cancel', [AdminOrderController::class, 'cancelRejected']);
    Route::post('/admin/order/{id}/exchange/resend', [AdminOrderController::class, 'resendTo1c']);

    // Счёт на оплату (SRS §8.10): загрузка PDF, удаление, проверка
    Route::post('/admin/order/{id}/invoice', [AdminOrderController::class, 'uploadInvoice']);
    Route::post('/admin/order/{id}/invoice/delete', [AdminOrderController::class, 'deleteInvoice']);
    Route::get('/admin/order/{id}/invoice', [AdminOrderController::class, 'downloadInvoice']);

    Route::get(
        '/admin/products',
        [AdminProductController::class, 'index']
    );

    // Массовая загрузка фото: ZIP или CSV «код;ссылка» → предпросмотр → очередь; журнал (BulkImages)
    // Типы цен: справочник, «по умолчанию» (PriceTypes)
    Route::get('/admin/price-types', [AdminPriceTypeController::class, 'index']);
    Route::post('/admin/price-types', [AdminPriceTypeController::class, 'store']);
    Route::post('/admin/price-type/{id}', [AdminPriceTypeController::class, 'update'])->whereNumber('id');
    Route::post('/admin/price-type/{id}/default', [AdminPriceTypeController::class, 'makeDefault'])->whereNumber('id');

    Route::get('/admin/products/images-import', [AdminImageImportController::class, 'index']);
    Route::post('/admin/products/images-import', [AdminImageImportController::class, 'upload']);
    Route::get('/admin/products/images-import/{id}', [AdminImageImportController::class, 'show'])->whereNumber('id');
    Route::post('/admin/products/images-import/{id}/apply', [AdminImageImportController::class, 'apply'])->whereNumber('id');
    Route::post('/admin/products/images-import/{id}/discard', [AdminImageImportController::class, 'discard'])->whereNumber('id');
    Route::get('/admin/products/images-import/{id}/status', [AdminImageImportController::class, 'status'])->whereNumber('id');
    Route::get('/admin/products/images-import/{id}/not-found.csv', [AdminImageImportController::class, 'notFound'])->whereNumber('id');

    // Новые из 1С: черновики обмена номенклатуры — категория и публикация массово
    Route::get('/admin/products/new-1c', [AdminNewFrom1cController::class, 'index']);
    Route::post('/admin/products/new-1c', [AdminNewFrom1cController::class, 'update']);

    Route::get(
        '/admin/product/create',
        [AdminProductController::class, 'create']
    );

    Route::post(
        '/admin/product',
        [AdminProductController::class, 'store']
    );

    Route::get(
        '/admin/product/{id}/preview',
        [AdminProductController::class, 'preview']
    );

    Route::post(
        '/admin/product/{id}/draft',
        [AdminProductController::class, 'saveDraft']
    );

    Route::post(
        '/admin/product/{id}/publish',
        [AdminProductController::class, 'publish']
    );

    Route::post(
        '/admin/product/{id}/draft/delete',
        [AdminProductController::class, 'deleteDraft']
    );

    Route::get(
        '/admin/product/{id}',
        [AdminProductController::class, 'show']
    );

    Route::post(
        '/admin/product/{id}',
        [AdminProductController::class, 'update']
    );

    // Сгенерированное описание (импорт каталога) — показать на сайте
    Route::post(
        '/admin/product/{id}/approve-description',
        [AdminProductController::class, 'approveDescription']
    );

    // Бейджи ХИТ / НОВИНКА / Акция — сразу по галочке (JSON)
    Route::post(
        '/admin/product/{id}/badge',
        [AdminProductController::class, 'badge']
    );

    Route::post(
        '/admin/product/{id}/toggle-visibility',
        [AdminProductController::class, 'toggleVisibility']
    );

    Route::post(
        '/admin/product/{id}/delete',
        [AdminProductController::class, 'destroy']
    );

    Route::get(
        '/admin/organizations',
        [AdminOrganizationController::class, 'index']
    );

    Route::get(
        '/admin/organization/create',
        [AdminOrganizationController::class, 'create']
    );

    Route::post(
        '/admin/organization',
        [AdminOrganizationController::class, 'store']
    );

    Route::get(
        '/admin/organization/{id}',
        [AdminOrganizationController::class, 'show']
    );

    Route::get(
        '/admin/organization/{id}/edit',
        [AdminOrganizationController::class, 'edit']
    );

    Route::post(
        '/admin/organization/{id}',
        [AdminOrganizationController::class, 'update']
    );

    Route::post(
        '/admin/organization/{id}/delete',
        [AdminOrganizationController::class, 'destroy']
    );

    Route::get(
        '/admin/organization/{organization}/address/create',
        [AdminDeliveryAddressController::class, 'create']
    );

    Route::post(
        '/admin/organization/{organization}/address',
        [AdminDeliveryAddressController::class, 'store']
    );

    Route::get(
        '/admin/organization/{organization}/address/{id}/edit',
        [AdminDeliveryAddressController::class, 'edit']
    );

    Route::post(
        '/admin/organization/{organization}/address/{id}',
        [AdminDeliveryAddressController::class, 'update']
    );

    Route::post(
        '/admin/organization/{organization}/address/{id}/default',
        [AdminDeliveryAddressController::class, 'makeDefault']
    );

    Route::post(
        '/admin/organization/{organization}/address/{id}/delete',
        [AdminDeliveryAddressController::class, 'destroy']
    );

    // Коды товаров клиента (вкладка «Коды товаров»): удаление, загрузка из Excel / CSV с предпросмотром
    Route::get('/admin/organization/{id}/codes/template', [AdminClientCodeController::class, 'template']);
    Route::post('/admin/organization/{id}/codes/import', [AdminClientCodeController::class, 'upload']);
    Route::get('/admin/organization/{id}/codes/import', [AdminClientCodeController::class, 'preview']);
    Route::post('/admin/organization/{id}/codes/import/apply', [AdminClientCodeController::class, 'apply']);
    Route::post('/admin/organization/{id}/codes/import/cancel', [AdminClientCodeController::class, 'cancel']);
    Route::post('/admin/organization/{id}/codes/{code}/delete', [AdminClientCodeController::class, 'destroyForOrganization']);

    Route::get(
        '/admin/users',
        [AdminUserController::class, 'index']
    );

    Route::get(
        '/admin/user/create',
        [AdminUserController::class, 'create']
    );

    Route::post(
        '/admin/user',
        [AdminUserController::class, 'store']
    );

    Route::get(
        '/admin/user/{id}',
        [AdminUserController::class, 'show']
    );

    Route::get(
        '/admin/user/{id}/edit',
        [AdminUserController::class, 'edit']
    );

    Route::post(
        '/admin/user/{id}',
        [AdminUserController::class, 'update']
    );

    Route::post(
        '/admin/user/{id}/block',
        [AdminUserController::class, 'block']
    );

    Route::post(
        '/admin/user/{id}/unblock',
        [AdminUserController::class, 'unblock']
    );

    Route::post(
        '/admin/user/{id}/reset-password',
        [AdminUserController::class, 'resetPassword']
    );

    Route::post(
        '/admin/user/{id}/notifications',
        [AdminUserController::class, 'updateNotifications']
    );

    Route::post(
        '/admin/user/{id}/invitation',
        [AdminUserController::class, 'reissueInvitation']
    );

    // Категории: картинка (плитки подкатегорий)
    Route::get('/admin/categories', [AdminCategoryController::class, 'index']);
    // Превью иконок категорий (проверка набора и соответствий)
    Route::get('/admin/category-icons', [AdminCategoryController::class, 'icons']);
    Route::get('/admin/category/create', [AdminCategoryController::class, 'create']);
    Route::post('/admin/category', [AdminCategoryController::class, 'store']);
    Route::get('/admin/category/{id}/edit', [AdminCategoryController::class, 'edit']);
    Route::post('/admin/category/{id}', [AdminCategoryController::class, 'update']);
    Route::post('/admin/category/{id}/move', [AdminCategoryController::class, 'move']);
    // Порядок категорий: режим (по количеству / вручную / по алфавиту) и «вернуть автопорядок»
    Route::post('/admin/categories/order-mode', [AdminCategoryController::class, 'orderMode']);
    // Фото на плитках разделов: только выбранные вручную / автоматически — самый продаваемый товар
    Route::post('/admin/categories/tile-photos', [AdminCategoryController::class, 'tilePhotos']);
    Route::post('/admin/category/{id}/unpin', [AdminCategoryController::class, 'unpin']);
    Route::post('/admin/category/{id}/toggle-visibility', [AdminCategoryController::class, 'toggleVisibility']);
    Route::post('/admin/category/{id}/delete', [AdminCategoryController::class, 'destroy']);
    Route::post('/admin/category/{id}/image', [AdminCategoryController::class, 'updateImage']);
    Route::post('/admin/category/{id}/image/delete', [AdminCategoryController::class, 'deleteImage']);

    // Баннеры слайдера на главной
    Route::get('/admin/banners', [AdminBannerController::class, 'index']);
    Route::get('/admin/banner/create', [AdminBannerController::class, 'create']);
    Route::post('/admin/banner', [AdminBannerController::class, 'store']);
    Route::get('/admin/banner/{id}/edit', [AdminBannerController::class, 'edit']);
    Route::post('/admin/banner/{id}', [AdminBannerController::class, 'update']);
    Route::post('/admin/banner/{id}/toggle', [AdminBannerController::class, 'toggle']);
    Route::post('/admin/banner/{id}/delete', [AdminBannerController::class, 'destroy']);

    // Информационные страницы
    Route::get('/admin/pages', [AdminPageController::class, 'index']);
    Route::get('/admin/page/create', [AdminPageController::class, 'create']);
    Route::post('/admin/page', [AdminPageController::class, 'store']);
    Route::get('/admin/page/{id}', [AdminPageController::class, 'edit']);
    Route::post('/admin/page/{id}', [AdminPageController::class, 'update']);
    Route::post('/admin/page/{id}/delete', [AdminPageController::class, 'destroy']);

    // Настройки: реквизиты продавца, тексты подвала
    Route::get('/admin/settings', [AdminSettingsController::class, 'edit']);
    Route::post('/admin/settings', [AdminSettingsController::class, 'update']);

    // Заявки «Стать партнёром»
    Route::get('/admin/partners', [AdminPartnerRequestController::class, 'index']);
    Route::post('/admin/partner/{id}/status', [AdminPartnerRequestController::class, 'updateStatus']);

    // Обмен с 1С: журнал, задание и его файл, токены API, белый список IP, настройки
    Route::get('/admin/exchange', [AdminExchangeController::class, 'index']);
    Route::get('/admin/exchange/job/{id}', [AdminExchangeController::class, 'job']);
    Route::get('/admin/exchange/job/{id}/file', [AdminExchangeController::class, 'file']);
    Route::post('/admin/exchange/tokens', [AdminExchangeController::class, 'storeToken']);
    Route::post('/admin/exchange/token/{id}/revoke', [AdminExchangeController::class, 'revokeToken'])->whereNumber('id');
    Route::post('/admin/exchange/ips', [AdminExchangeController::class, 'storeIp']);
    Route::post('/admin/exchange/ip/{id}/delete', [AdminExchangeController::class, 'deleteIp'])->whereNumber('id');
    Route::post('/admin/exchange/settings', [AdminExchangeController::class, 'saveSettings']);
    // Ручная загрузка цен и остатков: файл → предпросмотр → применить / отменить; шаблон
    Route::post('/admin/exchange/prices', [AdminExchangeController::class, 'uploadPrices']);
    Route::get('/admin/exchange/prices/preview', [AdminExchangeController::class, 'previewPrices']);
    Route::post('/admin/exchange/prices/apply', [AdminExchangeController::class, 'applyPrices']);
    Route::post('/admin/exchange/prices/cancel', [AdminExchangeController::class, 'cancelPrices']);
    Route::get('/admin/exchange/prices/template', [AdminExchangeController::class, 'pricesTemplate']);
    Route::post('/admin/exchange/catalog', [AdminExchangeController::class, 'uploadCatalog']);
    Route::get('/admin/exchange/catalog/preview', [AdminExchangeController::class, 'previewCatalog']);
    Route::post('/admin/exchange/catalog/apply', [AdminExchangeController::class, 'applyCatalog']);
    Route::post('/admin/exchange/catalog/cancel', [AdminExchangeController::class, 'cancelCatalog']);
    Route::get('/admin/exchange/catalog/template', [AdminExchangeController::class, 'catalogTemplate']);
});
