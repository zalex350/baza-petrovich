<?php

namespace App\Models;

use App\Enums\InvoiceSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Счёт на оплату заказа (SRS §8.10, UC-035). Файл — в закрытом хранилище (диск DISK),
 * отдаётся только через контроллер с проверкой доступа к заказу. Один счёт на заказ:
 * новый (из админки или из 1С — attach()) заменяет прежний, старый файл удаляется.
 */
class OrderInvoice extends Model
{
    public const DISK = 'local';

    public const DIRECTORY = 'invoices';

    /** ERR-024 */
    public const NOT_READY = 'Счёт ещё не сформирован';

    /** Максимальный размер PDF, КБ */
    public const MAX_KB = 10240;

    protected $fillable = ['order_id', 'source', 'external_id', 'path', 'original_name', 'size', 'uploaded_by'];

    protected $casts = [
        'source' => InvoiceSource::class,
        'size' => 'integer',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Прикрепить счёт к заказу (заменяя прежний). Для обмена с 1С — source = Erp и external_id.
     */
    public static function attach(Order $order, UploadedFile $file, InvoiceSource $source, ?User $by = null, ?string $externalId = null): self
    {
        $path = $file->storeAs(self::DIRECTORY.'/'.$order->id, Str::lower(Str::random(32)).'.pdf', self::DISK);

        $old = null;

        $invoice = DB::transaction(function () use ($order, $file, $source, $by, $externalId, $path, &$old) {
            $old = static::where('order_id', $order->id)->lockForUpdate()->value('path');

            return static::updateOrCreate(['order_id' => $order->id], [
                'source' => $source,
                'external_id' => $externalId,
                'path' => $path,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'size' => (int) $file->getSize(),
                'uploaded_by' => $by?->id,
            ]);
        });

        if ($old && $old !== $path) {
            Storage::disk(self::DISK)->delete($old);
        }

        return $invoice;
    }

    public function deleteWithFile(): void
    {
        $this->delete();
        Storage::disk(self::DISK)->delete($this->path);
    }

    /** Имя файла при скачивании: «Счёт SM-2026-000001.pdf» */
    public function downloadName(): string
    {
        return 'Счёт '.$this->order->number.'.pdf';
    }
}
