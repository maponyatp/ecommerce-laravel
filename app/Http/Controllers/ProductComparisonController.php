<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\Request;

class ProductComparisonController extends Controller
{
    private function products()
    {
        $ids = session('compare_list', []);
        $ids = is_array($ids) ? array_slice(array_values(array_unique(array_filter($ids,
            fn ($id) => (is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0))), 0, 4) : [];

        return Product::with('category')->whereIn('id', $ids)->get();
    }

    public function index()
    {
        return view('products.compare', ['products' => $this->products()]);
    }

    public function add(Request $request, ProductCategory $category, Product $product)
    {
        abort_unless((int) $product->category_id === $category->id, 404);
        $ids = $this->products()->pluck('id')->all();
        if (! in_array($product->id, $ids, true)) {
            if (count($ids) >= 4) {
                return redirect()->route('products.compare')->with('error', 'Compare up to four products. Remove one before adding another.');
            }
            $ids[] = $product->id;
        }
        $request->session()->put('compare_list', $ids);

        return redirect()->route('products.compare');
    }

    public function remove(Request $request, ProductCategory $category, Product $product)
    {
        abort_unless((int) $product->category_id === $category->id, 404);
        $request->session()->put('compare_list', $this->products()->pluck('id')->reject(fn ($id) => $id === $product->id)->values()->all());

        return redirect()->route('products.compare');
    }

    public function clear(Request $request)
    {
        $request->session()->forget('compare_list');

        return redirect()->route('products.compare');
    }
}
