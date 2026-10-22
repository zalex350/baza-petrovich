<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Общее для писем администраторам о заказе: канал mail, ссылка в админку,
 * формат сумм и экранирование текста, введённого пользователем.
 */
abstract class AdminOrderNotification extends Notification
{
    public function __construct(public readonly Order $order) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    protected function orderUrl(): string
    {
        return url("/admin/order/{$this->order->id}");
    }

    protected function organizationLine(): string
    {
        $organization = $this->order->organization;

        if (! $organization) {
            return '—';
        }

        return self::text($organization->name.($organization->unp ? " (УНП {$organization->unp})" : ''));
    }

    protected static function userLine(?User $user): string
    {
        return $user ? self::text("{$user->name} ({$user->email})") : '—';
    }

    /**
     * 1 234,50 BYN — как x-price на экранах (пробелы неразрывные: сумма не переносится).
     */
    protected static function money(float|string|null $amount): string
    {
        return number_format((float) $amount, 2, ',', "\u{00A0}")."\u{00A0}BYN";
    }

    /**
     * Текст пользователя (комментарий, причина, названия) — без разметки Markdown:
     * строки письма рендерятся как Markdown, и «[ссылка](http://…)» стала бы ссылкой.
     * HTML (< > &) экранирует сам шаблон письма — их не трогаем, иначе в письме
     * будет видно «&lt;» (двойное экранирование).
     */
    protected static function text(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '—';
        }

        return preg_replace('/([\\\\`*_{}\[\]()#+\-.!|~])/u', '\\\\$1', $value);
    }
}
