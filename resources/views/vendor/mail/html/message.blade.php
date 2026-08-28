@php($brand = app(\App\Support\StoreBranding::class)->current())
<x-mail::layout>
<x-slot:header>
<tr><td class="header" style="padding:32px 16px 24px;text-align:center;">
<a href="{{ $brand['url'] }}" style="display:inline-block;text-decoration:none;color:#18181b;">
@if($brand['logo_url'])
<img src="{{ $brand['logo_url'] }}" alt="{{ $brand['name'] }}" style="display:block;max-width:200px;max-height:64px;width:auto;height:auto;margin:0 auto 12px;">
@endif
<span style="font-size:22px;font-weight:700;">{{ $brand['name'] }}</span>
</a>
</td></tr>
</x-slot:header>
<div style="height:4px;background-color:{{ $brand['colour'] }};margin-bottom:28px;"></div>

{!! $slot !!}
@isset($subcopy)
<x-slot:subcopy><x-mail::subcopy>{!! $subcopy !!}</x-mail::subcopy></x-slot:subcopy>
@endisset
<x-slot:footer>
<x-mail::footer>
<p style="color:#52525b;font-weight:600;">{{ $brand['name'] }}</p>
@if($brand['email'])<p style="color:#526359;">Need help? <a style="color:#526359;" href="mailto:{{ $brand['email'] }}">{{ $brand['email'] }}</a></p>@endif
@if($brand['phone'])<p style="color:#526359;">{{ $brand['phone'] }}</p>@endif
@if($brand['address'])<p style="color:#526359;">{{ $brand['address'] }}</p>@endif
<p style="color:#526359;">&copy; {{ now()->year }} {{ $brand['name'] }}. All rights reserved.</p>
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
