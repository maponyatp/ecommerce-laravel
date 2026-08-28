{{-- These query-string-driven pages deliberately use full GET navigation, not
     Livewire pagination actions. Keep URLs valid after an action re-render. --}}
@php
    $paginator->withPath($url)->appends($context ?? []);
@endphp
{{ $paginator->links('pagination::tailwind') }}
