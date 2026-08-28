<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Product;
use App\Models\ProductCategory;

class SitemapController extends Controller
{
    public function index()
    {
        $products = Product::all();
        $categories = ProductCategory::all();
        $pages = Page::query()->published()->get();

        return response()->view('sitemap.index', [
            'products' => $products,
            'categories' => $categories,
            'pages' => $pages,
        ])->header('Content-Type', 'text/xml');
    }
}
