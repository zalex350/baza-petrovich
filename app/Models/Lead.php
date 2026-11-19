<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Заявка с сайта: «Узнать цену» товара без цены, «Заказать услугу», «Обратный звонок».
 * subject — что запрошено (название товара / услуги на момент заявки), status — new | done.
 * Отправка: письмо администраторам (notify_partner_request — «Заявки с сайта»), Telegram, Битрикс24.
 */
class Lead extends Model
{
    public const TYPES = [
        'price' => 'Узнать цену',
        'service' => 'Заказать услугу',
        'callback' => 'Обратный звонок',
        'quiz' => 'Квиз',
    ];

    protected $fillable = ['type', 'product_id', 'quiz_id', 'payload', 'subject', 'name', 'phone', 'email', 'comment', 'status', 'crm_id', 'consent_at', 'ip', 'attribution'];

    protected $casts = [
        'consent_at' => 'datetime',
        'payload' => 'array',
        'attribution' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
