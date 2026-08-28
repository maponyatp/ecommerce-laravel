@props(['product'])
@php($options = $product->purchasableVariants()->get())
<section class="my-6 rounded-2xl border border-zinc-200 bg-white p-5" aria-labelledby="product-options-heading">
    <h3 id="product-options-heading" class="text-lg font-semibold text-zinc-950">Choose your option</h3>
    <p class="mt-1 text-sm text-zinc-600">Each option has its own price and availability. Delivery is calculated at checkout.</p>
    @if($options->isEmpty())
        <p class="mt-4 text-sm font-medium">No options are currently available. Please check back soon.</p>
    @else
        <form action="{{ route('cart.add', $product) }}" method="POST" class="mt-5 space-y-4">
            @csrf
            <div><label for="variant_id" class="mb-2 block text-sm font-medium">Product option</label>
                <select id="variant_id" name="variant_id" required class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-3 text-base text-zinc-950">
                    <option value="">Select an option</option>
                    @foreach($options as $option)
                        @php($available = app(\App\Services\StockReservationService::class)->available($product, variant: $option))
                        <option value="{{ $option->id }}" @disabled($available < 1) @selected((string) old('variant_id') === (string) $option->id)>{{ $option->title }} · {{ collect($option->options)->map(fn ($value, $name) => $name.': '.$value)->implode(', ') }} · {{ \App\Support\StoreMoney::format($option->price) }} · {{ $available > 0 ? $available.' available' : 'Sold out' }}</option>
                    @endforeach
                </select>
            </div>
            <div><label for="variant_quantity" class="mb-2 block text-sm font-medium">Quantity</label><input id="variant_quantity" name="quantity" type="number" value="1" min="1" max="9999" step="1" required class="w-24 rounded-lg border border-zinc-300 bg-white px-3 py-3 text-base text-zinc-950"></div>
            <button type="submit" class="store-primary-bg min-h-11 rounded-lg px-6 py-3 text-sm font-semibold text-white">Add selected option to basket</button>
        </form>
    @endif
</section>
