<?php

namespace App\Http\Controllers;

use App\Exceptions\CancelRequestException;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Services\OrderStatusService;
use App\Services\ReorderService;
use App\Support\Plural;
use Illuminate\Support\Facades\Storage;

class OrderController extends Controller
{
    public function index()
    {
        // BR-113, BR-114: клиент видит заказы всей своей организации
        $orders = Order::visibleTo(auth()->user())
            ->with('user')
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->get();

        [$archivedOrders, $activeOrders] = $orders->partition(fn (Order $order) => $order->status->isFinal());

        return view('orders', compact('activeOrders', 'archivedOrders'));
    }

    public function show($id)
    {
        $order = Order::visibleTo(auth()->user())
            ->with(['items.product.mainImage', 'user', 'statusHistory.user', 'invoice'])
            ->where('id', $id)
            ->firstOrFail();

        return view('order_show', compact('order'));
    }

    /**
     * «Скачать счёт» (SRS §8.10, UC-035): заказ своей организации; счёта нет — назад
     * с «Счёт ещё не сформирован» (ERR-024). Чужой заказ — 404.
     */
    public function invoice($id)
    {
        $order = Order::visibleTo(auth()->user())->with('invoice')->where('id', $id)->firstOrFail();

        if (! $order->invoice || ! Storage::disk(OrderInvoice::DISK)->exists($order->invoice->path)) {
            return redirect("/order/{$order->id}")->with('error', OrderInvoice::NOT_READY);
        }

        return Storage::disk(OrderInvoice::DISK)->download($order->invoice->path, $order->invoice->downloadName());
    }

    /**
     * Страница после оформления: номер, доставка и итог с НДС.
     */
    public function placed($id)
    {
        $order = Order::visibleTo(auth()->user())->where('id', $id)->firstOrFail();

        return view('order_placed', compact('order'));
    }

    /**
     * Клиент заказ не удаляет (BR-133) и сам не отменяет: он запрашивает отмену своего
     * заказа с причиной, пока заказ «Новый» или «Подтверждён». Решает администратор.
     */
    public function requestCancel($id, OrderStatusService $statuses)
    {
        $order = Order::visibleTo(auth()->user())->where('id', $id)->firstOrFail();

        // Запросить отмену может только оформивший — не коллега по организации
        if ($order->user_id !== auth()->id()) {
            abort(403);
        }

        $data = request()->validate([
            'cancel_reason' => ['required', 'string', 'max:1000'],
        ], [
            'cancel_reason.required' => 'Укажите причину отмены',
            'cancel_reason.max' => 'Причина не длиннее 1000 символов',
        ]);

        try {
            $statuses->requestCancel($order, auth()->user(), $data['cancel_reason']);
        } catch (CancelRequestException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect("/order/{$order->id}")
            ->with('success', "Запрос на отмену заказа {$order->number} отправлен менеджеру");
    }

    /**
     * «Повторить» (SRS §8.11, UC-036): позиции заказа — в текущую корзину через CartValidator
     * (ReorderService), итог — в корзине: сколько добавлено и что недоступно или скорректировано.
     * Заказ коллеги по организации тоже можно повторить; исходный заказ не меняется (BR-118).
     */
    public function reorder($id, ReorderService $reorder)
    {
        $order = Order::visibleTo(auth()->user())->where('id', $id)->firstOrFail();

        if (! auth()->user()->isClient()) {
            abort(403);
        }

        ['added' => $added, 'issues' => $issues] = $reorder->reorder($order, auth()->user());

        $redirect = redirect('/order')
            ->with('reorder_number', $order->number)
            ->with('reorder_issues', $issues);

        return $added > 0
            ? $redirect->with('success', 'Добавлено в корзину: '.Plural::ru($added, ['позиция', 'позиции', 'позиций'])." из заказа {$order->number}")
            : $redirect->with('error', "Ни одну позицию заказа {$order->number} сейчас нельзя добавить в корзину");
    }
}
