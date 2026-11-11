<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Подтверждение / отказ 1С невозможны (docs/1C_EXCHANGE_SPEC.md, раздел 6).
 * $code — код ошибки API (ответ 409): order_cancelled, guid_conflict, already_registered.
 */
class OrderExchangeException extends RuntimeException
{
    public const ORDER_CANCELLED = 'order_cancelled';

    public const GUID_CONFLICT = 'guid_conflict';

    public const ALREADY_REGISTERED = 'already_registered';

    public const NOT_REJECTED = 'not_rejected';

    private const MESSAGES = [
        self::ORDER_CANCELLED => 'Заказ отменён на сайте',
        self::GUID_CONFLICT => 'Заказ уже подтверждён с другим GUID 1С',
        self::ALREADY_REGISTERED => 'Заказ уже зарегистрирован в 1С',
        self::NOT_REJECTED => '1С не отклоняла заказ — отправлять повторно нечего',
    ];

    public function __construct(public readonly string $error)
    {
        parent::__construct(self::MESSAGES[$error] ?? $error);
    }
}
