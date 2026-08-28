<x-filament-panels::page>
    @if(!$anchor)
        <x-filament::section heading="Purchase contacts" description="Find customers through their order history. Only contacts with orders appear; this is not a marketing subscriber list.">
            <form method="GET" action="{{ \App\Filament\Admin\Pages\CustomerDirectory::getUrl() }}" class="flex flex-wrap items-end gap-4">
                <div class="min-w-0 flex-1"><label for="contact-search" class="mb-2 block text-sm font-medium">Receipt email or internal name</label><x-filament::input.wrapper><x-filament::input id="contact-search" name="search" value="{{ $search }}" maxlength="160" placeholder="Search email or staff display name" /></x-filament::input.wrapper></div>
                <div><label for="contact-label" class="mb-2 block text-sm font-medium">Exact staff label</label><x-filament::input.wrapper><x-filament::input id="contact-label" name="label" value="{{ $label }}" maxlength="30" placeholder="For example: orchids" /></x-filament::input.wrapper></div>
                <div><label for="contact-kind" class="mb-2 block text-sm font-medium">Contact type</label><x-filament::input.wrapper><x-filament::input.select id="contact-kind" name="kind">@foreach($kinds as $value => $label)<option value="{{ $value }}" @selected($kind === $value)>{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></div>
                <x-filament::button type="submit">Search customers</x-filament::button>
                <x-filament::link href="{{ \App\Filament\Admin\Pages\CustomerDirectory::getUrl() }}">Clear filters</x-filament::link>
            </form>
            <p class="mt-4 text-sm text-gray-600">Accounts, legacy customer records and exact guest receipt emails stay separate, including across order teams. A guest email is an unverified contact, not proof of identity or marketing consent. Recipient details are not treated as buyer details.</p>
        </x-filament::section>
        <x-filament::section heading="Customer directory">
            <p class="mb-4 text-sm">{{ $contacts->total() }} {{ \Illuminate\Support\Str::plural('purchase contact group', $contacts->total()) }}</p>
            <div class="overflow-x-auto"><table class="w-full text-left text-sm">
                <thead><tr class="border-b border-gray-200"><th class="p-3">Contact / identity</th><th class="p-3">Receipt email sample</th><th class="p-3">Orders</th><th class="p-3">Latest purchase (South Africa)</th><th class="p-3">Details</th></tr></thead>
                <tbody>@forelse($contacts as $contact)
                    <tr class="border-b border-gray-200">
                        <td class="p-3">@if($contact->preferred_name)<p class="mb-2 break-words font-semibold">{{ $contact->preferred_name }}</p>@endif{{ $kinds[$contact->contact_kind] }}@if($contact->contact_kind === 'account') #{{ $contact->account_id }}@elseif($contact->contact_kind === 'legacy') #{{ $contact->legacy_id }}@elseif($contact->contact_kind === 'unknown') #{{ $contact->profile_id }}@endif<div class="mt-2 flex flex-wrap gap-2">@foreach(json_decode($contact->staff_labels ?? '[]', true) as $staffLabel)<x-filament::badge>{{ $staffLabel }}</x-filament::badge>@endforeach</div></td>
                        <td class="p-3 break-all">{{ $contact->receipt_email ?: 'Not recorded' }}</td><td class="p-3">{{ $contact->order_count }}</td>
                        <td class="p-3">{{ $contact->last_order_at ? \Carbon\Carbon::parse($contact->last_order_at)->timezone(config('commerce.delivery_timezone'))->format('d M Y H:i') : 'Not recorded' }}</td>
                        <td class="p-3"><x-filament::link href="{{ \App\Filament\Admin\Pages\CustomerDirectory::getUrl(['profile' => $contact->profile_id]) }}" aria-label="View purchase contact {{ $contact->profile_id }}">View history</x-filament::link></td>
                    </tr>
                @empty<tr><td colspan="5" class="p-6 text-center text-gray-600">No purchase contacts match these filters.</td></tr>@endforelse</tbody>
            </table></div>
            <div class="mt-4">{{ $contacts->links() }}</div>
        </x-filament::section>
    @else
        <x-filament::section heading="Purchase history">
            <x-filament::link href="{{ \App\Filament\Admin\Pages\CustomerDirectory::getUrl() }}">← All customers</x-filament::link>
            <h2 class="mt-4 text-lg font-semibold">{{ \App\Services\CustomerDirectoryService::KINDS[$contactKind] }}@if($contactKind === 'account') #{{ $anchor->user_id }}@elseif($contactKind === 'legacy') #{{ $anchor->customer_id }}@elseif($contactKind === 'unknown') #{{ $anchor->id }}@endif</h2>
            <p class="mt-2 break-all">Reference receipt email: {{ $anchor->customer_email ?: 'Not recorded' }}</p>
            <p class="mt-3 text-sm text-gray-600">{{ $contactKind === 'guest' ? 'These guest orders share the exact receipt email; control of that email has not been verified here. They have not been linked to an account.' : 'Orders are grouped only by their existing account or legacy customer link and order team. Email similarity does not merge other purchases.' }}</p>
            <p class="mt-2 text-sm text-gray-600">Purchase history stays read-only. The internal profile below is for staff reference only: it does not change account ownership, receipt emails, marketing consent or delivery instructions.</p>
        </x-filament::section>
        <x-filament::section heading="Internal customer profile" description="Private staff reference, never shown on the storefront, invoices or packing slips. Older values remain in the audit history.">
            @if($privateProfile)
                <h3 class="text-lg font-semibold">{{ $privateProfile->preferred_name ?: 'No internal display name' }}</h3>
                <div class="mt-3 flex flex-wrap gap-2">@forelse($privateProfile->labels as $label)<x-filament::badge>{{ $label }}</x-filament::badge>@empty<span class="text-sm text-gray-600">No staff labels</span>@endforelse</div>
                <p class="mt-4 whitespace-pre-wrap break-words text-sm">{{ $privateProfile->staff_notes ?: 'No private staff notes.' }}</p>
                <p class="mt-3 text-xs text-gray-600">Revision {{ $privateProfile->version }} · Updated {{ $privateProfile->updated_at->timezone(config('commerce.delivery_timezone'))->format('d M Y H:i') }} (South Africa)</p>
            @else<p class="text-sm text-gray-600">No internal profile has been saved. Staff with order-edit permission can add a display name, labels and private notes using “Edit internal profile”.</p>@endif
        </x-filament::section>
        @if($profileChanges && $profileChanges->total())
            <x-filament::section heading="Internal profile audit history" description="Who changed the staff profile and its previous/new values. This history does not represent customer consent.">
                <div class="space-y-4">@foreach($profileChanges as $change)<details class="rounded-lg border border-gray-200 p-4">
                    <summary class="cursor-pointer text-sm font-medium">Revision {{ $change->version }} · {{ $change->actor?->name ?? 'Staff account unavailable' }} · {{ $change->created_at->timezone(config('commerce.delivery_timezone'))->format('d M Y H:i') }} (South Africa)</summary>
                    <div class="mt-4 grid gap-4 md:grid-cols-2">@foreach(['before_values' => 'Before', 'after_values' => 'After'] as $field => $heading)<div class="min-w-0 text-sm"><h4 class="font-semibold">{{ $heading }}</h4><p class="mt-2 break-words">Name: {{ $change->{$field}['preferred_name'] ?: 'Not set' }}</p><p class="mt-2 break-words">Labels: {{ implode(', ', $change->{$field}['labels']) ?: 'None' }}</p><p class="mt-2 whitespace-pre-wrap break-words">Notes: {{ $change->{$field}['staff_notes'] ?: 'None' }}</p></div>@endforeach</div>
                </details>@endforeach</div><div class="mt-4">{{ $profileChanges->links() }}</div>
            </x-filament::section>
        @endif
        <x-filament::section heading="Paid order totals by currency" description="Gross recorded paid-order totals, including order charges. Not net revenue, profit or a verified customer lifetime value; refunds and credit notes are not reconciled here.">
            <div class="flex flex-wrap gap-6">@forelse($paidTotals as $total)<div><p class="text-lg font-semibold">{{ $total->currency ? \App\Support\StoreMoney::format($total->paid_total, $total->currency) : number_format((float) $total->paid_total, 2).' (currency not recorded)' }}</p><p class="text-sm text-gray-600">{{ $total->order_count }} paid {{ \Illuminate\Support\Str::plural('order', (int) $total->order_count) }}</p></div>@empty<p class="text-sm text-gray-600">No recorded paid orders.</p>@endforelse</div>
        </x-filament::section>
        <x-filament::section heading="Orders">
            <p class="mb-3 text-sm">{{ $orders->total() }} {{ \Illuminate\Support\Str::plural('order', $orders->total()) }} in this purchase history</p>
            <div class="overflow-x-auto"><table class="w-full text-left text-sm">
                <thead><tr class="border-b border-gray-200"><th class="p-3">Order / date</th><th class="p-3">Receipt email</th><th class="p-3">Amount</th><th class="p-3">Payment / fulfilment</th><th class="p-3">Action</th></tr></thead>
                <tbody>@foreach($orders as $order)<tr class="border-b border-gray-200">
                    <td class="p-3">#{{ $order->id }}<p class="text-xs text-gray-600">{{ $order->created_at?->timezone(config('commerce.delivery_timezone'))->format('d M Y H:i') }}</p></td>
                    <td class="p-3 break-all">{{ $order->customer_email ?: 'Not recorded' }}</td><td class="p-3 whitespace-nowrap">{{ $order->formatMoney($order->total_amount) }}</td>
                    <td class="p-3">{{ str($order->payment_status)->replace('_', ' ')->title() }}<p class="text-xs">{{ str($order->shipping_status)->replace('_', ' ')->title() }}</p></td>
                    <td class="p-3">@can('update', $order)<x-filament::link href="{{ \App\Filament\Admin\Resources\Orders\OrderResource::getUrl('edit', ['record' => $order]) }}">Manage order #{{ $order->id }}</x-filament::link>@else<span class="text-gray-600">View-only access</span>@endcan</td>
                </tr>@endforeach</tbody>
            </table></div><div class="mt-4">{{ $orders->links() }}</div>
        </x-filament::section>
        @if($cases !== null)<x-filament::section heading="Order support cases">
            <div class="overflow-x-auto"><table class="w-full text-left text-sm">
                <thead><tr class="border-b border-gray-200"><th class="p-3">Case / order</th><th class="p-3">Category</th><th class="p-3">Status</th><th class="p-3">Action</th></tr></thead>
                <tbody>@forelse($cases as $case)<tr class="border-b border-gray-200"><td class="p-3">Case #{{ $case->id }} · Order #{{ $case->order_id }}</td><td class="p-3">{{ \App\Models\OrderIssue::CATEGORIES[$case->category] ?? $case->category }}</td><td class="p-3">{{ \App\Models\OrderIssue::STATUSES[$case->status] ?? $case->status }}</td><td class="p-3">@can('update', $case)<x-filament::link href="{{ \App\Filament\Admin\Resources\OrderIssues\OrderIssueResource::getUrl('edit', ['record' => $case]) }}">Review case #{{ $case->id }}</x-filament::link>@endcan</td></tr>@empty<tr><td colspan="4" class="p-6 text-center text-gray-600">No support cases for these orders.</td></tr>@endforelse</tbody>
            </table></div><div class="mt-4">{{ $cases->links() }}</div>
        </x-filament::section>@endif
    @endif
</x-filament-panels::page>
