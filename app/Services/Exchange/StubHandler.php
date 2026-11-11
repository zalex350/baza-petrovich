<?php

namespace App\Services\Exchange;

use App\Models\ExchangeJob;

/**
 * Заглушка до реальных обработчиков (этапы 3–5): файл принят и сохранён в архиве,
 * данные сайта не меняются, задание — done с нулём строк.
 */
class StubHandler implements ExchangeHandler
{
    public function handle(ExchangeJob $job, string $path, ExchangeResult $result): void {}
}
