<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PriceType;
use App\Services\Pricing\PriceTypes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Справочник «Типы цен» (PriceTypes, PriceContext): название, скрытый, порядок; тип по умолчанию;
 * новый тип вручную (обычно их создаёт обмен с 1С по колонке price_<код> — скрытыми).
 */
class AdminPriceTypeController extends Controller
{
    public function __construct(private readonly PriceTypes $types) {}

    public function index()
    {
        return view('admin.price_types', [
            'types' => PriceType::query()->ordered()->withCount(['prices', 'organizations'])->get(),
        ]);
    }

    public function store(): RedirectResponse
    {
        request()->merge(['code' => mb_strtolower(trim((string) request('code')))]);
        $data = request()->validate([
            'code' => ['required', 'string', 'max:30', 'regex:'.PriceType::CODE_PATTERN, Rule::unique('price_types', 'code')],
            'name' => ['required', 'string', 'max:100'],
        ], [
            'code.regex' => 'Код — латиница, цифры, «_» или «-», как в колонке price_<код> файла 1С',
            'code.unique' => 'Тип с таким кодом уже есть',
        ]);

        PriceType::create($data + [
            'is_hidden' => request()->boolean('is_hidden'),
            'sort_order' => (int) PriceType::query()->max('sort_order') + 1,
        ]);

        return back()->with('success', "Тип цены «{$data['name']}» добавлен");
    }

    public function update(int $id): RedirectResponse
    {
        $type = PriceType::findOrFail($id);
        $data = request()->validate([
            'name' => ['required', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);
        $hidden = request()->boolean('is_hidden');

        if ($hidden && $type->is_default) {
            return back()->withErrors(['is_hidden' => 'Тип по умолчанию нельзя скрыть — сначала назначьте другой']);
        }

        $type->update(['name' => $data['name'], 'sort_order' => (int) ($data['sort_order'] ?? $type->sort_order), 'is_hidden' => $hidden]);

        return back()->with('success', "Тип цены «{$type->name}» сохранён");
    }

    public function makeDefault(int $id): RedirectResponse
    {
        $type = PriceType::findOrFail($id);

        try {
            $this->types->makeDefault($type);
        } catch (RuntimeException $e) {
            return back()->withErrors(['default' => $e->getMessage()]);
        }

        return back()->with('success', "Цена по умолчанию — «{$type->name}»");
    }
}
