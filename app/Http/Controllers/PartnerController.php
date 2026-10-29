<?php

namespace App\Http\Controllers;

use App\Events\PartnerRequested;
use App\Models\PartnerRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * «Стать партнёром»: форма заявки для организаций. Защита от спама — скрытое поле-ловушка
 * (заполнено — заявка молча не сохраняется, ответ как при успехе) и ограничение частоты
 * отправки (throttle на маршруте). Заявка → письмо администраторам (notify_partner_request)
 * и список «Заявки» в админке.
 */
class PartnerController extends Controller
{
    /** Поле-ловушка: скрыто от людей, боты его заполняют */
    public const HONEYPOT = 'website';

    public function create()
    {
        return view('partner');
    }

    public function store(): RedirectResponse
    {
        if (filled(request(self::HONEYPOT))) {
            return redirect('/partner')->with('partner_sent', true);
        }

        request()->merge([
            'unp' => preg_replace('/\D/', '', (string) request('unp')),
            'email' => mb_strtolower(trim((string) request('email'))),
        ]);

        $data = request()->validate([
            'organization' => ['required', 'string', 'max:255'],
            'unp' => ['required', 'digits:9'],
            'contact_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50', 'regex:/^[\d\s()+\-]{7,}$/'],
            'email' => ['required', 'email', 'max:255'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'consent' => ['accepted'],
        ], [
            'organization.required' => 'Укажите организацию',
            'unp.required' => 'Укажите УНП',
            'unp.digits' => 'УНП — 9 цифр',
            'contact_name.required' => 'Укажите контактное лицо',
            'phone.required' => 'Укажите телефон',
            'phone.regex' => 'Телефон — цифры, пробелы, скобки, «+» и «-»',
            'email.required' => 'Укажите email',
            'email.email' => 'Неверный формат email',
            'comment.max' => 'Комментарий — не длиннее 2000 символов',
            'consent.accepted' => 'Нужно согласие на обработку персональных данных',
        ]);

        DB::transaction(function () use ($data) {
            $partnerRequest = PartnerRequest::create([
                'organization' => trim($data['organization']),
                'unp' => $data['unp'],
                'contact_name' => trim($data['contact_name']),
                'phone' => trim($data['phone']),
                'email' => $data['email'],
                'comment' => trim((string) ($data['comment'] ?? '')) ?: null,
                'consent_at' => now(),
                'ip' => request()->ip(),
            ]);

            PartnerRequested::dispatch($partnerRequest);
        });

        return redirect('/partner')->with('partner_sent', true);
    }
}
