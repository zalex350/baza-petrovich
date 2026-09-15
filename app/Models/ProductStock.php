<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductStock extends Model
{
    protected $fillable = [
        'article',
        'sku',
        'name',
        'onec_id',
        'stock',
    ];
}