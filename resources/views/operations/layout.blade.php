<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>@yield('title') · {{ $branding->site_name }}</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f3f5f8;color:#172033;font:14px/1.5 Arial,Helvetica,sans-serif}
        main{max-width:1120px;margin:28px auto;background:#fff;border:1px solid #dbe1e9;border-radius:14px;padding:32px}
        header{display:flex;justify-content:space-between;gap:24px;border-bottom:2px solid #172033;padding-bottom:20px;margin-bottom:24px}
        .brand{font-size:22px;font-weight:700}.logo{display:block;max-width:180px;max-height:60px;object-fit:contain;margin-bottom:8px}
        h1{font-size:28px;line-height:1.15;margin:4px 0 8px}h2{font-size:16px;margin:0 0 10px}p{margin:6px 0}
        .muted{color:#586477;font-size:12px}.eyebrow{text-transform:uppercase;letter-spacing:.1em;font-size:11px;font-weight:700}
        .toolbar,.filters{display:flex;flex-wrap:wrap;gap:12px;align-items:end;margin-bottom:24px}
        a{color:#253c84}button,.button{display:inline-block;border:1px solid #253c84;background:#253c84;color:#fff;border-radius:7px;padding:9px 14px;text-decoration:none;font:inherit;cursor:pointer}
        label{display:block;font-size:12px;font-weight:700}select,input{display:block;border:1px solid #bfc9d6;border-radius:5px;padding:8px;font:inherit;max-width:100%}
        .grid{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin:24px 0}.box{border:1px solid #dbe1e9;border-radius:8px;padding:18px}
        .notice{padding:12px 16px;border-left:4px solid #a06d00;background:#fff8e7;margin:16px 0}.pre{white-space:pre-wrap;overflow-wrap:anywhere}
        table{width:100%;border-collapse:collapse;margin:20px 0;font-size:13px;table-layout:fixed}
        th{text-align:left;background:#f3f5f8;font-size:11px;text-transform:uppercase;letter-spacing:.04em}
        td,th{padding:12px 10px;border-bottom:1px solid #dbe1e9;vertical-align:top;overflow-wrap:anywhere}
        .check{width:18px;height:18px;display:inline-block;border:1px solid #69768a}.small{font-size:12px}.status{font-weight:700}
        footer{border-top:1px solid #dbe1e9;margin-top:28px;padding-top:14px;font-size:11px;color:#586477}.pagination{margin-top:20px}
        @media(max-width:640px){main{margin:0;padding:18px;border:0;border-radius:0}.grid{grid-template-columns:1fr}header{flex-direction:column}h1{font-size:24px}.table-wrap{overflow:auto}table{min-width:660px}.packing-table{min-width:0}}
        @page{size:A4;margin:14mm}
        @media print{body{background:#fff;font-size:11px}main{max-width:none;margin:0;padding:0;border:0;border-radius:0}.screen-only,.pagination{display:none!important}
        header{break-inside:avoid}thead{display:table-header-group}tr,.box{break-inside:avoid}table{font-size:10px;min-width:0}td,th{padding:8px 6px}
        a{color:inherit;text-decoration:none}h1{font-size:23px}.grid{grid-template-columns:1fr 1fr}.table-wrap{overflow:visible}}
    </style>
</head>
<body>
<main>
    <div class="toolbar screen-only"><a href="{{ route('filament.admin.pages.delivery-operations') }}">← Delivery operations</a>@yield('print-control')</div>
    <header>
        <div>@if($branding->site_logo_path)<img class="logo" src="{{ asset('storage/'.$branding->site_logo_path) }}" alt="{{ $branding->site_name }}">@endif<div class="brand">{{ $branding->site_name }}</div><p class="muted">Flower fulfilment</p></div>
        <div><p class="eyebrow">@yield('document-type')</p><h1>@yield('heading')</h1><p class="muted">Generated {{ $generatedAt->format('d M Y H:i') }} · South Africa</p></div>
    </header>
    @yield('content')
    <footer>Internal fulfilment document. Protect recipient contact details. This is not a tax invoice, courier label, shipment booking or proof of delivery. Recheck live order status before dispatch.</footer>
</main>
</body>
</html>
