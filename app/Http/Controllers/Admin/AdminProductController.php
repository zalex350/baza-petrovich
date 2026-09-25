<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;

class AdminProductController extends Controller
{
    public function index()
    {
        $products = Product::with('draft')->latest()->paginate(50);

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

    public function toggleVisibility($id)
    {
        $product = Product::findOrFail($id);

        $product->update(['is_active' => ! $product->is_active]);

        return back()->with(
            'success',
            $product->is_active ? 'Товар показан на сайте' : 'Товар скрыт с сайта'
        );
    }

    public function destroy($id)
    {
        Product::findOrFail($id)->delete();

        return redirect('/admin/products')->with('success', 'Товар удалён');
    }

    public function show($id)
    {
        $product = Product::findOrFail($id);
        $draft   = $product->draft;

        $categories = Category::all();

        return view(
            'admin.product',
            compact('product', 'categories', 'draft')
        );
    }

    public function update($id)
    {
        $product = Product::findOrFail($id);

        request()->validate([
            'price' => 'required|numeric|min:0',
            'stock' => 'required|integer|min:0',
        ]);

        $product->update([
            'name'        => request('name'),
            'sku'         => request('sku'),
            'brand'       => request('brand'),
            'slug'        => request('slug'),
            'price'       => request('price'),
            'stock'       => request('stock'),
            'category_id' => request('category_id'),
        ]);

        return back()->with('success', 'Товар сохранён');
    }

    public function saveDraft($id)
    {
        $product = Product::findOrFail($id);

        ProductDraft::updateOrCreate(
            ['product_id' => $product->id],
            ['data' => [
                'display_name'    => request('display_name'),
                'description'     => request('description'),
                'specifications'  => request('specifications'),
                'package_contents' => request('package_contents'),
            ]]
        );

        return back()->with('success', 'Черновик сохранён');
    }

    public function publish($id)
    {
        $product = Product::findOrFail($id);
        $draft   = $product->draft;

        if ($draft) {
            $data = $draft->data;

            $product->update([
                'display_name'    => $data['display_name'] ?? null,
                'description'     => $data['description'] ?? $product->description,
                'specifications'  => $data['specifications'] ?? $product->specifications,
                'package_contents' => $data['package_contents'] ?? $product->package_contents,
                'is_published'    => true,
            ]);

            $draft->delete();
        } else {
            $product->update(['is_published' => true]);
        }

        return back()->with('success', 'Товар опубликован');
    }

    public function deleteDraft($id)
    {
        $product = Product::findOrFail($id);
        $product->draft()->delete();

        return back()->with('success', 'Черновик удалён');
    }

    public function preview($id)
    {
        $product = Product::findOrFail($id);
        $draft   = $product->draft;

        if ($draft) {
            $data = $draft->data;

            $product->display_name    = $data['display_name'] ?? $product->display_name;
            $product->description     = $data['description'] ?? $product->description;
            $product->specifications  = $data['specifications'] ?? $product->specifications;
            $product->package_contents = $data['package_contents'] ?? $product->package_contents;
        }

        return view('product', compact('product'));
    }
}
