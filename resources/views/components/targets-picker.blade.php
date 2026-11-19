{{--
    «Где показывать» для квизов и кодов сервисов: галочки по группам (общие, разделы каталога, услуги,
    страницы) из App\Services\PageTargets::options(). Раздел — вместе с подразделами и товарами.
    <x-targets-picker name="targets" :value="$quiz->targets" />
--}}
@props(['name' => 'targets', 'value' => []])

@php
    $selected = old($name, $value ?? []);
    $groups = \App\Services\PageTargets::options();
@endphp

<x-field :id="'f-'.$name" label="Где показывать" :error="$name" hint="Раздел каталога — вместе с подразделами и карточками товаров.">
    <div class="grid gap-4 md:grid-cols-2" data-targets-picker>
        @foreach($groups as $group => $options)
            @continue(empty($options))
            <fieldset class="rounded-card border border-line p-3">
                <legend class="px-1 text-sm font-semibold">{{ $group }}</legend>
                <div class="max-h-64 space-y-1 overflow-y-auto">
                    @foreach($options as $key => $label)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="{{ $name }}[]" value="{{ $key }}" @checked(in_array($key, $selected, true)) class="h-4 w-4 rounded border-line-strong text-primary focus:ring-primary">
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
        @endforeach
    </div>
</x-field>
