<section class="store-page-background py-20">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="mb-12 flex flex-col items-center justify-between gap-4 border-b border-zinc-200 pb-4 sm:flex-row">
            <h2 class="text-2xl font-black uppercase tracking-tight text-zinc-950">{{ $settings->products_heading }}</h2>
            <a href="{{ route('products.index') }}" class="text-xs font-bold uppercase tracking-widest text-zinc-950 hover:underline">{{ $settings->products_link_label }}</a>
        </div>
        <div class="grid grid-cols-2 gap-6 md:grid-cols-4">
            @foreach($latestProducts as $product)
                <article class="flex flex-col justify-between border border-zinc-200 bg-white p-2 transition-all hover:border-zinc-950">
                    <div><a href="{{ route('products.show', $product) }}"><img src="{{ $product->image_url ? asset($product->image_url) : asset('images/placeholder.png') }}" alt="{{ $product->name }}" class="mb-4 aspect-[3/4] w-full object-cover"></a><span class="text-[9px] font-bold uppercase tracking-widest text-zinc-400">{{ $product->category->name ?? 'Flower' }}</span><h3 class="mt-1 truncate text-sm font-bold uppercase text-zinc-950">{{ $product->name }}</h3><p class="mt-2 line-clamp-2 text-xs text-zinc-500">{{ $product->short_description ?? $product->description }}</p></div>
                    <div class="mt-6 flex flex-wrap items-center justify-between gap-2 border-t border-zinc-100 pt-4"><span class="text-sm font-black">{{ $product->store_price_label }}</span><x-catalogue-buy-button :product="$product" /></div>
                </article>
            @endforeach
        </div>
    </div>
</section>
