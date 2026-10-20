<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Services\ProductAttributes;
use Illuminate\Validation\Rule;

/**
 * Справочник характеристик (SRS §4.9, §4.18): список, создание, переименование, тип,
 * единица, «используется в фильтрах», объединение дублей, удаление неиспользуемых.
 */
class AdminAttributeController extends Controller
{
    public function __construct(private readonly ProductAttributes $attributes)
    {
    }

    public function index()
    {
        $search = trim((string) request('q', ''));

        $list = ProductAttribute::withCount('values')
            ->when($search !== '', fn ($q) => $q->where('name_key', 'like', '%' . ProductAttribute::key($search) . '%'))
            ->orderBy('name')
            ->paginate(100)
            ->withQueryString();

        return view('admin.attributes', ['attributes' => $list, 'search' => $search]);
    }

    public function create()
    {
        return view('admin.attribute_form', ['attribute' => new ProductAttribute(['type' => ProductAttribute::TEXT])]);
    }

    public function store()
    {
        $attribute = ProductAttribute::create($this->validated());

        return redirect('/admin/attributes')->with('success', "Характеристика «{$attribute->name}» добавлена");
    }

    public function edit($id)
    {
        $attribute = ProductAttribute::withCount('values')->findOrFail($id);

        return view('admin.attribute_form', compact('attribute'));
    }

    public function update($id)
    {
        $attribute = ProductAttribute::findOrFail($id);
        $data = $this->validated($attribute);

        // В число — только если все значения числа; value_number пересчитывается
        if ($data['type'] === ProductAttribute::NUMBER) {
            $bad = $attribute->values()->pluck('value')
                ->filter(fn ($v) => ProductAttribute::parseNumber($v) === null);

            if ($bad->isNotEmpty()) {
                return back()->withInput()->withErrors([
                    'type' => 'Нельзя сделать числовой: не числа у ' . $bad->count() . ' товаров (например, «' . $bad->first() . '»)',
                ]);
            }
        }

        $attribute->update($data);

        $attribute->values()->each(fn (ProductAttributeValue $v) => $v->update([
            'value_number' => ProductAttribute::parseNumber($v->value),
        ]));

        return redirect('/admin/attributes')->with('success', "Характеристика «{$attribute->name}» сохранена");
    }

    /**
     * Объединение дублей: значения выбранных переходят к основной, выбранные удаляются.
     */
    public function merge()
    {
        $data = request()->validate([
            'target_id'   => ['required', 'integer', 'exists:attributes,id'],
            'source_ids'  => ['required', 'array', 'min:1'],
            'source_ids.*' => ['integer', 'exists:attributes,id'],
        ], [
            'target_id.required'  => 'Выберите характеристику, в которую объединять',
            'source_ids.required' => 'Отметьте характеристики для объединения',
        ]);

        $target = ProductAttribute::findOrFail($data['target_id']);
        $sources = ProductAttribute::whereIn('id', $data['source_ids'])->where('id', '<>', $target->id)->get();

        if ($sources->isEmpty()) {
            return back()->withErrors(['source_ids' => 'Отметьте характеристики, кроме основной']);
        }

        if ($target->isNumber()) {
            $bad = ProductAttributeValue::whereIn('attribute_id', $sources->pluck('id'))->pluck('value')
                ->filter(fn ($v) => ProductAttribute::parseNumber($v) === null);

            if ($bad->isNotEmpty()) {
                return back()->withErrors(['target_id' => "«{$target->name}» — числовая, а у объединяемых есть не числа (например, «{$bad->first()}»)"]);
            }
        }

        $result = $this->attributes->merge($target, $sources);

        return redirect('/admin/attributes')->with('success',
            'Объединено в «' . $target->name . '»: ' . $sources->pluck('name')->map(fn ($n) => "«{$n}»")->implode(', ')
            . ". Перенесено значений: {$result['moved']}"
            . ($result['dropped'] ? ", отброшено (у товара уже было значение «{$target->name}»): {$result['dropped']}" : '')
        );
    }

    public function destroy($id)
    {
        $attribute = ProductAttribute::withCount('values')->findOrFail($id);

        if ($attribute->values_count > 0) {
            return back()->with('error', "«{$attribute->name}» используется у {$attribute->values_count} товаров — сначала объедините её с другой или уберите из товаров");
        }

        $attribute->delete();

        return redirect('/admin/attributes')->with('success', "Характеристика «{$attribute->name}» удалена");
    }

    private function validated(?ProductAttribute $attribute = null): array
    {
        request()->merge(['name_key' => ProductAttribute::key(request('name'))]);

        $data = request()->validate([
            'name'          => ['required', 'string', 'max:255'],
            'name_key'      => [Rule::unique('attributes', 'name_key')->ignore($attribute?->id)],
            'unit'          => ['nullable', 'string', 'max:50'],
            'type'          => ['required', Rule::in(array_keys(ProductAttribute::TYPES))],
            'is_filterable' => ['boolean'],
        ], [
            'name_key.unique' => 'Такая характеристика уже есть (без учёта регистра). Чтобы свести дубли, используйте объединение',
        ], [
            'name' => 'Название',
            'unit' => 'Единица',
            'type' => 'Тип',
        ]);

        unset($data['name_key']);
        $data['is_filterable'] = request()->boolean('is_filterable');

        return $data;
    }
}
