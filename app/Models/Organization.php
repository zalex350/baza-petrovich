<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Организация (юрлицо) клиента. guid заполняется только при обмене с 1С,
 * поэтому не входит в $fillable и в админке показывается read-only.
 */
class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'legal_name',
        'unp',
        'address',
        'phone',
        'email',
        'comment',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Коды наших товаров у этой организации (ProductClientCode).
     */
    public function productCodes()
    {
        return $this->hasMany(ProductClientCode::class);
    }

    /**
     * Адреса доставки: адрес по умолчанию первым, остальные по названию.
     */
    public function deliveryAddresses()
    {
        return $this->hasMany(DeliveryAddress::class)
            ->orderByDesc('is_default')
            ->orderBy('title')
            ->orderBy('id');
    }
}
