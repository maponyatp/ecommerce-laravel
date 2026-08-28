@foreach($shippingMethods->where('requires_delivery_slot', true) as $method)
    <div data-delivery-window-method="{{ $method->id }}" hidden class="rounded-xl border border-indigo-100 bg-indigo-50 p-4">
        <label for="delivery_slot_{{ $method->id }}" class="block text-sm font-semibold text-gray-900">Choose your flower delivery window *</label>
        <p id="delivery_slot_help_{{ $method->id }}" class="mt-1 text-sm text-gray-600">All times are South African time (UTC+02:00), including for buyers overseas. Dates not listed are unavailable. A selection is confirmed only after payment and availability checks.</p>
        <select id="delivery_slot_{{ $method->id }}" name="delivery_slot_id" disabled aria-describedby="delivery_slot_help_{{ $method->id }}" class="mt-3 w-full rounded-lg border-gray-300">
            <option value="">Choose an available date and time</option>
            @foreach(($deliveryWindows[$method->id] ?? collect()) as $slot)
                <option value="{{ $slot->id }}" @selected((string) old('delivery_slot_id') === (string) $slot->id)>{{ $slot->window_label }}</option>
            @endforeach
        </select>
        @if(($deliveryWindows[$method->id] ?? collect())->isEmpty())
            <p class="mt-2 text-sm text-red-700">No delivery windows are available for this method. Choose another method or contact the shop.</p>
        @endif
        @error('delivery_slot_id')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
    </div>
@endforeach
