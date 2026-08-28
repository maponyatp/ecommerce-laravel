@if($order->payment_status === 'paid' && $order->items->contains(fn ($item) => $item->download_path || $item->product?->is_downloadable))
    <section class="mt-6 mb-6 rounded-2xl border border-gray-200 bg-white p-6" aria-label="Digital downloads">
        <h2 class="text-lg font-semibold text-gray-900">Your digital downloads</h2>
        <p class="mt-1 text-sm text-gray-500">These links are private. Keep your order email so you can return to this page.</p>
        @foreach($order->items as $item)
            @if($item->download_path || $item->product?->is_downloadable)
                <div class="mt-4 border-t border-gray-100 pt-4">
                    <p class="font-medium text-gray-900">{{ $item->product->name ?? 'Digital item' }}</p>
                    @if($downloadUrl = $item->downloadUrl())
                        <p class="mt-1 text-sm text-gray-500">Available until {{ $item->download_expires_at->format('d M Y, H:i') }}.
                            {{ $item->download_limit === null ? 'Unlimited downloads during this period.' : max(0, $item->download_limit - $item->download_count).' downloads remaining.' }}</p>
                        <a href="{{ $downloadUrl }}" rel="nofollow noreferrer" class="mt-3 inline-flex rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">Download file</a>
                    @else
                        <p class="mt-2 text-sm text-gray-600">Download access is unavailable or has expired. Please contact the store with order #{{ $order->id }}.</p>
                    @endif
                </div>
            @endif
        @endforeach
    </section>
@endif
