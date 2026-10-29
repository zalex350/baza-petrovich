<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PartnerRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\PartnerRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;

/**
 * Заявки «Стать партнёром»: список с фильтром по статусу и поиском, смена статуса
 * (Новая / В работе / Подключён / Отказ).
 */
class AdminPartnerRequestController extends Controller
{
    public function index()
    {
        $filters = request()->validate([
            'status' => ['nullable', Rule::enum(PartnerRequestStatus::class)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $q = trim((string) ($filters['q'] ?? ''));

        $requests = PartnerRequest::query()
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($q !== '', fn ($query) => $query->where(function ($query) use ($q) {
                $like = '%'.$q.'%';
                $query->where('organization', 'like', $like)
                    ->orWhere('unp', 'like', $like)
                    ->orWhere('contact_name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            }))
            ->latest()
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.partners', [
            'requests' => $requests,
            'filters' => $filters,
            'statuses' => PartnerRequestStatus::cases(),
            'newCount' => PartnerRequest::where('status', PartnerRequestStatus::New)->count(),
        ]);
    }

    public function updateStatus($id): RedirectResponse
    {
        $partnerRequest = PartnerRequest::findOrFail($id);

        $data = request()->validate(['status' => ['required', Rule::enum(PartnerRequestStatus::class)]]);

        $partnerRequest->update(['status' => $data['status']]);

        return back()->with('success', "Заявка «{$partnerRequest->organization}»: {$partnerRequest->status->label()}");
    }
}
