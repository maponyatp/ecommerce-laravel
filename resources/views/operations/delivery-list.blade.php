@extends('operations.layout')
@section('title', 'Delivery list '.$date)
@section('document-type', 'Daily delivery list')
@section('heading', \Carbon\Carbon::parse($date)->format('d F Y'))
@section('print-control')
    @if($printMode)<button type="button" onclick="window.print()">Print / Save PDF</button>
    @else<a class="button" href="{{ route('operations.delivery-list', ['date' => $date, 'shipping_method_id' => $method, 'state' => $state, 'print' => 1]) }}">Open complete print view</a>@endif
@endsection
@section('content')
@if(!$printMode)
    <form class="filters screen-only" method="GET" action="{{ route('operations.delivery-list') }}">
        <div><label for="delivery-date">Delivery date (South Africa)</label><input id="delivery-date" type="date" name="date" required value="{{ $date }}"></div>
        <div><label for="delivery-method">Delivery method</label><select id="delivery-method" name="shipping_method_id"><option value="">All methods</option>@foreach($methods as $option)<option value="{{ $option->id }}" @selected($method === $option->id)>{{ $option->name }}</option>@endforeach</select></div>
        <div><label for="delivery-state">Fulfilment state</label><select id="delivery-state" name="state">@foreach($states as $value => $label)<option value="{{ $value }}" @selected($state === $value)>{{ $label }}</option>@endforeach</select></div>
        <button>Apply filters</button>
    </form>
@endif
<p><strong>{{ $total }} {{ $total === 1 ? 'delivery' : 'deliveries' }}</strong> · {{ $method ? $methods->firstWhere('id', $method)?->name : 'All methods' }} · {{ $states[$state] }}</p>
<p class="muted">Confirmed bookings only. Ordered by delivery time, then order number — not an optimized driving route. Unpaid, review, cancelled and supplier-managed orders are excluded.</p>
@if($unscheduled)<div class="notice screen-only">{{ $unscheduled }} eligible undelivered orders have no booked date and are not on this list. Review them in <a href="{{ \App\Filament\Admin\Resources\Orders\OrderResource::getUrl() }}">Orders</a>; no delivery date has been assumed.</div>@endif
@if(!$printMode && $orders->hasPages())<p class="notice">This screen is paginated. Use “Open complete print view” to print the entire filtered list, not only this page.</p>@endif
<div class="table-wrap"><table>
    <thead><tr><th style="width:17%">Order / window</th><th style="width:20%">Recipient</th><th style="width:33%">Address</th><th style="width:20%">Method / status</th><th style="width:10%">Check</th></tr></thead>
    <tbody>@forelse($orders as $order)
        <tr>
            <td><strong>#{{ $order->id }}</strong><p>{{ $order->delivery_scheduled_at->copy()->timezone(config('commerce.delivery_timezone'))->format('H:i') }}–{{ $order->delivery_window_end?->copy()->timezone(config('commerce.delivery_timezone'))->format('H:i') }}</p><a class="screen-only small" href="{{ route('operations.packing-slip', $order) }}">Packing slip</a></td>
            <td>{{ $order->delivery_contact_name ?: $order->recipient_name ?: 'Not recorded — verify' }}<p>{{ $order->delivery_phone }}</p></td>
            <td class="pre">{{ $order->shipping_address }}</td>
            <td>{{ $order->shippingMethod?->name ?? 'Not recorded' }}<p class="status">{{ str($order->shipping_status)->replace('_', ' ')->title() }}</p>@if($order->supplier_tracking_number)<p class="small">{{ $order->supplier_tracking_number }}</p>@endif</td>
            <td><span class="check" aria-label="Manual delivery check box"></span></td>
        </tr>
    @empty<tr><td colspan="5">No eligible confirmed deliveries match these filters.</td></tr>@endforelse</tbody>
</table></div>
@if(!$printMode && $orders->hasPages())
    <nav class="pagination screen-only" aria-label="Delivery list pages">
        <p>Showing {{ $orders->firstItem() }}–{{ $orders->lastItem() }} of {{ $total }}</p>
        @if($orders->previousPageUrl())<a class="button" href="{{ $orders->previousPageUrl() }}">Previous page</a>@endif
        @if($orders->nextPageUrl())<a class="button" href="{{ $orders->nextPageUrl() }}">Next page</a>@endif
    </nav>
@endif
<p class="muted">Check boxes are a paper checklist, not proof of delivery. Update dispatch and delivery status in Orders after verification.</p>
@endsection
