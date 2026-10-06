<?php

namespace Tests;

use App\Models\DeliveryAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Данные формы оформления заказа: адрес доставки обязателен (BR-128),
     * поэтому у организации пользователя заводится адрес, если его ещё нет.
     * Пользователь без организации получает пустую форму — как в жизни.
     */
    protected function checkoutData(User $user, array $extra = []): array
    {
        $organization = $user->organization;

        if (! $organization) {
            return $extra;
        }

        $address = $organization->deliveryAddresses()->first()
            ?? DeliveryAddress::createFor($organization, [
                'title'   => 'Основной склад',
                'address' => 'г. Минск, ул. Промышленная, 1',
            ]);

        return ['delivery_address_id' => $address->id] + $extra;
    }
}
