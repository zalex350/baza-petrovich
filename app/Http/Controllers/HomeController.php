<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;

class HomeController extends Controller
{
    public function index()
    {
        $categories = Category::whereNull('parent_id')
            ->with('children')
            ->get();

        $featuredProducts = Product::with('mainImage')
            ->where('is_active', 1)
            ->where('is_published', true)
            ->latest()
            ->take(4)
            ->get();

        return view(
            'home',
            compact(
                'categories',
                'featuredProducts'
            )
        );
    }
}