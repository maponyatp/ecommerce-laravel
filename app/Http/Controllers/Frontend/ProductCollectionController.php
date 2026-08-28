<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Http\Requests\CatalogueBrowseRequest;
use App\Models\ProductCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class ProductCollectionController extends Controller
{
    public function index(CatalogueBrowseRequest $request)
    {
        $collections = QueryBuilder::for(ProductCollection::class)
            ->allowedFilters('name')
            ->allowedSorts('name', 'created_at')
            ->paginate();

        return view('collections.index', compact('collections'));
    }

    public function show(ProductCollection $collection)
    {
        return view('collections.show', compact('collection'));
    }

    public function products(CatalogueBrowseRequest $request, ProductCollection $collection)
    {
        $products = QueryBuilder::for($collection->products())
            ->allowedFilters(
                'name',
                AllowedFilter::callback('price', fn ($query, $value) => $query->priceMin($value)->priceMax($value)),
                'created_at',
                AllowedFilter::scope('price_min'),
                AllowedFilter::scope('price_max'),
            )
            ->allowedSorts('name', AllowedSort::callback('price', fn ($query, bool $descending) => $query->orderByStorePrice($descending)), 'created_at')
            ->paginate(config('pagination.per_page'))
            ->appends($request->query());

        return view('collections.products', compact('collection', 'products'));
    }
}
