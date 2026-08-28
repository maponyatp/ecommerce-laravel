@if($record)
    <dl class="grid gap-4 text-sm sm:grid-cols-2">
        <div><dt class="font-semibold">Order</dt><dd>#{{ $record->order_id }} · {{ $record->order->customer_email }}</dd></div>
        <div><dt class="font-semibold">Issue</dt><dd>{{ \App\Models\OrderIssue::CATEGORIES[$record->category] ?? $record->category }}</dd></div>
        <div><dt class="font-semibold">Affected item</dt><dd>{{ $record->orderItem?->product?->name ?? 'Whole order' }} @if($record->quantity) · quantity {{ $record->quantity }} @endif</dd></div>
        <div><dt class="font-semibold">Payment / fulfilment</dt><dd>{{ $record->order->payment_status }} / {{ $record->order->shipping_status }}</dd></div>
    </dl>
    <p class="mt-4 text-sm text-gray-500">Latest 100 messages. Private staff notes are never shown to customers.</p>
    @foreach($record->messages()->with('author')->latest('id')->limit(100)->get()->reverse() as $message)
        <div class="mt-3 rounded-lg border p-4 text-sm">
            <p class="font-semibold">{{ ucfirst($message->author_type) }} @if($message->is_internal) · Private note @else · Customer visible @endif</p>
            <p class="mt-2 whitespace-pre-wrap">{{ $message->body }}</p>
            <p class="mt-2 text-xs text-gray-500">{{ $message->author?->name ?? 'Guest / system' }} · {{ $message->created_at->format('d M Y H:i') }} ({{ config('app.timezone') }})</p>
        </div>
    @endforeach
@endif
