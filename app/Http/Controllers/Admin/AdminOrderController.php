<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderStatusService;
use Illuminate\Validation\Rule;

class AdminOrderController extends Controller
{
    /**
     * Список заказов с поиском и фильтрами (SRS §10.7.4).
     */
    public function index()
    {
        $filters = request()->validate([
            'q'         => ['nullable', 'string', 'max:100'],
            'status'    => ['nullable', Rule::enum(OrderStatus::class)],
            'date_from' => ['nullable', 'date'],
            'date_to'   => ['nullable', 'date'],
        ]);

        $q = trim((string) ($filters['q'] ?? ''));

        $orders = Order::with(['user', 'organization'])
            // Поиск: номер заказа, клиент (организация: название, УНП), пользователь (имя, email)
            ->when($q !== '', function ($query) use ($q) {
                $like = '%' . $q . '%';

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
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('placed_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('placed_at', '<=', $date))
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view(
            'admin.orders',
            [
                'orders'   => $orders,
                'filters'  => $filters,
                'statuses' => OrderStatus::cases(),
            ]
        );
    }

    public function show($id)
    {
        $order = Order::with(['user', 'organization', 'items', 'statusHistory.user'])->findOrFail($id);

        return view(
            'admin.order',
            [
                'order'        => $order,
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
            'status'  => ['required', Rule::enum(OrderStatus::class)],
            'comment' => ['nullable', 'string', 'max:1000'],
        ], [
            'status.required' => 'Выберите новый статус',
            'status.enum'     => 'Неизвестный статус',
            'comment.max'     => 'Комментарий не длиннее 1000 символов',
        ]);

        $to = OrderStatus::from($data['status']);

        try {
            $statuses->change($order, $to, auth()->user(), $data['comment'] ?? null);
        } catch (InvalidStatusTransition $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Статус изменён: «{$to->label()}»");
    }

    private function manualStatus(): bool
    {
        return (bool) config('shop.orders.manual_status', true);
    }
}
