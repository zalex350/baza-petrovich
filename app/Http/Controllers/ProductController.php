<?php

namespace App\Http\Controllers;

use App\Models\Product;

class ProductController extends Controller
{
    public function show($id)
    {
        $product = Product::with([
            'images',
            'category',
        ])
        ->where('is_active', 1)
        ->where('is_published', true)
        ->findOrFail($id);

        // BR-082: сбой при загрузке характеристик не роняет карточку — блок не показывается
        rescue(
            fn () => $product->load('attributeValues.attribute'),
            fn () => $product->setRelation('attributeValues', collect())
        );

        return view('product', compact('product'));
    }
}
