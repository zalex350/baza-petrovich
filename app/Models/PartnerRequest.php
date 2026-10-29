<?php

namespace App\Models;

use App\Enums\PartnerRequestStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Заявка «Стать партнёром» с сайта (PartnerController); статус ведёт администратор.
 */
class PartnerRequest extends Model
{
    protected $fillable = ['organization', 'unp', 'contact_name', 'phone', 'email', 'comment', 'consent_at', 'status', 'ip'];

    protected $casts = [
        'consent_at' => 'datetime',
        'status' => PartnerRequestStatus::class,
    ];

    protected $attributes = [
        'status' => 'new',
    ];
}
