<section id="featured-categories" class="border-b border-zinc-200 bg-white py-20">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="mb-16 text-center">
            <h2 class="text-3xl font-black uppercase tracking-tight text-zinc-950">{{ $settings->featured_categories_heading }}</h2>
            @if(filled($settings->featured_categories_subheading))<p class="mt-2 text-sm uppercase tracking-widest text-zinc-500">{{ $settings->featured_categories_subheading }}</p>@endif
        </div>
        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
            @forelse($featuredCategories as $category)
                <a href="{{ route('categories.show', $category) }}" class="group border border-zinc-200 bg-zinc-50 p-2 transition-all hover:border-zinc-950">
                    <div class="mb-4 h-64 overflow-hidden bg-zinc-100">
                        <img src="{{ $category->image ? asset('storage/' . $category->image) : asset('images/placeholder.png') }}" alt="{{ $category->name }}" class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105">
                    </div>
                    <div class="py-2 text-center"><h3 class="text-sm font-extrabold uppercase tracking-widest text-zinc-950">{{ $category->name }}</h3><span class="text-[10px] uppercase tracking-wider text-zinc-500">Shop now</span></div>
                </a>
            @empty
                <p class="col-span-full text-center text-zinc-500">Add categories in Admin to feature them here.</p>
            @endforelse
        </div>
    </div>
</section>
