@php($brand = app(\App\Support\StoreBranding::class)->current())
<x-mail::layout>
<x-slot:header>{{ $brand['name'] }} — {{ $brand['url'] }}</x-slot:header>
{{ $slot }}
@isset($subcopy)
<x-slot:subcopy><x-mail::subcopy>{{ $subcopy }}</x-mail::subcopy></x-slot:subcopy>
@endisset
<x-slot:footer>
{{ $brand['name'] }}
@if($brand['email'])
Need help? {{ $brand['email'] }}
@endif

{{ $brand['phone'] }}
{{ $brand['address'] }}
© {{ now()->year }} {{ $brand['name'] }}. All rights reserved.
</x-slot:footer>
</x-mail::layout>
