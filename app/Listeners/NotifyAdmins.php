<?php

namespace App\Listeners;

use App\Events\CancelRequested;
use App\Events\OrderPlaced;
use App\Models\User;
use App\Notifications\CancelRequestNotification;
use App\Notifications\NewOrderNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifications;
use Throwable;

/**
 * Email-уведомления администраторам (users.notify_*): получатели — активные admin
 * с включённой настройкой. Письма ставятся в очередь (уведомления — ShouldQueue),
 * отправляет воркер (сервис queue). Сбой постановки в очередь пишется в лог и не
 * влияет на оформление заказа или запрос на отмену.
 *
 * Регистрация — автообнаружение слушателей Laravel (методы handle* с типом события).
 */
class NotifyAdmins
{
    public function handleOrderPlaced(OrderPlaced $event): void
    {
        $this->send('notify_new_order', new NewOrderNotification($event->order));
    }

    public function handleCancelRequested(CancelRequested $event): void
    {
        $this->send('notify_cancel_request', new CancelRequestNotification($event->order));
    }

    private function send(string $setting, Notification $notification): void
    {
        try {
            $admins = User::notifiedAdmins($setting)->get();

            if ($admins->isNotEmpty()) {
                Notifications::send($admins, $notification);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
