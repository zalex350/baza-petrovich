<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Обработчик задания обмена отказался от файла целиком (например, предохранитель mode=full):
 * задание failed с этим текстом, данные сайта не меняются. Ожидаемая ситуация — в лог не пишется.
 */
class ExchangeJobFailedException extends RuntimeException {}
