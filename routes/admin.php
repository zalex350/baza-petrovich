<?php

use App\Http\Controllers\Admin\AdminAttributeController;
use App\Http\Controllers\Admin\AdminBannerController;
use App\Http\Controllers\Admin\AdminCatalogFilterController;
use App\Http\Controllers\Admin\AdminCategoryController;
use App\Http\Controllers\Admin\AdminDeliveryAddressController;
use App\Http\Controllers\Admin\AdminOrderController;
use App\Http\Controllers\Admin\AdminOrganizationController;
use App\Http\Controllers\Admin\AdminPageController;
use App\Http\Controllers\Admin\AdminPartnerRequestController;
use App\Http\Controllers\Admin\AdminProductController;
use App\Http\Controllers\Admin\AdminProductImageController;
use App\Http\Controllers\Admin\AdminSettingsController;
use App\Http\Controllers\Admin\AdminUserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'role:admin'])->group(function () {

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

    // Счёт на оплату (SRS §8.10): загрузка PDF, удаление, проверка
    Route::post('/admin/order/{id}/invoice', [AdminOrderController::class, 'uploadInvoice']);
    Route::post('/admin/order/{id}/invoice/delete', [AdminOrderController::class, 'deleteInvoice']);
    Route::get('/admin/order/{id}/invoice', [AdminOrderController::class, 'downloadInvoice']);

    Route::get(
        '/admin/products',
        [AdminProductController::class, 'index']
    );

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
});
