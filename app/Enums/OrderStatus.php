<?php

namespace App\Enums;

/**
 * Статусы заказа (SRS §10.7). Флоу:
 * Новый → Подтверждён → Передан в 1С → В работе → Отгружен → Выполнен;
 * Отменён — из любого статуса до «Передан в 1С» (то есть из «Новый» и «Подтверждён»).
 */
enum OrderStatus: string
{
    case New = 'new';
    case Confirmed = 'confirmed';
    case SentTo1c = 'sent_to_1c';
    case InProgress = 'in_progress';
    case Shipped = 'shipped';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::New        => 'Новый',
            self::Confirmed  => 'Подтверждён',
            self::SentTo1c   => 'Передан в 1С',
            self::InProgress => 'В работе',
            self::Shipped    => 'Отгружен',
            self::Completed  => 'Выполнен',
            self::Cancelled  => 'Отменён',
        };
    }

    /**
     * Tailwind-классы бейджа статуса.
     */
    public function color(): string
    {
        return match ($this) {
            self::New        => 'bg-blue-100 text-blue-700',
            self::Confirmed  => 'bg-cyan-100 text-cyan-700',
            self::SentTo1c   => 'bg-indigo-100 text-indigo-700',
            self::InProgress => 'bg-yellow-100 text-yellow-700',
            self::Shipped    => 'bg-purple-100 text-purple-700',
            self::Completed  => 'bg-green-100 text-green-700',
            self::Cancelled  => 'bg-slate-200 text-slate-600',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New        => [self::Confirmed, self::Cancelled],
            self::Confirmed  => [self::SentTo1c, self::Cancelled],
            self::SentTo1c   => [self::InProgress],
            self::InProgress => [self::Shipped],
            self::Shipped    => [self::Completed],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /**
     * Заказ завершён: дальнейших переходов нет.
     */
    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
