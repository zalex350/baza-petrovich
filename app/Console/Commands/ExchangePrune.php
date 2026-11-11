<?php

namespace App\Console\Commands;

use App\Models\ExchangeJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Архив обмена с 1С: файлы заданий старше shop.exchange_1c.archive_days (90 дней) удаляются,
 * запись журнала остаётся (file_path = null, file_pruned_at). Пустые папки ГГГГ/ММ — тоже.
 * По расписанию — ежедневно (routes/console.php).
 */
class ExchangePrune extends Command
{
    protected $signature = 'exchange:prune {--days= : срок хранения, по умолчанию shop.exchange_1c.archive_days}';

    protected $description = 'Удалить файлы обмена с 1С старше срока хранения (журнал остаётся)';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('shop.exchange_1c.archive_days', 90));
        $disk = Storage::disk('exchange');
        $count = 0;

        ExchangeJob::whereNotNull('file_path')
            ->where('received_at', '<', now()->subDays($days))
            ->chunkById(200, function ($jobs) use ($disk, &$count) {
                foreach ($jobs as $job) {
                    $disk->delete($job->file_path);
                    $job->update(['file_path' => null, 'file_pruned_at' => now()]);
                    $count++;
                }
            });

        foreach (array_reverse($disk->allDirectories()) as $dir) {
            if ($disk->allFiles($dir) === [] && $disk->directories($dir) === []) {
                $disk->deleteDirectory($dir);
            }
        }

        $this->info("Удалено файлов: {$count} (старше {$days} дн.)");

        return self::SUCCESS;
    }
}
