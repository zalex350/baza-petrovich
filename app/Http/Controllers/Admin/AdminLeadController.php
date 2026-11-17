<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;

/**
 * Заявки с сайта (Lead): «Узнать цену», «Заказать услугу», «Обратный звонок». Фильтр по типу и
 * статусу, поиск, отметка «Обработана».
 */
class AdminLeadController extends Controller
{
    public const STATUSES = ['new' => 'Новая', 'done' => 'Обработана'];

    public function index()
    {
        $filters = request()->validate([
            'type' => ['nullable', Rule::in(array_keys(Lead::TYPES))],
            'status' => ['nullable', Rule::in(array_keys(self::STATUSES))],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $q = trim((string) ($filters['q'] ?? ''));

        $leads = Lead::query()
            ->with('product')
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($q !== '', fn ($query) => $query->where(function ($query) use ($q) {
                $like = '%'.$q.'%';
                $query->where('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('subject', 'like', $like);
            }))
            ->latest()
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.leads', [
            'leads' => $leads,
            'filters' => $filters,
            'newCount' => Lead::where('status', 'new')->count(),
        ]);
    }

    public function updateStatus($id): RedirectResponse
    {
        $lead = Lead::findOrFail($id);

        $data = request()->validate(['status' => ['required', Rule::in(array_keys(self::STATUSES))]]);

        $lead->update(['status' => $data['status']]);

        return back()->with('success', "Заявка {$lead->name}: ".self::STATUSES[$lead->status]);
    }
}
