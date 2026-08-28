@extends('layouts.app')
@section('title', 'Compare flowers')
@section('content')
<main class="mx-auto max-w-7xl px-4 py-8">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div><h1 class="text-3xl font-semibold">Compare flowers</h1><p class="mt-2 text-gray-600">Compare up to four products before choosing your flowers.</p></div>
        <a href="{{ route('products.index') }}" class="text-blue-700 underline">Browse products</a>
    </div>
    @if(session('error'))<p role="alert" class="mb-4 rounded-lg bg-red-50 p-4 text-red-700">{{ session('error') }}</p>@endif
    @if($products->isNotEmpty())
        <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white">
            <table class="w-full text-left">
                <caption class="sr-only">Selected products, prices and availability</caption>
                <thead><tr><th scope="col" class="p-4">Product</th>@foreach($products as $product)<th scope="col" class="min-w-48 p-4">{{ $product->name }}</th>@endforeach</tr></thead>
                <tbody>
                    <tr class="border-t"><th scope="row" class="p-4">Image</th>@foreach($products as $product)<td class="p-4"><img src="{{ $product->imageUrl }}" alt="{{ $product->name }}" class="h-32 w-32 rounded-lg object-cover"></td>@endforeach</tr>
                    <tr class="border-t"><th scope="row" class="p-4">Price</th>@foreach($products as $product)<td class="p-4">{{ $product->store_price_label }}</td>@endforeach</tr>
                    <tr class="border-t"><th scope="row" class="p-4">Category</th>@foreach($products as $product)<td class="p-4">{{ $product->category?->name ?? 'Uncategorised' }}</td>@endforeach</tr>
                    <tr class="border-t"><th scope="row" class="p-4">Description</th>@foreach($products as $product)<td class="max-w-sm p-4">{{ strip_tags($product->short_description ?: $product->description ?? '') }}</td>@endforeach</tr>
                    <tr class="border-t"><th scope="row" class="p-4">Availability</th>@foreach($products as $product)<td class="p-4">{{ $product->has_variants ? 'Choose an option to check stock' : (app(\App\Services\StockReservationService::class)->available($product) > 0 ? 'In stock' : 'Out of stock') }}</td>@endforeach</tr>
                    <tr class="border-t"><th scope="row" class="p-4">Actions</th>@foreach($products as $product)<td class="p-4">
                        <a href="{{ route('products.show', $product) }}" class="text-blue-700 underline">View {{ $product->name }}</a>
                        <form action="{{ route('products.removeFromCompare', ['category' => $product->category, 'product' => $product]) }}" method="POST" class="mt-3">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-red-700" aria-label="Remove {{ $product->name }} from comparison">Remove</button>
                        </form>
                    </td>@endforeach</tr>
                </tbody>
            </table>
        </div>
        <form action="{{ route('products.clearCompare') }}" method="POST" class="mt-6">
            @csrf @method('DELETE')
            <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2">Clear comparison</button>
        </form>
    @else
        <div class="rounded-2xl border border-gray-200 bg-white p-8"><h2 class="text-xl font-medium">No products selected</h2><p class="mt-2 text-gray-600">Open a product and choose “Add to Compare”.</p></div>
    @endif
</main>
@endsection
