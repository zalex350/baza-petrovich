<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Rules\Bic;
use App\Rules\Iban;
use App\Rules\Unp;
use Illuminate\Http\RedirectResponse;

/**
 * Настройки сайта: контакты (телефоны с подписями, email, режим работы, адрес склада),
 * реквизиты продавца и короткие тексты подвала (Setting::FIELDS, Setting::PHONES).
 * Пустые реквизиты и тексты — на сайте [ЗАПОЛНИТЬ]; пустые контакты не показываются.
 */
class AdminSettingsController extends Controller
{
    /** Пустых строк телефонов в форме под заполненными — для новых номеров */
    private const BLANK_PHONE_ROWS = 2;

    public function edit()
    {
        $phones = old('phones', array_map(
            fn ($phone) => ['label' => $phone['label'], 'number' => $phone['number']],
            Setting::phones()
        ));
        $phones = array_slice(
            array_merge(array_values($phones), array_fill(0, self::BLANK_PHONE_ROWS, ['label' => '', 'number' => ''])),
            0,
            max(Setting::MAX_PHONES, count($phones))
        );

        return view('admin.settings', ['values' => Setting::values(), 'phones' => $phones]);
    }

    public function update(): RedirectResponse
    {
        $text = collect(Setting::FIELDS)->map(fn () => ['nullable', 'string', 'max:1000'])->all();

        // УНП — без пробелов; проверка — только 9 цифр, контрольная цифра — подсказка в форме
        if (filled(request('seller_unp'))) {
            request()->merge(['seller_unp' => Unp::normalize(request('seller_unp'))]);
        }

        $data = request()->validate([
            ...$text,
            'contact_email' => ['nullable', 'email', 'max:255'],
            'seller_unp' => ['nullable', 'string', new Unp],
            'seller_iban' => ['nullable', 'string', new Iban],
            'seller_bic' => ['nullable', 'string', new Bic],
            'phones' => ['nullable', 'array', 'max:'.Setting::MAX_PHONES],
            'phones.*.label' => ['nullable', 'string', 'max:100'],
            'phones.*.number' => ['nullable', 'required_with:phones.*.label', 'string', 'max:30', 'regex:/^\+?[\d\s()\-]{5,30}$/'],
        ], [
            ...collect(Setting::FIELDS)->mapWithKeys(fn ($label, $key) => ["{$key}.max" => "«{$label}» — не длиннее :max символов"])->all(),
            'contact_email.email' => 'Email — в формате name@example.by.',
            'phones.max' => 'Телефонов — не больше :max.',
            'phones.*.number.required_with' => 'Укажите номер — подпись без номера не сохраняется.',
            'phones.*.number.regex' => 'Номер — цифры, пробелы, скобки и дефисы, например +375 (17) 123-45-67.',
            'phones.*.number.max' => 'Номер — не длиннее :max символов.',
            'phones.*.label.max' => 'Подпись — не длиннее :max символов.',
        ]);

        $values = collect(Setting::FIELDS)->map(fn ($label, $key) => trim((string) ($data[$key] ?? '')) ?: null)->all();

        // Счёт и BIC — без пробелов, заглавными
        foreach (['seller_iban' => Iban::class, 'seller_bic' => Bic::class] as $key => $rule) {
            if ($values[$key] !== null) {
                $values[$key] = $rule::normalize($values[$key]);
            }
        }

        $phones = collect($data['phones'] ?? [])
            ->map(fn ($row) => ['label' => trim((string) ($row['label'] ?? '')), 'number' => trim((string) ($row['number'] ?? ''))])
            ->filter(fn ($row) => $row['number'] !== '')
            ->values()->all();

        $values[Setting::PHONES] = $phones ? json_encode($phones, JSON_UNESCAPED_UNICODE) : null;

        Setting::put($values);

        return back()->with('success', 'Настройки сохранены');
    }
}
