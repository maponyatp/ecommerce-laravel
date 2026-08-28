@extends('operations.layout')
@section('title', 'Packing slip #'.$order->id)
@section('document-type', 'Packing slip')
@section('heading', 'Order #'.$order->id)
@section('print-control')<button type="button" onclick="window.print()">Print / Save PDF</button>@endsection
@section('content')
<p class="status">Fulfilment: {{ str($order->shipping_status)->replace('_', ' ')->title() }} @if(in_array($order->shipping_status, ['shipped', 'delivered'])) · REPRINT — check before packing again @endif</p>
@if($legacyDetails)<div class="notice">Legacy order: some item details were not saved at purchase. Current catalogue details are shown where available; verify them before packing. No historical details have been invented.</div>@endif
<div class="grid">
    <section class="box"><h2>Deliver to</h2><p><strong>{{ $order->delivery_contact_name ?: $order->recipient_name ?: 'Recipient not recorded — verify before dispatch' }}</strong></p><p class="pre">{{ $order->shipping_address }}</p>@if($order->delivery_phone)<p>Phone: {{ $order->delivery_phone }}</p>@endif</section>
    <section class="box"><h2>Delivery instructions</h2><p>{{ $order->shippingMethod?->name ?? 'Method not recorded' }}</p><p>{{ $order->delivery_window_label ?? 'No booked delivery window — confirm arrangements separately' }}</p>@if($order->delivery_carrier)<p>Team: {{ $order->delivery_carrier }}</p>@endif @if($order->supplier_tracking_number)<p>Dispatch reference: {{ $order->supplier_tracking_number }}</p>@endif</section>
</div>
<table class="packing-table">
    <thead><tr><th style="width:55px">Pack</th><th>Item</th><th style="width:50px">Qty</th><th style="width:120px">Handling</th></tr></thead>
    <tbody>@foreach($order->items as $item)
        @php($digital = $item->is_downloadable_snapshot ?? ($item->download_path ? true : $item->product?->is_downloadable))
        <tr><td>@if($digital === false)<span class="check" aria-label="Packing check box"></span>@endif</td><td>{{ $item->product_name_snapshot ?? $item->product?->name ?? 'Item #'.$item->id.' — catalogue record unavailable' }}@if($item->sku_snapshot)<br><small>SKU: {{ $item->sku_snapshot }}</small>@endif</td><td>{{ $item->quantity }}</td><td>{{ $digital === true ? 'Digital — do not pack' : ($digital === false ? 'Physical item' : 'Type unknown — verify') }}</td></tr>
    @endforeach</tbody>
</table>
@if($order->gift_message)<section class="box"><h2>Gift message</h2><p class="pre">{{ $order->gift_message }}</p></section>@endif
<div class="grid"><p>Packed by: ____________________</p><p>Checked by: ____________________</p></div>
<p class="muted">Printed check boxes are a manual checklist only. They do not update stock or order status.</p>
@endsection
