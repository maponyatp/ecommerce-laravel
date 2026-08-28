@props(['url', 'color' => 'primary', 'align' => 'center'])
@php($brand = app(\App\Support\StoreBranding::class)->current())
<table class="action" align="{{ $align }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr><td align="{{ $align }}">
<a href="{{ $url }}" class="button" target="_blank" rel="noopener" style="background-color:{{ $brand['colour'] }};border:12px solid {{ $brand['colour'] }};border-left-width:24px;border-right-width:24px;border-radius:8px;color:{{ $brand['ink'] }};font-weight:600;">{!! $slot !!}</a>
</td></tr>
</table>
