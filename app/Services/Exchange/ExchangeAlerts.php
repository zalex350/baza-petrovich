<?php

namespace App\Services\Exchange;

use App\Enums\ExchangeJobStatus;
use App\Enums\ExchangeJobType;
use App\Enums\OrderExchangeState;
use App\Enums\OrderStatus;
use App\Models\ExchangeJob;
use App\Models\Order;
use App\Models\User;
use App\Notifications\ExchangeAlertNotification;
use App\Support\ExchangeSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Письма администраторам о сбоях обмена (docs/1C_EXCHANGE_SPEC.md, раздел 8; users.notify_exchange):
 *  - задание failed; ошибок в задании больше порога (по умолчанию 5 %) — сразу после обработки;
 *  - цены и остатки не приходили дольше N часов (после первой успешной загрузки, любой источник); заказ ждёт передачи дольше N минут
 *    (включается в настройках после подключения выгрузки заказов) — проверка по расписанию
 *    (exchange:check, раз в 10 минут).
 * Письмо одного вида — не чаще настройки «Повторять не чаще» (по умолчанию раз в час).
 */
class ExchangeAlerts
{
    public function afterJob(ExchangeJob $job): void
    {
        $url = url("/admin/exchange/job/{$job->id}");
        $what = $job->type->label().' ('.$job->received_at->format('d.m.Y H:i').')';

        if ($job->status === ExchangeJobStatus::Failed) {
            $this->send('job_failed', 'задание не обработано', [
                "**Задание:** {$what}",
                '**Причина:** '.$this->text($job->message),
                'Данные сайта не изменились.',
            ], $url);

            return;
        }

        $threshold = ExchangeSettings::errorRateShare();

        if ($job->status === ExchangeJobStatus::Done && $job->rows_error > 0 && $job->errorRate() > $threshold) {
            $this->send('error_rate', 'много ошибок в задании', [
                "**Задание:** {$what}",
                "**Ошибок:** {$job->rows_error} из {$job->rows_total} строк (порог — ".round($threshold * 100).' %)',
            ], $url);
        }
    }

    /** Проверка по расписанию: давно нет цен и остатков, заказы ждут передачи */
    public function check(): void
    {
        $hours = ExchangeSettings::int('exchange_stale_prices_hours');
        // Последняя успешная загрузка цен — из 1С или вручную в админке
        $lastPrices = ExchangeJob::where('type', ExchangeJobType::PricesStock)->where('status', ExchangeJobStatus::Done)->max('received_at');

        // Пока ни одной успешной загрузки цен (обмен не запущен) — молчим
        if ($lastPrices !== null && now()->diffInMinutes($lastPrices, true) > $hours * 60) {
            $this->send('stale_prices', "цены и остатки не приходили больше {$hours} ч", [
                '**Последняя загрузка:** '.Carbon::parse($lastPrices)->format('d.m.Y H:i'),
                'Проверьте расписание выгрузки в 1С и журнал обмена.',
            ], url('/admin/exchange'));
        }

        if (! ExchangeSettings::pendingOrdersAlert()) {
            return;
        }

        $minutes = ExchangeSettings::int('exchange_pending_orders_minutes');
        $pending = Order::where('exchange_state', OrderExchangeState::Pending)
            ->where('status', '!=', OrderStatus::Cancelled)
            ->where('placed_at', '<', now()->subMinutes($minutes));

        if (($count = $pending->count()) > 0) {
            $this->send('pending_orders', "заказы не переданы в 1С дольше {$minutes} мин", [
                "**Заказов:** {$count}, самый старый — ".$pending->orderBy('placed_at')->value('number'),
                'Проверьте, забирает ли 1С новые заказы (GET /orders/new).',
            ], url('/admin/orders'));
        }
    }

    /**
     * @param  list<string>  $lines
     */
    private function send(string $kind, string $subject, array $lines, string $url): void
    {
        $interval = ExchangeSettings::int('exchange_alert_interval_minutes');

        // Не чаще раза в интервал на вид письма; add — атомарно, второй процесс не отправит повтор
        if (! Cache::add("exchange-alert:{$kind}", true, now()->addMinutes($interval))) {
            return;
        }

        try {
            $admins = User::notifiedAdmins('notify_exchange')->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new ExchangeAlertNotification($subject, $lines, $url));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function text(?string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));

        return $value === '' ? '—' : preg_replace('/([\\\\`*_\[\]~])/u', '\\\\$1', $value);
    }
}
