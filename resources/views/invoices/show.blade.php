@php
    $document = app(\App\Services\InvoiceDocumentService::class)->document($invoice);
    $money = fn ($value) => ($document['currency'] ?: 'Currency not recorded').' '.number_format((float) $value, 2);
    $tax = app(\App\Services\InvoiceDocumentService::class)->taxPresentation($document);
    $brand = $document['branding'] ?? null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Invoice {{ $document['number'] }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f5f7f6; color: #18231e; font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 1.5; }
        p, h1, h2, h3 { margin: 0; }
        .invoice-wrap { max-width: 920px; margin: auto; padding: 32px 20px; }
        .invoice-wrap > div { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .invoice-wrap > div a { color: #47594f; text-decoration: none; }
        button { border: 0; border-radius: 8px; padding: 12px 20px; background: #18231e; color: white; font: inherit; cursor: pointer; }
        .invoice-document { border: 1px solid #dfe5e1; border-top: 5px solid {{ $brand['colour'] ?? '#18181b' }}; border-radius: 12px; background: #fff; padding: 40px; box-shadow: 0 8px 28px #18231e08; }
        .invoice-document header { display: flex; justify-content: space-between; gap: 28px; padding-bottom: 24px; border-bottom: 1px solid #dfe5e1; }
        .invoice-document header > div { min-width: 0; max-width: 48%; }
        .invoice-document header > div:last-child { text-align: right; }
        .invoice-document h1 { font-size: 26px; letter-spacing: -.7px; margin: 6px 0; overflow-wrap: anywhere; }
        .invoice-document header h2 { font-size: 17px; }
        .invoice-document header p { margin-top: 6px; font-size: 12px; color: #526359; }
        .invoice-document header > div:first-child > p:first-child { text-transform: uppercase; letter-spacing: 2px; font-weight: 700; }
        .invoice-document section { display: grid; grid-template-columns: 1fr 1fr; gap: 28px; padding: 24px 0; }
        .invoice-document section > div:last-child { text-align: right; }
        .invoice-document section h2, .invoice-document section h3 { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #526359; }
        .invoice-document section h3 { margin-top: 14px; }
        .invoice-document section p { margin-top: 6px; font-size: 12px; }
        .whitespace-pre-line { white-space: pre-line; }
        .break-words { overflow-wrap: anywhere; }
        .overflow-x-auto { overflow-x: auto; }
        .invoice-document table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .invoice-document th { background: #f2f6f3; padding: 12px; border-block: 1px solid #dfe5e1; text-transform: uppercase; font-size: 10px; letter-spacing: 1px; text-align: left; }
        .invoice-document td { padding: 16px 12px; border-bottom: 1px solid #edf0ee; }
        .invoice-document td span { display: block; margin-top: 4px; font-size: 10px; color: #526359; }
        .invoice-document th:nth-child(2), .invoice-document td:nth-child(2) { text-align: center; }
        .invoice-document th:nth-child(n+3), .invoice-document td:nth-child(n+3) { text-align: right; }
        .invoice-totals { margin: 24px 0 0 auto; max-width: 340px; font-size: 12px; }
        .invoice-totals > div { display: flex; justify-content: space-between; gap: 20px; margin-top: 10px; }
        .invoice-totals > div:last-child { padding-top: 16px; margin-top: 16px; border-top: 1px solid #dfe5e1; font-size: 20px; font-weight: 700; }
        .invoice-logo { max-width: 190px; max-height: 64px; width: auto; height: auto; object-fit: contain; }
        .invoice-brand { display: flex; align-items: center; gap: 18px; margin-bottom: 32px; }
        .invoice-brand-name { font-size: 22px; font-weight: 700; letter-spacing: -.4px; }
        .invoice-footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #e5e7eb; font-size: 12px; color: #4b5563; line-height: 1.7; }
        .invoice-review { margin: 20px 0; padding: 14px 16px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; font-size: 12px; color: #78350f; }
        .invoice-document td { overflow-wrap: anywhere; }
        .invoice-document td:not(:first-child) { white-space: nowrap; }
        @media (max-width: 600px) {
            .invoice-wrap { padding: 16px 10px; }
            .invoice-document { padding: 20px 16px; }
            .invoice-document header { display: block; }
            .invoice-document header > div { max-width: none; }
            .invoice-document header > div:last-child { text-align: left; margin-top: 20px; }
            .invoice-document section { grid-template-columns: 1fr; }
            .invoice-document section > div:last-child { text-align: left; }
            .invoice-document table { min-width: 440px; }
        }
        @media print {
            @page { size: A4; margin: 15mm; }
            body { background: white !important; }
            .invoice-wrap > div { display: none !important; }
            .invoice-document { border: 0 !important; box-shadow: none !important; padding: 0 !important; }
            .invoice-wrap { max-width: none !important; padding: 0 !important; }
            thead { display: table-header-group; }
            tr, .invoice-totals { break-inside: avoid; }
            .invoice-document header, .invoice-brand { break-inside: avoid; }
            .invoice-document table { min-width: 0; }
            .overflow-x-auto { overflow: visible; }
            .invoice-brand { margin-bottom: 20px; }
            .invoice-document header { padding-bottom: 18px; }
            .invoice-document section { padding: 18px 0; }
            .invoice-document td { padding: 12px; }
            .invoice-footer { margin-top: 24px; }
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-900">
<main class="invoice-wrap mx-auto max-w-4xl px-4 py-10 sm:px-6">
    <div class="mb-5 flex items-center justify-between print:hidden">
        <a href="{{ url('/') }}" class="text-sm font-medium text-gray-600 hover:text-gray-900">&larr; Store home</a>
        <button type="button" onclick="window.print()" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white">Print / Save PDF</button>
    </div>
    <article class="invoice-document rounded-2xl border border-gray-200 bg-white p-7 shadow-sm sm:p-10" aria-label="Invoice document">
        @if($brand)
            <div class="invoice-brand">
                @if($brand['logo_data'] ?? null)<img class="invoice-logo" src="{{ $brand['logo_data'] }}" alt="{{ $brand['name'] }}">@endif
                <span class="invoice-brand-name">{{ $brand['name'] }}</span>
            </div>
        @endif
        <header class="flex flex-col justify-between gap-6 border-b border-gray-200 pb-8 sm:flex-row">
            <div>
                <p class="text-sm font-semibold uppercase tracking-widest text-gray-500">{{ $tax['title'] }}</p>
                <h1 class="mt-2 break-words text-3xl font-bold">{{ $document['number'] }}</h1>
                <p class="mt-2 text-sm text-gray-500">Issued {{ $document['issued_at'] ? \Illuminate\Support\Carbon::parse($document['issued_at'])->timezone('Africa/Johannesburg')->format('d F Y') : 'date not recorded' }} (South Africa)</p>
                <p class="mt-1 text-sm text-gray-600">Order #{{ $document['order_id'] }}</p>
            </div>
            <div class="min-w-0 sm:max-w-sm sm:text-right">
                @if ($document['seller'])
                    <h2 class="break-words text-xl font-bold">{{ $document['seller']['name'] }}</h2>
                    @if ($document['seller']['address'])<p class="mt-2 whitespace-pre-line break-words text-sm text-gray-600">{{ $document['seller']['address'] }}</p>@endif
                    @if ($document['seller']['registration_number'] ?? null)<p class="mt-2 break-words text-sm">Business registration: {{ $document['seller']['registration_number'] }}</p>@endif
                    @if (($document['seller']['tax_number'] ?? null) && $tax['status'] !== 'not_registered')<p class="mt-1 break-words text-sm">{{ $tax['status'] === 'registered' ? 'VAT registration' : 'Tax reference (unconfirmed)' }}: {{ $document['seller']['tax_number'] }}</p>@endif
                    <p class="mt-2 break-words text-sm text-gray-600">{{ $document['seller']['email'] ?? '' }}</p>
                    @if ($document['seller']['phone'] ?? null)<p class="text-sm text-gray-600">{{ $document['seller']['phone'] }}</p>@endif
                @else
                    <p class="text-sm text-gray-600">Seller details were not captured for this invoice.</p>
                @endif
            </div>
        </header>
        @if ($document['legacy'])
            <p class="my-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">Legacy invoice: a complete document snapshot was not recorded. Available order details are shown; missing historical information has not been reconstructed.</p>
        @endif
        @if($tax['issues'])
            <aside class="invoice-review" aria-label="Invoice review required"><strong>Invoice details require review</strong>
                @foreach($tax['issues'] as $issue)<p>{{ $issue }}</p>@endforeach
            </aside>
        @endif
        <section class="grid gap-6 py-8 sm:grid-cols-2">
            <div>
                <h2 class="text-xs font-semibold uppercase tracking-wider text-gray-500">Customer</h2>
                @if($document['buyer'] ?? null)
                    <h3 class="mt-2 text-xs font-semibold uppercase tracking-wider text-gray-500">Billed to</h3>
                    <p class="mt-2 break-words font-medium">{{ $document['buyer']['name'] }}</p>
                    <p class="mt-2 whitespace-pre-line break-words text-sm text-gray-600">{{ $document['buyer']['address'] }}</p>
                    @if($document['buyer']['vat_number'] ?? null)<p class="mt-2 text-sm">Customer VAT registration: {{ $document['buyer']['vat_number'] }}</p>@endif
                @endif
                <p class="mt-2 break-words font-medium">{{ $document['customer_email'] ?: 'Not recorded' }}</p>
                @if ($document['delivery_address'])
                    <h3 class="mt-4 text-xs font-semibold uppercase tracking-wider text-gray-500">Delivery address</h3>
                    <p class="mt-2 whitespace-pre-line break-words text-sm text-gray-600">{{ $document['delivery_address'] }}</p>
                @endif
            </div>
            <div class="sm:text-right">
                <h2 class="text-xs font-semibold uppercase tracking-wider text-gray-500">Payment</h2>
                <p class="mt-2 font-medium">{{ ucfirst($document['payment_method'] ?: 'Not recorded') }}</p>
                <p class="mt-2 text-sm">Current payment status: {{ ucfirst($invoice->payment_status ?: 'Not recorded') }}</p>
                <p class="mt-1 text-sm text-gray-600">Currency: {{ $document['currency'] ?: 'Not recorded — contact the store' }}</p>
            </div>
        </section>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-y border-gray-200 bg-gray-50 text-xs uppercase tracking-wider text-gray-500"><tr><th scope="col" class="px-4 py-3">Item</th><th scope="col" class="px-4 py-3 text-center">Qty</th><th scope="col" class="px-4 py-3 text-right">Price</th><th scope="col" class="px-4 py-3 text-right">Amount</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($document['items'] as $item)
                        <tr><td class="break-words px-4 py-4 font-medium">{{ $item['name'] }}@if($item['sku'] ?? null)<span class="mt-1 block text-xs text-gray-500">SKU: {{ $item['sku'] }}</span>@endif</td><td class="px-4 py-4 text-center">{{ $item['quantity'] }}</td><td class="px-4 py-4 text-right">{{ $money($item['unit_price']) }}</td><td class="px-4 py-4 text-right font-medium">{{ $money($item['amount']) }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-4 text-gray-600">Line-item details were not recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="invoice-totals ml-auto mt-8 max-w-sm space-y-3 text-sm">
            <div class="flex justify-between gap-4"><span>Subtotal</span><span>{{ $money($document['totals']['subtotal']) }}</span></div>
            @if ((float) $document['totals']['discount'] > 0)<div class="flex justify-between gap-4"><span>Discount</span><span>-{{ $money($document['totals']['discount']) }}</span></div>@endif
            <div class="flex justify-between gap-4"><span>Shipping</span><span>{{ $money($document['totals']['shipping']) }}</span></div>
            <div class="flex justify-between gap-4"><span>{{ $tax['status'] === 'registered' ? 'Value excluding VAT' : 'Value before tax' }}</span><span>{{ $money((float) $document['totals']['total'] - (float) $document['totals']['tax']) }}</span></div>
            <div class="flex justify-between gap-4"><span>{{ $tax['tax_label'] }}</span><span>{{ $money($document['totals']['tax']) }}</span></div>
            <div class="flex justify-between gap-4 border-t border-gray-200 pt-4 text-lg font-bold"><span>Total</span><span>{{ $money($document['totals']['total']) }}</span></div>
        </div>
        <footer class="invoice-footer">
            @if($tax['status'] === 'not_registered' && (float) $document['totals']['tax'] === 0.0)<p>Seller is not VAT registered. No VAT has been charged.</p>@endif
            @if($tax['status'] === 'registered')<p>Line prices are before VAT and order discounts. VAT shown above is included in the total.</p>@endif
            @if($document['footer_note'] ?? null)<p class="whitespace-pre-line">{{ $document['footer_note'] }}</p>@else<p>Thank you for your order.</p>@endif
            @if($document['seller']['email'] ?? null)<p>Invoice enquiries: {{ $document['seller']['email'] }} · Please quote {{ $document['number'] }}.</p>@endif
            <p>All amounts are in {{ $document['currency'] === 'ZAR' ? 'South African rand (ZAR)' : ($document['currency'] ?: 'the currency originally charged (not recorded)') }}. This document records the original sale; payment status may change separately.</p>
        </footer>
    </article>
    @if($invoice->creditNotes()->exists())
        <aside style="margin-top:24px;padding:20px;border:1px solid #dfe5e1;border-radius:12px;background:white">
            <h2 style="font-size:16px">Linked credit notes</h2>
            <p style="margin:8px 0;font-size:12px">The original invoice above is unchanged. These separate documents record subsequent credits.</p>
            @foreach($invoice->creditNotes()->orderBy('id')->get() as $credit)
                <p><a href="{{ \Illuminate\Support\Facades\URL::temporarySignedRoute('credit-notes.show', now()->addDays(30), ['creditNote' => $credit]) }}">{{ $credit->number }}</a> · {{ $credit->document_snapshot['currency'] }} {{ number_format((float) $credit->document_snapshot['totals']['total'], 2) }}</p>
            @endforeach
        </aside>
    @endif
</main>
</body>
</html>
