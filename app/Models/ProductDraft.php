<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductDraft extends Model
{
    protected $fillable = ['product_id', 'data'];

    protected $casts = ['data' => 'array'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
