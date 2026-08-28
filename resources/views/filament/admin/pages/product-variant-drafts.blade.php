<x-filament-panels::page>
    <x-filament::section heading="Product options, from draft to sale" description="Prepare sizes, colours and prices, then explicitly publish each physical-product option. Editing a draft alone never changes the live store.">
        <p class="text-sm text-gray-600">Open a draft and choose “Publish / manage live option” to set on-hand stock, packed weight and sale availability. The first publication makes this a variable product: its parent price and stock are no longer sold directly. Existing orders retain their purchased option and price.</p>
        @if($product)
            <h3 class="mt-4 text-lg font-semibold">{{ $product->name }}</h3>
            <div class="mt-3 flex flex-wrap gap-4">
                <x-filament::link href="{{ \App\Filament\Admin\Pages\ProductVariantDrafts::getUrl() }}">Choose another product</x-filament::link>
                @if($draft)<x-filament::link href="{{ \App\Filament\Admin\Pages\ProductVariantDrafts::getUrl(['product' => $product->id]) }}">All drafts for this product</x-filament::link>@endif
            </div>
        @endif
    </x-filament::section>

    @if($draft)
        <x-filament::section heading="Live storefront option">
            @if($live)
                <div class="flex flex-wrap items-center gap-3"><x-filament::badge color="{{ $live->active ? 'success' : 'gray' }}">{{ $live->active ? 'Published for sale' : 'Not for sale' }}</x-filament::badge><span>{{ $live->sku }} · {{ \App\Support\StoreMoney::format($live->price, $live->currency) }}</span></div>
                <p class="mt-3 text-sm">{{ $live->inventory_quantity }} on hand · {{ app(\App\Services\StockReservationService::class)->available($product, variant: $live) }} available after reservations · {{ $live->weight }} kg per unit · Live revision {{ $live->version }}</p>
                <p class="mt-2 text-sm text-gray-600">Draft edits are separate from this published version. Archiving the draft does not turn off the live option.</p>
                <details class="mt-4"><summary class="cursor-pointer text-sm font-medium">Recent publication history (latest 20)</summary><ul class="mt-3 space-y-2">@foreach($publications as $publication)@php($values = json_decode($publication->after_values, true))<li class="text-sm">{{ $publication->created_at }} · Staff #{{ $publication->actor_id ?? 'unavailable' }} · Draft revision {{ $publication->draft_version }} · {{ $values['active'] ? 'For sale' : 'Not for sale' }} · {{ \App\Support\StoreMoney::format($values['price'], $values['currency']) }} · {{ $values['inventory_quantity'] }} on hand</li>@endforeach</ul></details>
            @else<p class="text-sm text-gray-600">Draft — not for sale. No live option has been published from this draft.</p>@endif
            <div class="mt-3"><x-filament::link href="{{ route('products.show', $product) }}" target="_blank">View product in store</x-filament::link></div>
        </x-filament::section>
        <x-filament::section heading="Variant draft">
            <div class="flex flex-wrap items-center gap-3">
                <h2 class="text-xl font-semibold">{{ $draft->title }}</h2>
                <x-filament::badge color="{{ $draft->archived ? 'gray' : 'warning' }}">{{ $draft->archived ? 'Archived draft' : ($live ? 'Draft working copy' : 'Draft — not for sale') }}</x-filament::badge>
            </div>
            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                <div><dt class="text-sm text-gray-600">Proposed SKU</dt><dd class="font-medium break-all">{{ $draft->sku }}</dd></div>
                <div><dt class="text-sm text-gray-600">Proposed unit price</dt><dd class="font-medium">{{ \App\Support\StoreMoney::format($draft->price, $draft->currency) }}</dd></div>
                @foreach($draft->options as $option => $value)<div><dt class="text-sm text-gray-600">{{ $option }}</dt><dd class="font-medium break-words">{{ $value }}</dd></div>@endforeach
            </dl>
            <p class="mt-4 text-sm text-gray-600">Revision {{ $draft->version }} · Updated {{ $draft->updated_at->timezone(config('commerce.delivery_timezone'))->format('d M Y H:i') }} (South Africa)</p>
        </x-filament::section>
        <x-filament::section heading="Draft edit history" description="Previous values are retained when a draft is edited or archived.">
            <div class="space-y-4">@foreach($changes as $change)<details class="rounded-lg border border-gray-200 p-4">
                <summary class="cursor-pointer text-sm font-medium">Revision {{ $change->version }} · {{ $change->actor?->name ?? 'Staff account unavailable' }} · {{ $change->created_at->timezone(config('commerce.delivery_timezone'))->format('d M Y H:i') }} (South Africa)</summary>
                <div class="mt-4 grid gap-4 md:grid-cols-2">@foreach(['before_values' => 'Before', 'after_values' => 'After'] as $field => $heading)<div class="min-w-0">
                    <h4 class="font-semibold">{{ $heading }}</h4>
                    @if($change->{$field})
                        <p class="mt-2 text-sm break-words">{{ $change->{$field}['title'] }} · {{ $change->{$field}['sku'] }}</p>
                        <p class="text-sm">{{ \App\Support\StoreMoney::format($change->{$field}['price'], $change->{$field}['currency']) }} · {{ $change->{$field}['archived'] ? 'Archived draft' : 'Active draft' }}</p>
                        @foreach($change->{$field}['options'] as $option => $value)<p class="text-sm break-words">{{ $option }}: {{ $value }}</p>@endforeach
                    @else<p class="mt-2 text-sm text-gray-600">Draft did not exist.</p>@endif
                </div>@endforeach</div>
            </details>@endforeach</div>
            <div class="mt-4">{{ $changes->links('filament.admin.partials.pagination', ['url' => \App\Filament\Admin\Pages\ProductVariantDrafts::getUrl()]) }}</div>
        </x-filament::section>
    @else
        <x-filament::section heading="{{ $product ? 'Draft catalogue' : 'Choose a product' }}">
            <form method="GET" action="{{ \App\Filament\Admin\Pages\ProductVariantDrafts::getUrl() }}" class="flex flex-wrap items-end gap-4">
                @if($product)<input type="hidden" name="product" value="{{ $product->id }}">@endif
                <div class="min-w-0 flex-1"><label for="draft-search" class="mb-2 block text-sm font-medium">{{ $product ? 'Draft title or proposed SKU' : 'Product name' }}</label><x-filament::input.wrapper><x-filament::input id="draft-search" name="search" value="{{ $search }}" maxlength="120" /></x-filament::input.wrapper></div>
                @if($product)<div><label for="draft-status" class="mb-2 block text-sm font-medium">Draft status</label><x-filament::input.wrapper><x-filament::input.select id="draft-status" name="status">@foreach(['active' => 'Active drafts', 'archived' => 'Archived drafts', 'all' => 'All drafts'] as $value => $label)<option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></div>@endif
                <x-filament::button type="submit">Search catalogue</x-filament::button>
                <x-filament::link href="{{ \App\Filament\Admin\Pages\ProductVariantDrafts::getUrl($product ? ['product' => $product->id] : []) }}">Clear filters</x-filament::link>
            </form>
            <div class="mt-6 overflow-x-auto"><table class="w-full text-left text-sm">
                <thead><tr class="border-b border-gray-200"><th class="p-3">{{ $product ? 'Draft / proposed SKU' : 'Product' }}</th><th class="p-3">{{ $product ? 'Proposed price / status' : 'Type' }}</th><th class="p-3">Action</th></tr></thead>
                <tbody>
                    @if($product)
                        @forelse($drafts as $row)<tr class="border-b border-gray-200"><td class="p-3"><p class="font-semibold">{{ $row->title }}</p><p class="break-all text-gray-600">{{ $row->sku }}</p></td><td class="p-3">{{ \App\Support\StoreMoney::format($row->price, $row->currency) }}<p class="text-gray-600">{{ $row->archived ? 'Archived draft' : 'Active draft' }} · {{ $row->live_active === null ? 'Not published' : ($row->live_active ? 'Live option for sale' : 'Live option paused') }}</p></td><td class="p-3"><x-filament::link href="{{ \App\Filament\Admin\Pages\ProductVariantDrafts::getUrl(['product' => $product->id, 'draft' => $row->id]) }}">View draft #{{ $row->id }}</x-filament::link></td></tr>@empty<tr><td colspan="3" class="p-6 text-gray-600">No variant drafts match these filters. Use “New variant draft” to prepare an option.</td></tr>@endforelse
                    @else
                        @forelse($products as $row)<tr class="border-b border-gray-200"><td class="p-3 font-semibold">{{ $row->name }}</td><td class="p-3">{{ $row->is_downloadable ? 'Digital — draft editing not supported' : ($row->pricing_type && $row->pricing_type !== 'fixed' ? 'Non-fixed pricing — draft editing not supported' : 'Physical product') }}</td><td class="p-3"><x-filament::link href="{{ \App\Filament\Admin\Pages\ProductVariantDrafts::getUrl(['product' => $row->id]) }}">Manage drafts for product #{{ $row->id }}</x-filament::link></td></tr>@empty<tr><td colspan="3" class="p-6 text-gray-600">No products match this search.</td></tr>@endforelse
                    @endif
                </tbody>
            </table></div>
            <div class="mt-4">{{ ($product ? $drafts : $products)->links('filament.admin.partials.pagination', ['url' => \App\Filament\Admin\Pages\ProductVariantDrafts::getUrl()]) }}</div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
