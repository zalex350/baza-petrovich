<?php

namespace App\Exceptions;

use App\Enums\OrderStatus;
use DomainException;

class InvalidStatusTransition extends DomainException
{
    public function __construct(public readonly OrderStatus $from, public readonly OrderStatus $to)
    {
        parent::__construct(
            $from->isFinal()
                ? "Заказ в статусе «{$from->label()}» — изменить статус нельзя"
                : "Переход «{$from->label()}» → «{$to->label()}» недопустим"
        );
    }
}
