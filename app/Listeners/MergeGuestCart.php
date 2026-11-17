<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\CurrentCart;
use Illuminate\Auth\Events\Login;

/**
 * Вход клиента: гостевая корзина розницы (cookie) переносится в его корзину (CurrentCart::mergeGuestInto).
 */
class MergeGuestCart
{
    public function __construct(private readonly CurrentCart $cart) {}

    public function handle(Login $event): void
    {
        if ($event->user instanceof User) {
            $this->cart->mergeGuestInto($event->user);
        }
    }
}
