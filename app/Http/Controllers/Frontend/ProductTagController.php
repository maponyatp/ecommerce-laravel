<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Http\Requests\CatalogueBrowseRequest;
use App\Models\Product;
use App\Models\Tag;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class ProductTagController extends Controller
{
    public function index(CatalogueBrowseRequest $request)
    {
        $tags = QueryBuilder::for(Tag::class)
            ->allowedFilters('name')
            ->allowedSorts('name', 'created_at')
            ->paginate();

        return view('tags.index', compact('tags'));
    }

    public function show(CatalogueBrowseRequest $request, Tag $tag)
    {
        $products = QueryBuilder::for(Product::class)
            ->allowedFilters(
                'name',
                AllowedFilter::callback('price', fn ($query, $value) => $query->priceMin($value)->priceMax($value)),
                'created_at',
                AllowedFilter::scope('price_min'),
                AllowedFilter::scope('price_max'),
            )
            ->withTag($tag)
            ->allowedSorts('name', AllowedSort::callback('price', fn ($query, bool $descending) => $query->orderByStorePrice($descending)), 'created_at')
            ->paginate(config('pagination.per_page'))
            ->appends($request->query());

        return view('tags.show', compact('tag', 'products'));
    }
}
