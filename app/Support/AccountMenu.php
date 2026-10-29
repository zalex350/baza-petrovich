<?php

namespace App\Support;

use App\Models\User;

/**
 * Разделы личного кабинета (SRS §8) — один список для меню кабинета (layouts/account),
 * меню пользователя в шапке и панели ☰ на телефоне. Разделы организации — только клиенту
 * с организацией, адреса — только клиенту; администратору — профиль и пароль
 * (заказов и корзины у него нет, BR-121).
 */
class AccountMenu
{
    /**
     * @return list<array{label: string, url: string, icon: string, active: bool}>
     */
    public static function items(User $user): array
    {
        $client = $user->isClient();
        $organization = $client && $user->organization_id;

        return array_values(array_filter([
            $client ? ['label' => 'Мои заказы', 'url' => '/orders', 'icon' => 'heroicon-o-clipboard-document-list', 'active' => request()->is('orders') || request()->is('order/*')] : null,
            $organization ? ['label' => 'Организация', 'url' => '/account/organization', 'icon' => 'heroicon-o-building-office-2', 'active' => request()->is('account/organization')] : null,
            $organization ? ['label' => 'Пользователи организации', 'url' => '/account/users', 'icon' => 'heroicon-o-users', 'active' => request()->is('account/users')] : null,
            $client ? ['label' => 'Адреса доставки', 'url' => '/addresses', 'icon' => 'heroicon-o-map-pin', 'active' => request()->is('addresses*')] : null,
            ['label' => 'Профиль', 'url' => '/profile', 'icon' => 'heroicon-o-user', 'active' => request()->is('profile')],
            ['label' => 'Смена пароля', 'url' => '/account/password', 'icon' => 'heroicon-o-key', 'active' => request()->is('account/password')],
        ]));
    }

    /** Открыт какой-либо раздел кабинета — подсветка в шапке (BR-008) */
    public static function isActive(User $user): bool
    {
        return collect(self::items($user))->contains('active', true);
    }
}
