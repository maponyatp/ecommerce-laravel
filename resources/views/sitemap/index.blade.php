@php echo '<?xml version="1.0" encoding="UTF-8"?>'; @endphp
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url>
        <loc>{{ url('/') }}</loc>
        <changefreq>daily</changefreq>
        <priority>1.0</priority>
    </url>
    <url>
        <loc>{{ route('products.index') }}</loc>
        <changefreq>daily</changefreq>
        <priority>0.9</priority>
    </url>
    <url>
        <loc>{{ route('categories.index') }}</loc>
        <changefreq>weekly</changefreq>
        <priority>0.8</priority>
    </url>

    @foreach ($categories as $category)
        <url>
            <loc>{{ route('categories.show', $category) }}</loc>
            <lastmod>{{ optional($category->updated_at)->toAtomString() }}</lastmod>
            <changefreq>weekly</changefreq>
            <priority>0.7</priority>
        </url>
    @endforeach

    @foreach ($products as $product)
        <url>
            <loc>{{ route('products.show', $product) }}</loc>
            <lastmod>{{ optional($product->updated_at)->toAtomString() }}</lastmod>
            <changefreq>weekly</changefreq>
            <priority>0.7</priority>
        </url>
    @endforeach

    @foreach ($pages as $page)
        <url>
            <loc>{{ $page->publicUrl() }}</loc>
            <lastmod>{{ optional($page->published_at ?? $page->updated_at)->toAtomString() }}</lastmod>
            <changefreq>monthly</changefreq>
            <priority>0.6</priority>
        </url>
    @endforeach
</urlset>
