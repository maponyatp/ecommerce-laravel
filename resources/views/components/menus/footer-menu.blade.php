@if(isset($menuItems) && $menuItems->isNotEmpty())
    <ul class="grid gap-x-8 gap-y-3 sm:grid-cols-2">
        @foreach($menuItems as $item)
            <li class="{{ $item->wrapper_class }}">
                <a href="{{ $item->link }}" target="{{ $item->target }}" class="{{ trim(($item->link_class ? $item->link_class . ' ' : '') . 'text-zinc-200 transition-colors hover:text-white') }}">
                    {{ $item->name }}
                </a>
            </li>
        @endforeach
    </ul>
@endif
