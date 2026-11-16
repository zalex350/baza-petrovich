<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Планировщик: на стенде — сервис scheduler (php artisan schedule:work, docker-compose.staging.yml),
| локально — тот же сервис в docker-compose.yml.
*/

// Обмен с 1С: архив принятых файлов старше shop.exchange_1c.archive_days (90) — удалить
Schedule::command('exchange:prune')->dailyAt('04:10')->withoutOverlapping();

// Меню каталога: счётчики товаров и порядок категорий «по количеству» — пересчёт ночью
Schedule::command('catalog:menu-refresh')->dailyAt('03:50')->withoutOverlapping();

// Обмен с 1С: письма о давно не приходивших ценах и ждущих передачи заказах
Schedule::command('exchange:check')->everyTenMinutes()->withoutOverlapping();
