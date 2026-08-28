<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $settings->site_name }} | Temporarily unavailable</title>
    @if($settings->favicon_path)
        <link rel="icon" href="{{ asset('storage/' . $settings->favicon_path) }}">
    @endif
    <style>
        :root { --store-primary: {{ $settings->store_primary_color }}; --store-background: {{ $settings->store_background_color }}; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; display: grid; place-items: center; padding: 1.5rem; color: #202124; background: radial-gradient(circle at 10% 10%, color-mix(in srgb, var(--store-primary) 14%, transparent), transparent 32rem), var(--store-background); font-family: Inter, ui-sans-serif, system-ui, sans-serif; }
        main { width: min(100%, 36rem); padding: clamp(2rem, 7vw, 4.5rem); text-align: center; background: rgba(255,255,255,.95); border: 1px solid rgba(32,33,36,.10); border-radius: 1.5rem; box-shadow: 0 18px 48px rgba(60,64,67,.14); }
        img { max-width: 13rem; max-height: 3.5rem; object-fit: contain; margin: 0 auto 2rem; }
        .brand { margin-bottom: 2rem; color: var(--store-primary); font-size: 1.2rem; font-weight: 800; letter-spacing: -.04em; }
        h1 { margin: 0; font-size: clamp(2rem, 7vw, 3.5rem); line-height: 1; letter-spacing: -.055em; }
        p { margin: 1.4rem auto 0; max-width: 29rem; color: #5f6368; font-size: 1.05rem; line-height: 1.65; }
    </style>
</head>
<body>
    <main>
        @if($settings->site_logo_path)
            <img src="{{ asset('storage/' . $settings->site_logo_path) }}" alt="{{ $settings->site_name }}">
        @else
            <div class="brand">{{ $settings->site_name }}</div>
        @endif
        <h1>We’ll be back soon.</h1>
        <p>{{ $settings->storefront_unavailable_message }}</p>
    </main>
</body>
</html>
