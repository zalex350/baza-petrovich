<?php

namespace App\Exceptions;

use DomainException;

/**
 * Запрос на отмену заказа нельзя создать или решить в текущем состоянии заказа.
 */
class CancelRequestException extends DomainException
{
    public const NOT_ALLOWED = 'Запросить отмену можно только для заказа в статусе «Новый» или «Подтверждён»';
    public const ALREADY_OPEN = 'Запрос на отмену уже отправлен и ждёт решения';
    public const NO_REQUEST = 'По заказу нет открытого запроса на отмену';
    public const TOO_LATE = 'Заказ уже передан в 1С — отменить его нельзя, запрос можно только отклонить';
}
