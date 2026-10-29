<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;

/**
 * Настройки сайта: реквизиты продавца и короткие тексты подвала (Setting::FIELDS).
 * Пустое поле — на сайте [ЗАПОЛНИТЬ].
 */
class AdminSettingsController extends Controller
{
    public function edit()
    {
        return view('admin.settings', ['values' => Setting::values()]);
    }

    public function update(): RedirectResponse
    {
        $data = request()->validate(
            collect(Setting::FIELDS)->map(fn () => ['nullable', 'string', 'max:1000'])->all(),
            collect(Setting::FIELDS)->mapWithKeys(fn ($label, $key) => ["{$key}.max" => "«{$label}» — не длиннее 1000 символов"])->all(),
        );

        Setting::put(collect(Setting::FIELDS)->map(fn ($label, $key) => trim((string) ($data[$key] ?? '')) ?: null)->all());

        return back()->with('success', 'Настройки сохранены');
    }
}
