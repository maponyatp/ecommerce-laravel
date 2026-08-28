@props(['product'])
@if($product->has_variants)
    <a href="{{ route('products.show', $product) }}" class="store-primary-bg mt-3 inline-flex min-h-11 items-center justify-center rounded-lg px-4 py-3 text-sm font-semibold text-white">Choose options</a>
@else
    <form action="{{ route('cart.add', $product) }}" method="POST" class="mt-3">@csrf
        <button type="submit" class="store-primary-bg min-h-11 rounded-lg px-4 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-40" @disabled($product->inventory_count <= 0)>{{ $product->inventory_count <= 0 ? 'Out of stock' : 'Add to basket' }}</button>
    </form>
@endif
