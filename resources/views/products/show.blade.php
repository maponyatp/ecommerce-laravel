@extends('layouts.app')

@section('title', ($product->meta_title ?: $product->name).' | '.app(\App\Settings\GeneralSettings::class)->site_name)
@section('meta_description', $product->meta_description ?: strip_tags($product->short_description ?: $product->description ?? ''))
@section('meta_keywords', $product->meta_keywords ?? '')
@section('og_title', $product->meta_title ?: $product->name)
@section('og_description', $product->meta_description ?: strip_tags($product->short_description ?: $product->description ?? ''))
@section('og_image', $product->imageUrl)

@section('content')
<div class="container p-8">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h2 class="text-xl font-semibold tracking-tight text-gray-900 dark:text-white">
                        {{ $product->name }}
                    </h2>
                </div>
                <div class="card-body">
                    <img src="{{ $product->imageUrl }}" alt="{{ $product->name }}" class="mb-5 aspect-square w-full max-w-sm rounded-2xl object-cover">
                    <h4>Description:</h4>
                    <p>{{ $product->long_description }}</p>

                    <br>
                    @if($product->has_variants)
                        <x-variant-selector :product="$product" />
                    @elseif($product->isFree())
                        <p><strong>Price:</strong> Free</p>
                        <form action="{{ route('cart.add', $product) }}" method="POST">
                            @csrf
                            <button type="submit" class="btn btn-success mt-2">Add to cart — free checkout</button>
                        </form>
                    @elseif($product->isDonationBased())
                        <form action="{{ route('cart.add', $product) }}" method="POST" class="d-inline">
                            @csrf
                            <div class="form-group">
                                <label for="donation_amount">Support this product (Suggested: {{ \App\Support\StoreMoney::format($product->suggested_price) }})</label>
                                <input type="number" 
                                       name="price" 
                                       id="donation_amount" 
                                       class="form-control" 
                                       value="{{ $product->suggested_price }}"
                                       min="{{ $product->minimum_price }}"
                                       step="0.01">
                            </div>
                            <button type="submit" class="btn btn-success mt-2">Support & Download</button>
                        </form>
                        @if($product->minimum_price <= 0)
                            <p class="text-sm">Complete checkout to receive your protected download link.</p>
                        @endif
                    @else
                        <p><strong>Price:</strong> {{ \App\Support\StoreMoney::format($product->price) }}</p>
                        @if($product->inventory_count > 0)
                            <form action="{{ route('cart.add', $product) }}" method="POST" class="d-inline">
                                @csrf
                                <div class="flex items-center gap-2 mb-4">
                                    <label for="quantity" class="text-sm font-medium">Quantity:</label>
                                    <input type="number" 
                                           name="quantity" 
                                           id="quantity" 
                                           class="w-20 rounded border-gray-300" 
                                           value="1" 
                                           min="1" 
                                           max="{{ $product->inventory_count }}">
                                </div>
                                <button type="submit" class="btn btn-success mt-2">Add to Cart</button>
                            </form>
                        @endif
                    @endif
                    <p><strong>Category:</strong> {{ $product->category->name ?? "" }}</p>
                    @if($product->has_variants)
                        <p class="text-sm text-gray-600">Stock is shown separately for each option.</p>
                    @elseif($product->inventory_count > 0)
                        <p class="text-success"><strong>In Stock</strong></p>
                    @else
                        <p class="text-danger"><strong>Out of Stock</strong></p>
                    @endif
                    @auth
                    @if(Route::has('wishlist.add') && Route::has('wishlist.remove'))
                        @if(auth()->user()->wishlist()->where('product_id', $product->id)->exists())
                            <form action="{{ route('wishlist.remove', $product) }}" method="POST">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-danger">Remove from Wishlist</button>
                            </form>
                        @else
                            <form action="{{ route('wishlist.add', $product) }}" method="POST">
                                @csrf
                                <button type="submit" class="btn btn-primary">Add to Wishlist</button>
                            </form>
                        @endif
                    @endif
                    @endauth
                    <form action="{{ route('products.addToCompare', ['category' => $product->category, 'product' => $product]) }}" method="POST" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-primary mt-2">Add to Compare</button>
                    </form>
                    <a href="{{ route('products.compare') }}" class="text-blue-700 underline">View comparison</a>
                </div>
            </div>
            <a href="{{ route('products.index') }}" class="btn btn-primary mt-3">Back to Products</a>

            @if(isset($recommendations) && count($recommendations) > 0)
                <div class="card mt-4">
                    <div class="card-header">Recommended Products</div>
                    <div class="card-body">
                        <div class="row">
                            @foreach($recommendations as $recommendedProduct)
                                <div class="col-md-4 mb-3">
                                    <div class="card">
                                        <img src="/images/placeholder.png" alt="{{ $recommendedProduct->name }}" class="card-img-top">
                                        <div class="card-body">
                                            <h5 class="card-title">{{ $recommendedProduct->name }}</h5>
                                            <p class="card-text">{{ \App\Support\StoreMoney::format($recommendedProduct->price) }}</p>
                                            <a href="{{ route('products.show', $recommendedProduct) }}" class="btn btn-sm btn-primary">View Product</a>
                                            @if($recommendedProduct->inventory_count == 0)
                                                <p class="text-danger mt-2">Out of Stock</p>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>

@if($product->is_downloadable && auth()->check())
    <a href="{{ route('orders.index') }}" class="btn btn-success mt-3">View purchased downloads</a>
@endif



@unless($product->has_variants)
<script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org/',
    '@type' => 'Product',
    'name' => $product->name,
    'description' => $product->description,
    'image' => $product->imageUrl,
    'sku' => $product->id,
    'offers' => [
        '@type' => 'Offer',
        'url' => route('products.show', $product),
        'priceCurrency' => \App\Support\StoreMoney::currency(),
        'price' => $product->price,
        'availability' => $product->inventory_count > 0
            ? 'https://schema.org/InStock'
            : 'https://schema.org/OutOfStock',
        'seller' => [
            '@type' => 'Organization',
            'name' => app(\App\Settings\GeneralSettings::class)->site_name,
        ],
    ],
], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_PRETTY_PRINT) !!}
</script>
@endunless
    
@endsection

@section('meta')
    <meta name="description" content="{{ $product->meta_description ?? $product->short_description }}">
    <meta name="keywords" content="{{ $product->meta_keywords }}">
    <meta property="og:title" content="{{ $product->meta_title ?? $product->name }}">
    <meta property="og:description" content="{{ $product->meta_description ?? $product->short_description }}">
    <meta property="og:image" content="{{ asset('/images/placeholder.png') }}">
    <meta property="og:url" content="{{ route('products.show', $product) }}">
    <meta name="twitter:card" content="summary_large_image">
@endsection
