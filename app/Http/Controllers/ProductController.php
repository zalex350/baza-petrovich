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
        ->findOrFail($id);

        return view('product', compact('product'));
    }
}