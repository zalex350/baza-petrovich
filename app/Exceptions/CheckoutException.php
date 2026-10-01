<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Заказ не оформлен. $problems — полный перечень причин по позициям корзины
 * (BR-135); пуст, если причина одна и она уже в сообщении исключения.
 */
class CheckoutException extends RuntimeException
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(string $message, public readonly array $problems = [])
    {
        parent::__construct($message);
    }
}
