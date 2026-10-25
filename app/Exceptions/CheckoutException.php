<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Заказ не оформлен. $problems — полный перечень причин по позициям корзины
 * (BR-135); пуст, если причина одна и она уже в сообщении исключения.
 * $errorCode — код ошибки по SRS, пользователю не показывается.
 */
class CheckoutException extends RuntimeException
{
    // Тексты ошибок по SRS
    public const HAS_PROBLEMS = 'Оформление заказа невозможно до устранения выявленных ошибок'; // ERR-020

    public const EMPTY_CART = 'Корзина пуста';                                                  // ERR-028

    public const NOTHING_SELECTED = 'Отметьте позиции, которые войдут в заказ';                 // не из SRS: выбор позиций

    public const CREATION_FAILED = 'Ошибка создания заказа';                                    // ERR-033

    // ERR-032 — текст из чек-листа задачи; BR-129 — формулировка не из SRS
    public const ADDRESS_REQUIRED = 'Не выбран адрес доставки';                                 // ERR-032, BR-128

    public const ADDRESS_FOREIGN = 'Выбранный адрес доставки недоступен. Выберите адрес своей организации'; // BR-129

    /**
     * @param  list<string>  $problems
     */
    public function __construct(
        string $message,
        public readonly array $problems = [],
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  list<string>  $problems
     */
    public static function hasProblems(array $problems): self
    {
        return new self(self::HAS_PROBLEMS, $problems, 'ERR-020');
    }

    public static function emptyCart(): self
    {
        return new self(self::EMPTY_CART, [], 'ERR-028');
    }

    public static function nothingSelected(): self
    {
        return new self(self::NOTHING_SELECTED, [], 'ERR-028');
    }

    public static function addressRequired(): self
    {
        return new self(self::ADDRESS_REQUIRED, [], 'ERR-032');
    }

    public static function addressForeign(): self
    {
        return new self(self::ADDRESS_FOREIGN, [], 'ERR-032');
    }
}
