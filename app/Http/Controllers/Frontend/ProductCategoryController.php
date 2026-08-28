<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Http\Requests\CatalogueBrowseRequest;
use App\Models\Product;
use App\Models\ProductCategory;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class ProductCategoryController extends Controller
{
    public function index(CatalogueBrowseRequest $request)
    {
        $categories = QueryBuilder::for(ProductCategory::class)
            ->allowedFilters('name')
            ->allowedSorts('name', 'created_at')
            ->withCount('products')
            ->paginate();

        return view('categories.index', compact('categories'));
    }

    public function show(ProductCategory $category)
    {
        $productCount = Product::query()
            ->where('category_id', $category->id)
            ->count();

        $featuredProducts = Product::query()
            ->where('category_id', $category->id)
            ->latest()
            ->take(3)
            ->get();

        return view('categories.show', compact('category', 'productCount', 'featuredProducts'));
    }

    public function products(CatalogueBrowseRequest $request, ProductCategory $category)
    {
        $products = QueryBuilder::for(Product::class)
            ->allowedFilters(
                'name',
                AllowedFilter::callback('price', fn ($query, $value) => $query->priceMin($value)->priceMax($value)),
                'created_at',
                AllowedFilter::scope('price_min'),
                AllowedFilter::scope('price_max'),
            )
            ->where('category_id', $category->id)
            ->allowedSorts('name', AllowedSort::callback('price', fn ($query, bool $descending) => $query->orderByStorePrice($descending)), 'created_at')
            ->paginate(config('pagination.per_page'))
            ->appends($request->query());

        return view('categories.products', compact('category', 'products'));
    }
}
