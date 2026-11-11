<?php

namespace App\Console\Commands;

use App\Services\Exchange\ExchangeAlerts;
use Illuminate\Console\Command;

/**
 * Проверка обмена с 1С по расписанию (раз в 10 минут): давно нет цен и остатков,
 * заказы ждут передачи — письма администраторам (ExchangeAlerts).
 */
class ExchangeCheck extends Command
{
    protected $signature = 'exchange:check';

    protected $description = 'Проверить обмен с 1С и разослать оповещения администраторам';

    public function handle(ExchangeAlerts $alerts): int
    {
        $alerts->check();

        return self::SUCCESS;
    }
}
