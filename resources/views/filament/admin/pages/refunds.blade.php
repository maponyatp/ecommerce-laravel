<x-filament-panels::page>
    <x-filament::section heading="Refunds without guesswork" description="Request → verify externally → record evidence → issue credit note. No action on this page transfers money.">
        <p class="text-sm text-gray-600">Use the original provider to refund the customer. Record completion only after checking the payment, amount and provider refund reference. Pending requests pause fulfilment; they do not refund, restock or email anyone.</p>
        <form method="GET" action="{{ \App\Filament\Admin\Pages\Refunds::getUrl() }}" class="mt-4 flex flex-wrap items-end gap-3">
            <div><label for="refund-order" class="mb-2 block text-sm font-medium">Order number</label><x-filament::input.wrapper><x-filament::input id="refund-order" name="order" type="number" min="1" value="{{ $order?->id }}" /></x-filament::input.wrapper></div>
            <x-filament::button type="submit">Open order refunds</x-filament::button>
            <x-filament::link :href="\App\Filament\Admin\Pages\Refunds::getUrl()">All refunds</x-filament::link>
        </form>
        @if($order)
            <p class="mt-4 text-sm">Order #{{ $order->id }} · {{ $order->customer_email }} · Payment: {{ $order->payment_status }}</p>
            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach(['paid' => 'Original order total', 'recorded' => 'External refunds recorded', 'pending' => 'Pending refund requests', 'remaining' => 'Available to request'] as $key => $label)
                    <div class="rounded-lg border border-gray-200 p-4"><p class="text-sm text-gray-500">{{ $label }}</p><p class="mt-2 text-xl font-semibold">{{ $order->formatMoney(\App\Services\RefundManagementService::decimal($balance[$key])) }}</p></div>
                @endforeach
            </div>
            <p class="mt-3 text-xs text-gray-500">Unallocated invoice tax: {{ $order->formatMoney(\App\Services\RefundManagementService::decimal($balance['remaining_tax'])) }}. Recorded refunds reflect staff-checked external evidence, not automatic provider verification.</p>
            @if($order->hasPendingRefund())<p role="status" class="mt-4 text-sm font-medium text-amber-700">Fulfilment is paused until all pending refund requests are recorded or cancelled.</p>@endif
            <p class="mt-4"><x-filament::link :href="\App\Filament\Admin\Resources\Orders\OrderResource::getUrl('edit', ['record' => $order])">View order</x-filament::link> · <x-filament::link :href="\App\Filament\Admin\Pages\Returns::getUrl(['order' => $order->id])">Physical returns</x-filament::link></p>
        @endif
    </x-filament::section>
    @if($record)
        <x-filament::section :heading="'Refund request #'.$record->id">
            <p class="font-semibold">{{ \App\Services\RefundManagementService::STATUSES[$record->status] ?? $record->status }} · {{ $order->formatMoney($record->amount) }}</p>
            <p class="mt-2 whitespace-pre-wrap break-words">{{ $record->reason }}</p>
            <p class="mt-2 text-sm">Tax adjustment: {{ $order->formatMoney($record->tax_amount) }} · Original provider: {{ $record->refund_method ?: 'Not recorded' }} · Revision {{ $record->version ?? 'legacy' }}</p>
            @if($record->version === null)<p class="mt-4 text-sm text-amber-700">Legacy record: read-only until reconciled. No payment evidence has been inferred.</p>@endif
            @if($record->status === 'pending')
                <p class="mt-4 text-sm">Complete and verify the agreed refund in the provider's merchant tools, then use “Record completed external refund”. If the result is uncertain, leave this request pending and investigate. Do not retry a real refund blindly.</p>
            @endif
            @if($record->confirmation_basis === 'staff_recorded_external')
                <p class="mt-4 text-sm">Provider refund reference: <strong>{{ $record->transaction_id }}</strong></p>
                <p class="mt-2 text-sm">External completion: {{ $record->external_completed_at?->timezone('Africa/Johannesburg')->format('d M Y H:i') }} (South Africa)</p>
                <p class="mt-2 text-sm">Recorded by {{ $record->processedBy?->name ?? 'Staff account unavailable' }} · {{ $record->processed_at?->timezone('Africa/Johannesburg')->format('d M Y H:i') }}</p>
                <p class="mt-2 whitespace-pre-wrap break-words text-sm">Private evidence: {{ $record->notes }}</p>
                @if($record->creditNote)<p class="mt-4"><x-filament::link :href="route('credit-notes.show', $record->creditNote)" target="_blank">View / print {{ $record->creditNote->number }}</x-filament::link></p>@endif
                <p class="mt-4 text-sm text-amber-700">No stock was restored, customer email sent or courier booking cancelled. Review remaining delivery work separately.</p>
            @endif
        </x-filament::section>
        <x-filament::section heading="Audit history">
            @forelse($changes as $change)
                <article class="mb-3 rounded-lg border border-gray-200 p-4">
                    <p class="text-sm font-semibold">Revision {{ $change->version }} · {{ str_replace('_', ' ', $change->event) }}</p>
                    <p class="mt-1 text-xs text-gray-500">{{ $change->actor?->name ?? 'Staff account unavailable' }} · {{ $change->created_at->timezone('Africa/Johannesburg')->format('d M Y H:i') }} (South Africa)</p>
                    <p class="mt-3 whitespace-pre-wrap break-words text-sm">{{ $change->note }}</p>
                </article>
            @empty<p class="text-sm text-gray-500">No recorded history.</p>@endforelse
            {{ $changes->links() }}
        </x-filament::section>
    @else
        <x-filament::section heading="Refund register">
            <form method="GET" action="{{ \App\Filament\Admin\Pages\Refunds::getUrl() }}" class="mb-4 flex flex-wrap items-end gap-3">
                @if($order)<input type="hidden" name="order" value="{{ $order->id }}">@endif
                <div><label for="refund-status" class="mb-2 block text-sm">Status</label><x-filament::input.wrapper><x-filament::input.select id="refund-status" name="status"><option value="">All statuses</option>@foreach(\App\Services\RefundManagementService::STATUSES as $value => $label)<option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></div>
                <x-filament::button type="submit">Filter refunds</x-filament::button>
            </form>
            <div class="overflow-x-auto"><table class="w-full text-left text-sm">
                <thead><tr><th class="p-3">Request / order</th><th class="p-3">Amount</th><th class="p-3">Status</th><th class="p-3">Credit note</th><th class="p-3">Action</th></tr></thead>
                <tbody>@forelse($refunds as $row)<tr class="border-t border-gray-200">
                    <td class="p-3">#{{ $row->id }} · Order #{{ $row->order_id }}<br>{{ $row->order?->customer_email }}</td>
                    <td class="p-3">{{ $row->order?->formatMoney($row->amount) }}</td>
                    <td class="p-3">{{ \App\Services\RefundManagementService::STATUSES[$row->status] ?? $row->status }}
                        @if($row->version === null)
                            · legacy
                        @endif
                    </td>
                    <td class="p-3">{{ $row->creditNote?->number ?: 'Not issued' }}</td>
                    <td class="p-3"><x-filament::link :href="\App\Filament\Admin\Pages\Refunds::getUrl(['order' => $row->order_id, 'refund' => $row->id])">Review #{{ $row->id }}</x-filament::link></td>
                </tr>@empty<tr><td colspan="5" class="p-6 text-gray-500">No matching refunds. Open an order above to start a request.</td></tr>@endforelse</tbody>
            </table></div>
            <div class="mt-4">{{ $refunds->links() }}</div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
