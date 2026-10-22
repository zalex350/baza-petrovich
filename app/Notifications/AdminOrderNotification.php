<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Общее для писем администраторам о заказе: канал mail, ссылка в админку,
 * формат сумм и экранирование текста, введённого пользователем.
 *
 * Отправка — через очередь (QUEUE_CONNECTION=database, воркер — сервис queue):
 * по задаче на каждого получателя, только после коммита транзакции; при сбое SMTP —
 * повтор через 1 и 5 минут, затем задача в failed_jobs (php artisan queue:failed).
 */
abstract class AdminOrderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Заказ удалён до отправки — задача снимается без ошибки */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Order $order)
    {
        $this->afterCommit();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

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
        // Одна строка: переносы клиента не дают блочной разметки («# …», «- …», «> …»)
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));

        if ($value === '') {
            return '—';
        }

        // Экранируется только то, что даёт разметку внутри строки: ссылки и выделение.
        // Точки, скобки, дефисы не трогаем — иначе «\.» и «\(» видны в текстовой версии письма
        return preg_replace('/([\\\\`*_\[\]~])/u', '\\\\$1', $value);
    }
}
