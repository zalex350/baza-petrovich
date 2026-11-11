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
            self::New => 'Новый',
            self::Confirmed => 'Подтверждён',
            self::SentTo1c => 'Передан в 1С',
            self::InProgress => 'В работе',
            self::Shipped => 'Отгружен',
            self::Completed => 'Выполнен',
            self::Cancelled => 'Отменён',
        };
    }

    /**
     * Tailwind-классы бейджа статуса — единая карта цветов для всех экранов
     * (x-badge :status, partials/order_status). Сами цвета — токены status.* в tailwind.config.js.
     */
    public function color(): string
    {
        return match ($this) {
            self::New => 'bg-status-new-bg text-status-new-text',
            self::Confirmed => 'bg-status-confirmed-bg text-status-confirmed-text',
            self::SentTo1c => 'bg-status-sent-bg text-status-sent-text',
            self::InProgress => 'bg-status-progress-bg text-status-progress-text',
            self::Shipped => 'bg-status-shipped-bg text-status-shipped-text',
            self::Completed => 'bg-status-completed-bg text-status-completed-text',
            self::Cancelled => 'bg-status-cancelled-bg text-status-cancelled-text',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::SentTo1c, self::Cancelled],
            self::SentTo1c => [self::InProgress],
            self::InProgress => [self::Shipped],
            self::Shipped => [self::Completed],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /**
     * Переход по данным 1С (docs/1C_EXCHANGE_SPEC.md, раздел 7): только вперёд по флоу,
     * промежуточные шаги можно пропускать; «Отменён» — из любого незавершённого статуса.
     */
    public function canTransitionFrom1c(self $to): bool
    {
        if ($this->isFinal()) {
            return false;
        }

        if ($to === self::Cancelled) {
            return true;
        }

        $flow = [self::New, self::Confirmed, self::SentTo1c, self::InProgress, self::Shipped, self::Completed];

        return array_search($to, $flow, true) > array_search($this, $flow, true);
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
