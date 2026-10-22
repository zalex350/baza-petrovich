<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Клиент оформил заказ. Слушатели получают событие только после коммита транзакции
 * оформления: при откате (нехватка остатка и т.п.) события нет.
 */
class OrderPlaced implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Order $order) {}
}
