@if($record)
<div class="space-y-6">
    @if($record->issues()->exists())
        <div class="text-sm"><h3 class="font-semibold">Order support cases</h3>
            @foreach($record->issues()->latest()->limit(10)->get() as $issue)
                <p class="mt-2"><a class="underline" href="{{ \App\Filament\Admin\Resources\OrderIssues\OrderIssueResource::getUrl('edit', ['record' => $issue]) }}">Case #{{ $issue->id }}</a> · {{ \App\Models\OrderIssue::STATUSES[$issue->status] }}</p>
            @endforeach
        </div>
    @endif
    @include('orders.delivery-window', ['order' => $record])
    @php($buyer = $record->invoice?->document_snapshot['buyer'] ?? $record->billing_details)
    <div class="text-sm"><h3 class="font-semibold">Buyer / invoice details</h3>
        @if($buyer)
            <p class="mt-2">{{ $buyer['name'] ?? 'Not recorded' }}</p>
            <p class="whitespace-pre-wrap">{{ $buyer['address'] ?? 'Not recorded' }}</p>
            @if($buyer['vat_number'] ?? null)<p>Buyer VAT number: {{ $buyer['vat_number'] }}</p>@endif
            <p class="mt-2 text-xs text-gray-500">The buyer may differ from the gift recipient. Issued invoice details are read-only.</p>
        @else
            <p class="mt-2 text-gray-500">Buyer billing details were not recorded. Do not assume the delivery recipient is the buyer.</p>
        @endif
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <caption class="pb-3 text-left font-semibold">Order items</caption>
            <thead><tr class="border-b"><th class="py-2">Product</th><th>Quantity</th><th>Line total</th></tr></thead>
            <tbody>
                @foreach($record->items()->with('product')->get() as $item)
                    <tr class="border-b"><td class="py-3">{{ $item->product_name_snapshot ?? $item->product?->name ?? 'Product #'.$item->product_id }}</td><td>{{ $item->quantity }}</td><td>{{ $record->formatMoney($item->price * $item->quantity) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <dl class="grid gap-3 text-sm sm:grid-cols-2">
        <div><dt class="font-semibold">Stock reservation</dt><dd>{{ $record->stock_reservation_status ?? 'Legacy order — no hold' }} @if($record->stock_reserved_until) · until {{ $record->stock_reserved_until->format('d M Y H:i') }} @endif</dd></div>
        <div><dt class="font-semibold">Receipt delivery</dt><dd>{{ $record->receipt?->status ?? ($record->confirmation_sent_at ? 'Legacy receipt queued/sent' : 'Not queued') }} @if($record->receipt) · {{ $record->receipt->attempts }} attempts @endif</dd><dd class="text-xs text-gray-500">Sent means accepted by the mail transport, not proof of inbox delivery.</dd></div>
        <div><dt class="font-semibold">Delivery method</dt><dd>{{ $record->shippingMethod?->name ?? 'Not recorded' }}</dd></div>
        <div><dt class="font-semibold">Stock committed</dt><dd>{{ $record->inventory_committed_at?->format('d M Y H:i') ?? 'No — review before dispatch' }}</dd></div>
        <div><dt class="font-semibold">Shipping / tax / discount</dt><dd>{{ $record->formatMoney($record->shipping_cost) }} / {{ $record->formatMoney($record->tax_amount) }} / {{ $record->formatMoney($record->discount_amount) }}</dd></div>
        <div><dt class="font-semibold">Gift recipient</dt><dd>{{ $record->recipient_name ?? $record->delivery_contact_name ?? 'Not recorded' }}</dd></div>
        <div class="sm:col-span-2"><dt class="font-semibold">Gift message</dt><dd class="whitespace-pre-wrap">{{ $record->gift_message ?: 'No gift message' }}</dd></div>
    </dl>
    <div>
        <h3 class="mb-3 font-semibold">Private staff notes (latest 50)</h3>
        @forelse($record->notes()->where('customer_visible', false)->with('user')->latest()->limit(50)->get() as $note)
            <div class="border-b py-3 text-sm"><p class="whitespace-pre-wrap">{{ $note->note }}</p><p class="mt-1 text-xs text-gray-500">{{ $note->user?->name ?? 'System' }} · {{ $note->created_at->format('d M Y H:i') }}</p></div>
        @empty
            <p class="text-sm text-gray-500">No private notes. Use “Add staff note” above.</p>
        @endforelse
    </div>
    <div>
        <h3 class="mb-3 font-semibold">Fulfilment history (latest 50)</h3>
        @forelse($record->statusHistory()->with('changedBy')->latest()->limit(50)->get() as $event)
            <div class="border-b py-3 text-sm"><p>{{ $event->notes }}</p><p class="mt-1 text-xs text-gray-500">{{ $event->changedBy?->name ?? 'System' }} · {{ $event->created_at->format('d M Y H:i') }}</p></div>
        @empty
            <p class="text-sm text-gray-500">No recorded staff fulfilment changes.</p>
        @endforelse
        <p class="mt-3 text-xs text-gray-500">Status changes appear on the customer's private order page. No delivery email or carrier booking is sent by this form.</p>
    </div>
</div>
@endif
