@php
    $design = $themeDesign ?? app(\App\Services\ThemeLibraryService::class)->design();
    $themeWidth = ($design['content_width'] ?? 'wide') === 'comfortable' ? '1120px' : '1280px';
    $themeRadius = match ($design['corner_style'] ?? 'square') { 'soft' => '12px', 'rounded' => '24px', default => '0px' };
    $primaryInk = app(\App\Support\StoreBranding::class)->current()['ink'];
@endphp
<style>
    :root { --theme-width: {{ $themeWidth }}; --theme-radius: {{ $themeRadius }}; --store-primary-ink: {{ $primaryInk }}; }
    main .max-w-7xl { max-width: var(--theme-width); }
    main article, .store-theme-hero img, .store-theme-hero .hero-inner > div:last-child { border-radius: var(--theme-radius); }
    main .store-primary-bg, .store-theme-hero a { border-radius: var(--theme-radius); }
    .store-theme-hero h1 { overflow-wrap: anywhere; }
    .store-primary-bg, footer.store-primary-bg a, footer.store-primary-bg h3 { color: var(--store-primary-ink) !important; }
    a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible { outline: 3px solid #2563eb; outline-offset: 3px; }
    @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: .01ms !important; transition-duration: .01ms !important; } }
    @if(($design['hero_layout'] ?? 'split') === 'image-left')
    @media (min-width: 1024px) { .store-theme-hero .hero-inner > div:last-child { order: -1; } }
    @elseif(($design['hero_layout'] ?? 'split') === 'centered')
    .store-theme-hero .hero-inner { grid-template-columns: minmax(0, 1fr); text-align: center; }
    .store-theme-hero h1, .store-theme-hero p { margin-left: auto; margin-right: auto; }
    .store-theme-hero .hero-inner > div:first-child > div { justify-content: center; }
    @endif
</style>
