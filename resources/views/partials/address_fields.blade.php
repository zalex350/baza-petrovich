{{-- Поля адреса доставки — общие для админки и кабинета. Данные: $address (DeliveryAddress) --}}

<div>
    <label for="title" class="block mb-2 font-semibold">
        Название <span class="text-red-600">*</span>
    </label>
    <input
        id="title"
        type="text"
        name="title"
        value="{{ old('title', $address->title) }}"
        placeholder="Например: Склад на Промышленной"
        maxlength="255"
        class="w-full border border-slate-300 rounded px-3 py-2"
        required
    >
</div>

<div>
    <label for="address" class="block mb-2 font-semibold">
        Адрес <span class="text-red-600">*</span>
    </label>
    <textarea
        id="address"
        name="address"
        rows="2"
        maxlength="500"
        class="w-full border border-slate-300 rounded px-3 py-2"
        required
    >{{ old('address', $address->address) }}</textarea>
</div>

<div>
    <label for="contact_name" class="block mb-2 font-semibold">
        Контактное лицо
    </label>
    <input
        id="contact_name"
        type="text"
        name="contact_name"
        value="{{ old('contact_name', $address->contact_name) }}"
        maxlength="255"
        class="w-full border border-slate-300 rounded px-3 py-2"
    >
</div>

<div>
    <label for="contact_phone" class="block mb-2 font-semibold">
        Телефон контактного лица
    </label>
    <input
        id="contact_phone"
        type="text"
        name="contact_phone"
        value="{{ old('contact_phone', $address->contact_phone) }}"
        maxlength="50"
        class="w-full border border-slate-300 rounded px-3 py-2"
    >
</div>

@if($address->exists && $address->is_default)

    <p class="text-sm text-slate-500">
        Это адрес по умолчанию. Чтобы сменить его, выберите по умолчанию другой адрес.
    </p>

@else

    <label class="flex items-center gap-2">
        <input
            type="checkbox"
            name="is_default"
            value="1"
            @checked(old('is_default'))
        >
        Использовать по умолчанию
    </label>

@endif
