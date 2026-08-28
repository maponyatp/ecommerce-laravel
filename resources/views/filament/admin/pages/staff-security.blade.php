<x-filament-panels::page>
    <x-filament::section heading="Security overview" description="Staff access, application activity and firewall decisions in one place. Logs contain metadata only, never passwords, tokens or email bodies.">
        <p class="text-sm">Firewall mode: <strong>{{ ucfirst($mode) }}</strong> · Your client address: {{ request()->ip() }}</p>
        <p class="mt-3 text-sm text-gray-600">Monitor mode does not block traffic. Enforce applies exact-address blocks and common sensitive-path probes. No geolocation service or country blocking is enabled. Configure trusted proxies at server level before relying on client IP rules.</p>
        <p class="mt-3 text-xs text-gray-500">Audit records are append-only through the application. Privileged database access can still alter them; external log retention and alerts remain separate operational controls.</p>
    </x-filament::section>
    <x-filament::section heading="IP rule history">
        <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th class="p-3">Address</th><th class="p-3">Reason</th><th class="p-3">Status</th><th class="p-3">Expiry / revision</th></tr></thead>
            <tbody>@forelse($rules as $rule)<tr class="border-t border-gray-200"><td class="p-3">{{ $rule->ip_address }}</td><td class="p-3 break-words">{{ $rule->reason }}</td><td class="p-3">{{ $rule->revoked_at ? 'Revoked' : ($rule->expires_at?->isPast() ? 'Expired' : 'Active') }}</td><td class="p-3">{{ $rule->expires_at?->timezone('Africa/Johannesburg')->format('d M Y H:i') ?: 'No expiry' }} · v{{ $rule->version }}</td></tr>@empty<tr><td colspan="4" class="p-5 text-gray-500">No IP rules recorded.</td></tr>@endforelse</tbody></table></div>
        {{ $rules->links() }}
    </x-filament::section>
    <x-filament::section heading="Staff security audit">
        <form method="GET" class="mb-4 flex flex-wrap items-end gap-3">
            <div><label for="audit-event" class="mb-2 block text-sm">Exact event (optional)</label><x-filament::input.wrapper><x-filament::input id="audit-event" name="event" value="{{ $filters['event'] ?? '' }}" placeholder="staff.account_saved" /></x-filament::input.wrapper></div>
            <div><label for="audit-actor" class="mb-2 block text-sm">Staff account ID (optional)</label><x-filament::input.wrapper><x-filament::input id="audit-actor" name="actor" type="number" min="1" value="{{ $filters['actor'] ?? '' }}" /></x-filament::input.wrapper></div>
            <x-filament::button type="submit">Filter audit</x-filament::button>
        </form>
        <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th class="p-3">Time (South Africa)</th><th class="p-3">Staff / IP</th><th class="p-3">Event / outcome</th><th class="p-3">Subject / route</th><th class="p-3">Metadata</th></tr></thead>
            <tbody>@forelse($logs as $log)<tr class="border-t border-gray-200">
                <td class="p-3">{{ $log->created_at->timezone('Africa/Johannesburg')->format('d M Y H:i:s') }}</td>
                <td class="p-3">{{ $log->actor?->name ?? 'System / unauthenticated' }}<br>{{ $log->ip_address }}</td>
                <td class="p-3">{{ $log->event }}<br><span class="text-gray-500">{{ $log->outcome }}</span></td>
                <td class="p-3">{{ $log->subject_type }} {{ $log->subject_id }}<br>{{ $log->method }} {{ $log->route_name }}</td>
                <td class="p-3 break-words text-xs">{{ json_encode($log->details, JSON_UNESCAPED_SLASHES) }}</td>
            </tr>@empty<tr><td colspan="5" class="p-5 text-gray-500">No matching security events.</td></tr>@endforelse</tbody></table></div>
        {{ $logs->links() }}
    </x-filament::section>
</x-filament-panels::page>
