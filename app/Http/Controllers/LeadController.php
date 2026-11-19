<?php

namespace App\Http\Controllers;

use App\Events\LeadCreated;
use App\Models\Lead;
use App\Models\Product;
use App\Support\Attribution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Заявки с сайта: «Узнать цену» (товар без цены), «Заказать услугу», «Обратный звонок».
 * Защита от спама — поле-ловушка и throttle на маршруте, как у «Стать партнёром».
 */
class LeadController extends Controller
{
    public const HONEYPOT = 'website';

    /** Форма «Узнать цену» товара */
    public function price(int $id)
    {
        $product = Product::query()->where('is_active', true)->where('is_published', true)->findOrFail($id);

        return view('lead', [
            'type' => 'price',
            'title' => 'Узнать цену',
            'subject' => $product->title(),
            'product' => $product,
            'back' => route('product.show', $product->id),
        ]);
    }

    /** Форма «Обратный звонок» / «Заказать услугу» (?subject= — название услуги) */
    public function create(string $type)
    {
        abort_unless(in_array($type, ['service', 'callback'], true), 404);

        $subject = trim((string) request('subject')) ?: null;

        return view('lead', [
            'type' => $type,
            'title' => Lead::TYPES[$type],
            'subject' => $subject ? mb_substr($subject, 0, 255) : null,
            'product' => null,
            'back' => url()->previous() !== url()->current() ? url()->previous() : '/',
        ]);
    }

    public function store(): RedirectResponse
    {
        if (filled(request(self::HONEYPOT))) {
            return back()->with('lead_sent', true);
        }

        $data = request()->validate([
            'type' => ['required', 'in:'.implode(',', array_keys(Lead::TYPES))],
            'product_id' => ['nullable', 'integer'],
            'subject' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50', 'regex:/^[\d\s()+\-]{7,}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'consent' => ['accepted'],
        ], [
            'name.required' => 'Укажите имя',
            'phone.required' => 'Укажите телефон',
            'phone.regex' => 'Телефон — цифры, пробелы, скобки, «+» и «-»',
            'email.email' => 'Неверный формат email',
            'comment.max' => 'Комментарий — не длиннее 2000 символов',
            'consent.accepted' => 'Нужно согласие на обработку персональных данных',
        ]);

        $product = ! empty($data['product_id']) ? Product::find($data['product_id']) : null;

        DB::transaction(function () use ($data, $product) {
            $lead = Lead::create([
                'type' => $data['type'],
                'product_id' => $product?->id,
                'subject' => $product?->title() ?? (trim((string) ($data['subject'] ?? '')) ?: null),
                'name' => trim($data['name']),
                'phone' => trim($data['phone']),
                'email' => ($email = mb_strtolower(trim((string) ($data['email'] ?? '')))) !== '' ? $email : null,
                'comment' => trim((string) ($data['comment'] ?? '')) ?: null,
                'consent_at' => now(),
                'ip' => request()->ip(),
                'attribution' => Attribution::current(),
            ]);

            LeadCreated::dispatch($lead);
        });

        return back()->with('lead_sent', true);
    }
}
