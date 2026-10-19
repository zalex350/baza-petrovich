{{--
    Выбор ставки НДС товара. «Без НДС» (none) хранится как null и отличается от 0%.
    Данные: $current — ставка из БД или old(): число, null или 'none';
    необязательно: $form — id формы, $disabled — только просмотр, $id — id поля.
--}}
@php
    $currentRate = \App\Services\Vat\VatCalculator::normalizeRate($current);
    $rates = collect(\App\Services\Vat\VatCalculator::RATES)->map(fn ($rate) => (float) $rate);

    // Нестандартная ставка, сохранённая раньше, остаётся в списке
    if ($currentRate !== null && ! $rates->contains($currentRate)) {
        $rates->push($currentRate);
    }
@endphp

<select
    name="vat_rate"
    @isset($id) id="{{ $id }}" @endisset
    @isset($form) form="{{ $form }}" @endisset
    class="w-full border rounded px-3 py-2 disabled:bg-slate-50 disabled:text-slate-500"
    @if($disabled ?? false) disabled @else required @endif
>

    @foreach($rates as $rate)

        <option value="{{ $rate }}" @selected($currentRate === $rate)>
            {{ \App\Services\Vat\VatCalculator::rateLabel($rate) }}
        </option>

    @endforeach

    <option value="{{ \App\Services\Vat\VatCalculator::NO_VAT }}" @selected($currentRate === null)>
        Без НДС
    </option>

</select>
