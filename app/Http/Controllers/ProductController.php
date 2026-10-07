<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductImage;

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

        // Галерея: основное фото первым, остальные — в порядке из админки (BR-051, BR-052)
        $gallery = $product->images->sortByDesc('is_main')->values();

        return view('product', [
            'product'     => $product,
            'gallery'     => $gallery,
            'galleryData' => $gallery->map(function (ProductImage $image) {
                [$mw, $mh] = $image->dimensions('medium');
                [$lw, $lh] = $image->dimensions('large');

                return [
                    'thumb'  => $image->url('thumb'),
                    'medium' => $image->url('medium'),
                    'large'  => $image->url('large'),
                    'mw' => $mw, 'mh' => $mh, 'lw' => $lw, 'lh' => $lh,
                ];
            })->all(),
        ]);
    }
}
