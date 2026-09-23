<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;

class AdminProductController extends Controller
{
    public function index()
    {
        $products = Product::latest()->get();

        return view(
            'admin.products',
            compact('products')
        );
    }

    public function create()
    {
        $categories = Category::all();

        return view('admin.product_create', compact('categories'));
    }

    public function store()
    {
        $data = request()->validate([
            'name'             => 'required|string|max:255',
            'sku'              => 'required|string|max:100|unique:products,sku',
            'brand'            => 'nullable|string|max:255',
            'slug'             => 'nullable|string|max:255|unique:products,slug',
            'price'            => 'required|numeric|min:0',
            'stock'            => 'required|integer|min:0',
            'category_id'      => 'nullable|exists:categories,id',
            'description'      => 'nullable|string',
            'specifications'   => 'nullable|string',
            'package_contents' => 'nullable|string',
            'is_active'        => 'boolean',
        ]);

        $data['is_active']    = request()->has('is_active');
        $data['is_published'] = false;

        $product = Product::create($data);

        return redirect("/admin/product/{$product->id}")->with('success', 'Товар создан');
    }

    public function show($id)
    {
        $product = Product::findOrFail($id);

        $categories = Category::all();

        return view(
            'admin.product',
            compact('product', 'categories')
        );
    }
    public function update($id)
    {
        $product = Product::findOrFail($id);

        $product->update([
            'name' => request('name'),
            'sku' => request('sku'),
            'brand' => request('brand'),
            'slug' => request('slug'),
            'price' => request('price'),
            'stock' => request('stock'),
            'description' => request('description'),
            'specifications' => request('specifications'),
            'package_contents' => request('package_contents'),
            'category_id' => request('category_id'),
            'is_active' => request()->has('is_active'),
        ]);

        return back()->with(
            'success',
            'Product updated'
        );
    }
}
