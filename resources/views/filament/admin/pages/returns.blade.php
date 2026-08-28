<x-filament-panels::page>
    <x-filament::section heading="Flower returns and handling" description="Staff-only intake, approval and receipt records. This module does not refund payments, create credit notes, book collections, send customer emails or restock flowers.">
        <p class="text-sm text-gray-600">Inspect returned flowers before any separate inventory decision. Completing a return means physical handling is complete, not that the customer has been refunded.</p>
        <form method="GET" action="{{ \App\Filament\Admin\Pages\Returns::getUrl() }}" class="mt-4 flex flex-wrap items-end gap-3">
            <div><label for="return-order" class="mb-2 block text-sm font-medium">Order number</label><x-filament::input.wrapper><x-filament::input id="return-order" name="order" type="number" min="1" value="{{ $order?->id }}" /></x-filament::input.wrapper></div>
            <x-filament::button type="submit">Open order returns</x-filament::button>
            <x-filament::link href="{{ \App\Filament\Admin\Pages\Returns::getUrl() }}">All returns</x-filament::link>
        </form>
        @if($order)<p class="mt-4 text-sm">Order #{{ $order->id }} · {{ $order->customer_email }} · Payment: {{ $order->payment_status }} · Delivery: {{ $order->shipping_status }}</p>
            @if(\App\Filament\Admin\Pages\Refunds::canAccess())<p class="mt-3"><x-filament::link :href="\App\Filament\Admin\Pages\Refunds::getUrl(['order' => $order->id])">Open separate refunds & credit notes</x-filament::link></p>@endif
        @endif
    </x-filament::section>
    @if($record)
        <x-filament::section heading="{{ $record->rma_number }}">
            <p class="font-semibold">{{ \App\Services\ReturnManagementService::STATUSES[$record->status] ?? $record->status }}</p>
            <p class="mt-2 break-words">{{ $record->reason }}</p>
            <p class="mt-2 whitespace-pre-wrap break-words text-sm">{{ $record->description }}</p>
            <p class="mt-2 text-sm">{{ \App\Services\ReturnManagementService::METHODS[$record->return_method] ?? $record->return_method }}</p>
            @if($record->version === null)<p class="mt-4 text-sm text-amber-700">Legacy record: read-only until reconciled. No missing history or receipt quantities have been inferred.</p>@endif
            <div class="mt-4 overflow-x-auto"><table class="w-full text-left text-sm">
                <thead><tr><th class="p-3">Purchased item</th><th class="p-3">Requested</th><th class="p-3">Received</th><th class="p-3">Condition / handling</th></tr></thead>
                <tbody>@foreach($record->items as $line)<tr class="border-t border-gray-200"><td class="p-3">{{ $line->product_name_snapshot ?: 'Order item #'.$line->order_item_id }}</td><td class="p-3">{{ $line->quantity }}</td><td class="p-3">{{ $record->version === null ? 'Not recorded' : $line->received_quantity }}</td><td class="p-3">{{ \App\Services\ReturnManagementService::CONDITIONS[$line->condition] ?? $line->condition }}<br>{{ \App\Services\ReturnManagementService::DISPOSITIONS[$line->disposition] ?? $line->disposition }}</td></tr>@endforeach</tbody>
            </table></div>
            <p class="mt-4 whitespace-pre-wrap break-words text-sm">{{ $record->resolution }}</p>
            <p class="mt-4 text-xs text-gray-500">Revision {{ $record->version ?? 'legacy' }} · Last update {{ $record->updated_at->timezone(config('commerce.delivery_timezone'))->format('d M Y H:i') }} (South Africa)</p>
        </x-filament::section>
        <x-filament::section heading="Return audit history">
            @forelse($changes as $change)
                <details class="mb-3 rounded-lg border border-gray-200 p-4">
                    <summary class="text-sm font-medium">Revision {{ $change->version }} · {{ $change->actor?->name ?? 'Staff account unavailable' }} · {{ $change->created_at->timezone(config('commerce.delivery_timezone'))->format('d M Y H:i') }}</summary>
                    <p class="mt-3 whitespace-pre-wrap break-words text-sm">{{ $change->note }}</p>
                    <div class="mt-3 grid gap-4 md:grid-cols-2">@foreach(['before_values' => 'Before', 'after_values' => 'After'] as $field => $label)
                        <div><h3 class="font-semibold">{{ $label }}</h3>@if($change->{$field})
                            <p class="text-sm">{{ \App\Services\ReturnManagementService::STATUSES[$change->{$field}['status']] ?? $change->{$field}['status'] }}</p>
                            @foreach($change->{$field}['items'] as $item)<p class="mt-2 break-words text-sm">{{ $item['product_name_snapshot'] }}: {{ $item['received_quantity'] }}/{{ $item['quantity'] }} received · {{ $item['condition'] }} · {{ $item['disposition'] }}</p>@endforeach
                        @else<p class="text-sm text-gray-500">Request did not exist.</p>@endif</div>
                    @endforeach</div>
                </details>
            @empty<p class="text-sm text-gray-500">No recorded audit history.</p>@endforelse
            {{ $changes->links('filament.admin.partials.pagination', ['url' => \App\Filament\Admin\Pages\Returns::getUrl()]) }}
        </x-filament::section>
    @else
        <x-filament::section heading="Return requests">
            <form method="GET" action="{{ \App\Filament\Admin\Pages\Returns::getUrl() }}" class="mb-4 flex flex-wrap items-end gap-3">
                @if($order)<input type="hidden" name="order" value="{{ $order->id }}">@endif
                <div><label for="return-status" class="mb-2 block text-sm">Status</label><x-filament::input.wrapper><x-filament::input.select id="return-status" name="status"><option value="">All statuses</option>@foreach(\App\Services\ReturnManagementService::STATUSES as $value => $label)<option value="{{ $value }}" @selected($value === $status)>{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></div>
                <x-filament::button type="submit">Filter returns</x-filament::button>
            </form>
            <div class="overflow-x-auto"><table class="w-full text-left text-sm">
                <thead><tr><th class="p-3">Return</th><th class="p-3">Order / buyer</th><th class="p-3">Status</th><th class="p-3">Action</th></tr></thead>
                <tbody>@forelse($returns as $row)<tr class="border-t border-gray-200"><td class="p-3 break-all">{{ $row->rma_number }}</td><td class="p-3">#{{ $row->order_id }}<br>{{ $row->order?->customer_email }}</td><td class="p-3">{{ \App\Services\ReturnManagementService::STATUSES[$row->status] ?? $row->status }}</td><td class="p-3"><x-filament::link href="{{ \App\Filament\Admin\Pages\Returns::getUrl(['order' => $row->order_id, 'return' => $row->id]) }}">Review #{{ $row->id }}</x-filament::link></td></tr>@empty<tr><td colspan="4" class="p-6 text-gray-500">No return requests match. Open an order above to record a request.</td></tr>@endforelse</tbody>
            </table></div>
            <div class="mt-4">{{ $returns->links('filament.admin.partials.pagination', ['url' => \App\Filament\Admin\Pages\Returns::getUrl(), 'context' => ['order' => $this->orderId, 'return' => $this->returnId]]) }}</div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
