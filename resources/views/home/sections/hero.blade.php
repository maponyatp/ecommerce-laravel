<section class="store-theme-hero relative border-b border-zinc-200 store-page-background py-16 sm:py-24">
    <div class="hero-inner mx-auto grid max-w-7xl items-center gap-12 px-4 sm:px-6 lg:grid-cols-2 lg:px-8">
        <div class="space-y-7">
            @if(filled($settings->hero_eyebrow))
                <p class="store-primary-bg inline-block px-3 py-1 text-[10px] font-bold uppercase tracking-widest text-white">{{ $settings->hero_eyebrow }}</p>
            @endif
            <h1 class="max-w-xl text-5xl font-black uppercase leading-none tracking-tighter text-zinc-950 sm:text-6xl">{{ $settings->hero_title }}</h1>
            @if(filled($settings->hero_description))
                <p class="max-w-xl text-base leading-relaxed text-zinc-700">{{ $settings->hero_description }}</p>
            @endif
            <div class="flex flex-col gap-3 sm:flex-row">
                <a href="{{ $settings->hero_primary_url }}" class="store-primary-bg px-8 py-4 text-center text-xs font-bold uppercase tracking-widest text-white transition-opacity hover:opacity-85">{{ $settings->hero_primary_label }}</a>
                @if(filled($settings->hero_secondary_label) && filled($settings->hero_secondary_url))
                    <a href="{{ $settings->hero_secondary_url }}" class="border border-zinc-950 px-8 py-4 text-center text-xs font-bold uppercase tracking-widest text-zinc-950 transition-colors hover:bg-zinc-950 hover:text-white">{{ $settings->hero_secondary_label }}</a>
                @endif
            </div>
        </div>
        <div class="border border-zinc-200 bg-white p-2">
            <img src="{{ $settings->hero_image_path ? asset('storage/' . $settings->hero_image_path) : asset('images/flowers/flowershop_hero.png') }}" alt="{{ $settings->site_name }}" class="h-96 w-full object-cover">
        </div>
    </div>
</section>
