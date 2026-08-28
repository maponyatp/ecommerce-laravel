<x-filament-panels::page>
    <div class="store-workspace">
        <section class="store-panel" aria-label="Inventory guidance">
            <div class="store-panel-heading"><div><p class="store-eyebrow">Stock control</p><h2>{{ $unit ? $unit->name : 'Every stock unit, in one place' }}</h2><p class="store-section-description">On hand is your recorded quantity. Reserved units are held for active checkouts. Available is what remains after those holds; paused options cannot be bought.</p></div></div>
            <div class="store-panel-footer" style="display:flex;flex-wrap:wrap;gap:1.25rem">
                <a class="store-text-link" href="{{ \App\Filament\Admin\Pages\Inventory::getUrl() }}">All inventory</a>
                <a class="store-text-link" href="{{ \App\Filament\Admin\Pages\Inventory::getUrl(['status' => 'low']) }}">Low stock ({{ $lowCount }})</a>
                <a class="store-text-link" href="{{ \App\Filament\Admin\Pages\Inventory::getUrl(['status' => 'out']) }}">Out of stock ({{ $outCount }})</a>
                <a class="store-text-link" href="{{ \App\Filament\Admin\Pages\Inventory::getUrl(['status' => 'paused']) }}">Paused options</a>
            </div>
        </section>
        @if($unit)
            <x-filament::section :heading="$unit->option_title ?: 'Stock details'" :description="$unit->sku ?: 'No SKU recorded'">
                <div style="display:flex;flex-wrap:wrap;gap:2.5rem">
                    @foreach(['on_hand' => 'On hand', 'reserved' => 'Reserved', 'available' => 'Available after holds'] as $key => $label)
                        <div><p style="font-size:.875rem;color:#64748b">{{ $label }}</p><strong style="font-size:1.75rem">{{ number_format($unit->$key) }}</strong></div>
                    @endforeach
                </div>
                <p style="margin-top:1rem;font-size:.875rem">{{ $unit->is_downloadable ? 'Digital allocation' : 'Physical stock' }} · {{ $unit->active ? 'Enabled' : 'Option paused — not for sale' }} · Low-stock threshold: {{ $unit->threshold }}</p>
                <p style="margin-top:.5rem;font-size:.875rem;color:#64748b">Use Adjust stock to receive units, record damaged or wilted flowers, or save a stock count. Counts below active reservations are blocked; review the affected checkouts first.</p>
            </x-filament::section>
        @else
            <x-filament::section heading="Find inventory">
                <form method="get" action="{{ \App\Filament\Admin\Pages\Inventory::getUrl() }}" style="display:flex;flex-wrap:wrap;align-items:end;gap:1rem">
                    @if($this->productId)<input type="hidden" name="product" value="{{ $this->productId }}">@endif
                    <label style="flex:1;min-width:12rem">Product, option or SKU<x-filament::input.wrapper><x-filament::input name="search" :value="$this->search" maxlength="120" /></x-filament::input.wrapper></label>
                    <label>Stock view<x-filament::input.wrapper><x-filament::input.select name="status">@foreach(['all' => 'All inventory', 'low' => 'Low stock (includes zero)', 'out' => 'Out of stock', 'paused' => 'Paused options'] as $value => $label)<option value="{{ $value }}" @selected($this->status === $value)>{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                    <x-filament::button type="submit">Apply filters</x-filament::button>
                </form>
            </x-filament::section>
            <x-filament::section heading="Stock units" description="Published options have their own stock. Unused parent quantities and unpublished drafts are excluded.">
                <div style="overflow-x:auto"><table style="width:100%;text-align:left;font-size:.875rem"><thead><tr>@foreach(['Product / option', 'SKU', 'On hand', 'Reserved', 'Available', 'Status', ''] as $heading)<th style="padding:.8rem">{{ $heading }}</th>@endforeach</tr></thead><tbody>
                    @forelse($rows as $row)<tr style="border-top:1px solid #e2e8f0">
                        <td style="padding:.8rem"><strong>{{ $row->name }}</strong>@if($row->option_title)<p>{{ $row->option_title }}</p>@endif</td><td style="padding:.8rem">{{ $row->sku ?: '—' }}</td>
                        <td style="padding:.8rem">{{ $row->on_hand }}</td><td style="padding:.8rem">{{ $row->reserved }}</td><td style="padding:.8rem">{{ $row->available }}</td>
                        <td style="padding:.8rem"><x-filament::badge color="{{ ! $row->active ? 'gray' : ($row->available <= $row->threshold ? 'warning' : 'success') }}">{{ ! $row->active ? 'Paused' : ($row->available == 0 ? 'Out of stock' : ($row->available <= $row->threshold ? 'Low stock' : 'In stock')) }}</x-filament::badge></td>
                        <td style="padding:.8rem"><x-filament::link :href="\App\Filament\Admin\Pages\Inventory::getUrl(['product' => $row->product_id, 'variant' => $row->variant_id])" :aria-label="'Manage stock for '.$row->name.' '.$row->option_title">Manage stock</x-filament::link></td>
                    </tr>@empty<tr><td colspan="7" style="padding:2rem;text-align:center">No stock units match this view. Clear the filters or add products and publish their options.</td></tr>@endforelse
                </tbody></table></div><div style="margin-top:1rem">{{ $rows->links() }}</div>
            </x-filament::section>
        @endif
        @if($history)
            <x-filament::section heading="Stock history" description="Recorded changes, newest first. Legacy entries may not contain staff or before/after quantities.">
                @forelse($history as $entry)<article style="padding:1rem 0;border-bottom:1px solid #e2e8f0">
                    <strong>{{ $entry->quantity_change > 0 ? '+' : '' }}{{ $entry->quantity_change }} units · {{ $entry->reason }}</strong>
                    <p style="font-size:.875rem;margin-top:.35rem">{{ $entry->old_quantity ?? 'Not recorded' }} → {{ $entry->new_quantity ?? 'Not recorded' }} on hand · {{ $entry->created_at->timezone(config('commerce.delivery_timezone'))->format('d M Y H:i') }} (South Africa)</p>
                    <p style="font-size:.875rem;color:#64748b">{{ $entry->reference_type === \App\Models\User::class ? ($actors[$entry->reference_id] ?? 'Staff account unavailable') : ($entry->reference_type === \App\Models\Order::class ? 'Order #'.$entry->reference_id : 'Legacy / system entry') }}</p>
                </article>@empty<p>No stock movements recorded yet.</p>@endforelse
                <div style="margin-top:1rem">{{ $history->links() }}</div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
