<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InvoiceSource;
use App\Enums\OrderExchangeState;
use App\Enums\OrderStatus;
use App\Exceptions\CancelRequestException;
use App\Exceptions\InvalidStatusTransition;
use App\Exceptions\OrderExchangeException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Services\OrderExchange;
use App\Services\OrderStatusService;
use App\Support\LocalTime;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AdminOrderController extends Controller
{
    /**
     * Список заказов с поиском и фильтрами (SRS §10.7.4).
     */
    public function index()
    {
        $filters = request()->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'cancel_request' => ['nullable', 'boolean'],
        ]);

        $q = trim((string) ($filters['q'] ?? ''));

        $orders = Order::with(['user', 'organization'])
            // Поиск: номер заказа, клиент (организация: название, УНП), пользователь (имя, email)
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.$q.'%';

                $query->where(function ($query) use ($like) {
                    $query->where('number', 'like', $like)
                        ->orWhereHas('organization', function ($query) use ($like) {
                            $query->where('name', 'like', $like)
                                ->orWhere('legal_name', 'like', $like)
                                ->orWhere('unp', 'like', $like);
                        })
                        ->orWhereHas('user', function ($query) use ($like) {
                            $query->where('name', 'like', $like)
                                ->orWhere('email', 'like', $like);
                        });
                });
            })
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            // Только заказы с открытым запросом на отмену
            ->when($filters['cancel_request'] ?? null, fn ($query) => $query->whereNotNull('cancel_requested_at'))
            // Дни по Минску (LocalTime::day): placed_at в БД — UTC
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->where('placed_at', '>=', LocalTime::day($date)[0]))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->where('placed_at', '<=', LocalTime::day($date)[1]))
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view(
            'admin.orders',
            [
                'orders' => $orders,
                'filters' => $filters,
                'statuses' => OrderStatus::cases(),
            ]
        );
    }

    public function show($id)
    {
        $order = Order::with(['user', 'organization', 'items.product.mainImage', 'statusHistory.user', 'cancelRequester', 'invoice.uploader'])->findOrFail($id);

        return view(
            'admin.order',
            [
                'order' => $order,
                'manualStatus' => $this->manualStatus(),
            ]
        );
    }

    /**
     * Признак оплаты. Статус меняется отдельно — changeStatus().
     */
    public function update($id)
    {
        $order = Order::findOrFail($id);

        $order->is_paid = request()->boolean('is_paid');

        $order->save();

        return back()->with(
            'success',
            'Заказ обновлён'
        );
    }

    /**
     * Смена статуса менеджером: только по допустимым переходам, с необязательным комментарием.
     */
    public function changeStatus($id, OrderStatusService $statuses)
    {
        if (! $this->manualStatus()) {
            return back()->with('error', 'Ручная смена статусов отключена');
        }

        $order = Order::findOrFail($id);

        $data = request()->validate([
            'status' => ['required', Rule::enum(OrderStatus::class)],
            'comment' => ['nullable', 'string', 'max:1000'],
        ], [
            'status.required' => 'Выберите новый статус',
            'status.enum' => 'Неизвестный статус',
            'comment.max' => 'Комментарий не длиннее 1000 символов',
        ]);

        $to = OrderStatus::from($data['status']);

        try {
            $statuses->change($order, $to, auth()->user(), $data['comment'] ?? null);
        } catch (InvalidStatusTransition $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Статус изменён: «{$to->label()}»");
    }

    /**
     * Подтверждение запроса клиента на отмену: заказ отменяется, остаток возвращается.
     */
    public function approveCancel($id, OrderStatusService $statuses)
    {
        $order = Order::findOrFail($id);

        $data = request()->validate([
            'comment' => ['nullable', 'string', 'max:1000'],
        ], [
            'comment.max' => 'Комментарий не длиннее 1000 символов',
        ]);

        try {
            $statuses->approveCancel($order, auth()->user(), $data['comment'] ?? null);
        } catch (CancelRequestException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Заказ {$order->number} отменён по запросу клиента");
    }

    /**
     * Отклонение запроса на отмену: комментарий обязателен, статус не меняется.
     */
    public function rejectCancel($id, OrderStatusService $statuses)
    {
        $order = Order::findOrFail($id);

        $data = request()->validate([
            'reject_comment' => ['required', 'string', 'max:1000'],
        ], [
            'reject_comment.required' => 'Укажите причину отклонения',
            'reject_comment.max' => 'Комментарий не длиннее 1000 символов',
        ]);

        try {
            $statuses->rejectCancel($order, auth()->user(), $data['reject_comment']);
        } catch (CancelRequestException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Запрос на отмену отклонён');
    }

    /**
     * Отклонённый 1С заказ: менеджер отменяет его (резерв снимается). Работает и при выключенной
     * ручной смене статусов — решение по отказу 1С остаётся за сайтом.
     */
    public function cancelRejected($id, OrderStatusService $statuses)
    {
        $order = Order::findOrFail($id);

        $data = request()->validate([
            'comment' => ['nullable', 'string', 'max:1000'],
        ], [
            'comment.max' => 'Комментарий не длиннее 1000 символов',
        ]);

        if ($order->exchange_state !== OrderExchangeState::Rejected) {
            return back()->with('error', '1С не отклоняла заказ');
        }

        try {
            $statuses->change($order, OrderStatus::Cancelled, auth()->user(), $data['comment'] ?? 'Отклонён 1С: '.$order->reject_reason);
        } catch (InvalidStatusTransition $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Заказ {$order->number} отменён");
    }

    /**
     * Отклонённый 1С заказ: менеджер разобрал причину и отправляет его в 1С повторно.
     */
    public function resendTo1c($id, OrderExchange $exchange)
    {
        $order = Order::findOrFail($id);

        try {
            $exchange->resend($order, auth()->user());
        } catch (OrderExchangeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Заказ {$order->number} будет отправлен в 1С повторно");
    }

    /**
     * Счёт на оплату (SRS §8.10, UC-035): PDF, заменяет прежний. Позже счёт будет приходить
     * из 1С — тогда OrderInvoice::attach(..., InvoiceSource::Erp, externalId: …).
     */
    public function uploadInvoice($id)
    {
        $order = Order::findOrFail($id);

        request()->validate([
            'invoice' => ['required', 'file', 'mimetypes:application/pdf', 'max:'.OrderInvoice::MAX_KB],
        ], [
            'invoice.required' => 'Выберите PDF-файл счёта',
            'invoice.uploaded' => 'Файл не загрузился — возможно, он больше '.intdiv(OrderInvoice::MAX_KB, 1024).' МБ',
            'invoice.mimetypes' => 'Счёт — только PDF',
            'invoice.max' => 'Файл больше '.intdiv(OrderInvoice::MAX_KB, 1024).' МБ',
        ]);

        $replaced = $order->invoice()->exists();

        OrderInvoice::attach($order, request()->file('invoice'), InvoiceSource::Manual, auth()->user());

        return back()->with('success', $replaced ? 'Счёт заменён' : 'Счёт загружен — клиент может его скачать');
    }

    public function deleteInvoice($id)
    {
        $order = Order::with('invoice')->findOrFail($id);

        $order->invoice?->deleteWithFile();

        return back()->with('success', 'Счёт удалён');
    }

    /**
     * Скачать счёт из админки (проверить загруженный файл).
     */
    public function downloadInvoice($id)
    {
        $order = Order::with('invoice')->findOrFail($id);

        abort_unless($order->invoice, 404);

        return Storage::disk(OrderInvoice::DISK)->download($order->invoice->path, $order->invoice->downloadName());
    }

    private function manualStatus(): bool
    {
        return (bool) config('shop.orders.manual_status', true);
    }
}
