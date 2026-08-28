@php
    $document = $creditNote->document_snapshot;
    $money = fn ($amount) => $document['currency'].' '.number_format((float) $amount, 2);
    $brand = $document['branding'] ?? [];
    $colour = preg_match('/^#[0-9a-f]{6}$/i', $brand['colour'] ?? '') ? $brand['colour'] : '#18231e';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive"><title>Credit note {{ $document['number'] }}</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f5f7f6;color:#18231e;font:14px/1.6 Arial,Helvetica,sans-serif}
        main{max-width:920px;margin:auto;padding:32px 20px}nav{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
        button{border:0;border-radius:8px;padding:12px 20px;background:#18231e;color:white;font:inherit;cursor:pointer}a{color:inherit}
        article{background:white;border:1px solid #dfe5e1;border-top:5px solid {{ $colour }};border-radius:12px;padding:40px}
        header,.parties{display:grid;grid-template-columns:1fr 1fr;gap:28px;padding-bottom:24px;border-bottom:1px solid #dfe5e1}
        header>div:last-child{text-align:right}h1{font-size:28px;margin:4px 0}h2{font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#526359;margin:0 0 10px}
        p{margin:5px 0;overflow-wrap:anywhere}.muted{font-size:12px;color:#526359}.parties{padding-top:24px}.address{white-space:pre-line}
        .reason{padding:24px 0}.totals{max-width:390px;margin-left:auto}.totals div{display:flex;justify-content:space-between;gap:20px;padding:10px 0}
        .totals div:last-child{border-top:1px solid #dfe5e1;font-size:20px;font-weight:700}.note{margin-top:30px;border-top:1px solid #dfe5e1;padding-top:20px;font-size:12px;color:#526359}
        .brand{font-size:22px;font-weight:700;margin-bottom:24px}.brand img{max-width:190px;max-height:64px;object-fit:contain}
        @media(max-width:600px){main{padding:16px 10px}article{padding:22px 16px}header,.parties{grid-template-columns:1fr}header>div:last-child{text-align:left}}
        @media print{@page{size:A4;margin:15mm}body{background:white}main{padding:0;max-width:none}nav{display:none}article{border:0;padding:0}.totals,header,.parties{break-inside:avoid}}
    </style>
</head>
<body>
<main>
    <nav><a href="{{ url('/') }}">← Store</a><button onclick="window.print()">Print / save PDF</button></nav>
    <article>
        <div class="brand">@if($brand['logo_data'] ?? null)<img src="{{ $brand['logo_data'] }}" alt="{{ $brand['name'] ?? $document['seller']['name'] }}">@else{{ $brand['name'] ?? $document['seller']['name'] }}@endif</div>
        <header><div><h2>Credit note</h2><h1>{{ $document['number'] }}</h1><p class="muted">Issued {{ \Carbon\Carbon::parse($document['issued_at'])->timezone('Africa/Johannesburg')->format('d M Y H:i') }} (South Africa)</p></div>
            <div><p>Original invoice <strong>{{ $document['invoice_number'] }}</strong></p><p class="muted">Invoice date {{ \Carbon\Carbon::parse($document['invoice_issued_at'])->timezone('Africa/Johannesburg')->format('d M Y') }}</p><p class="muted">Order #{{ $document['order_id'] }} · Original total {{ $money($document['original_total']) }}</p></div></header>
        <section class="parties">
            <div><h2>Supplier</h2><p><strong>{{ $document['seller']['name'] }}</strong></p><p class="address">{{ $document['seller']['address'] }}</p>
                @if(filled($document['seller']['registration_number'] ?? null))<p class="muted">Registration: {{ $document['seller']['registration_number'] }}</p>@endif
                @if($document['vat_status'] === 'registered')<p class="muted">VAT: {{ $document['seller']['tax_number'] }}</p>@else<p class="muted">Supplier not VAT registered.</p>@endif
                <p class="muted">{{ $document['seller']['email'] ?? '' }}</p>
            </div>
            <div><h2>Recipient</h2><p>{{ $document['buyer']['name'] ?? 'Not recorded on original invoice' }}</p><p class="address">{{ $document['buyer']['address'] ?? '' }}</p>
                @if($document['buyer']['is_vat_vendor'] ?? false)<p class="muted">VAT: {{ $document['buyer']['vat_number'] ?? '' }}</p>@endif
            </div>
        </section>
        <section class="reason"><h2>Reason for credit / supply adjustment</h2><p>{{ $document['reason'] }}</p></section>
        <section class="totals">
            <div><span>Reduction excluding tax</span><strong>{{ $money($document['totals']['net']) }}</strong></div>
            <div><span>{{ $document['vat_status'] === 'registered' ? 'VAT reduction' : 'Tax reduction' }}</span><strong>{{ $money($document['totals']['tax']) }}</strong></div>
            <div><span>Total credit</span><span>{{ $money($document['totals']['total']) }}</span></div>
        </section>
        <footer class="note">This document records a reduction against the original invoice, which remains unchanged. The associated refund was recorded from staff-checked external evidence; this document does not itself transfer money. This is not a store-credit balance or gift card.</footer>
    </article>
</main>
</body>
</html>
