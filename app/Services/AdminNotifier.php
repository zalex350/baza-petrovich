<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Notifications\CancelRequestNotification;
use App\Notifications\NewOrderNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as Notifications;
use Throwable;

/**
 * Email-уведомления администраторам (users.notify_*). Письма уходят после фиксации
 * транзакции — откат не рассылает писем о несуществующем заказе; сбой почты не ломает
 * оформление заказа или запрос на отмену: ошибка пишется в лог.
 */
class AdminNotifier
{
    public function newOrder(Order $order): void
    {
        $this->send('notify_new_order', fn () => new NewOrderNotification($order));
    }

    public function cancelRequested(Order $order): void
    {
        $this->send('notify_cancel_request', fn () => new CancelRequestNotification($order));
    }

    /**
     * @param  callable(): Notification  $notification
     */
    private function send(string $setting, callable $notification): void
    {
        DB::afterCommit(function () use ($setting, $notification) {
            $admins = User::notifiedAdmins($setting)->get();

            foreach ($admins as $admin) {
                try {
                    Notifications::send($admin, $notification());
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });
    }
}
