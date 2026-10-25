<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Код товара у организации-клиента (её собственный артикул нашего товара).
 * Уникально: (organization_id, product_id) — один код на товар; (organization_id, code) —
 * код не повторяется у организации. Интерфейса пока нет — docs/BACKLOG.md.
 */
class ProductClientCode extends Model
{
    protected $fillable = [
        'product_id',
        'organization_id',
        'code',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
