<x-mail::message>
# {{ $greeting ?: ($level === 'error' ? __('Please review this update') : __('Hello!')) }}

@foreach ($introLines as $line)
{{ $line }}

@endforeach
@isset($actionText)
<x-mail::button :url="$actionUrl">{{ $actionText }}</x-mail::button>
@endisset
@foreach ($outroLines as $line)
{{ $line }}

@endforeach
@if (! empty($salutation))
{{ $salutation }}
@else
Kind regards,<br>
{{ app(\App\Support\StoreBranding::class)->current()['name'] }}
@endif
@isset($actionText)
<x-slot:subcopy>
If the “{{ $actionText }}” button does not work, copy this address into your browser:
[{{ $displayableActionUrl }}]({{ $actionUrl }})
</x-slot:subcopy>
@endisset
</x-mail::message>
