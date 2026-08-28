<x-filament-panels::page>
    <div class="store-workspace">
        <section class="store-panel" aria-labelledby="guide-heading">
            <div class="store-panel-heading"><div><p class="store-eyebrow">Start here</p><h2 id="guide-heading">Your store setup guide</h2><p class="store-section-description">{{ $guide['complete'] }} of {{ $guide['total'] }} steps configured. Progress updates from saved settings, not tick boxes.</p></div><x-filament::badge color="gray">{{ $guide['complete'] }}/{{ $guide['total'] }}</x-filament::badge></div>
            <div style="padding:0 24px 16px"><progress value="{{ $guide['complete'] }}" max="{{ $guide['total'] }}" aria-label="Store setup progress" style="width:100%;height:8px;accent-color:#4f46e5"></progress></div>
            @foreach($guide['steps'] as $step)
                <div class="store-setup-row" style="padding:18px 24px;align-items:flex-start;border-top:1px solid #eef0f3">
                    <span class="store-setup-indicator {{ $step['complete'] ? 'is-configured' : '' }}" aria-hidden="true">@if($step['complete'])<x-filament::icon icon="heroicon-o-check" />@else{{ $loop->iteration }}@endif</span>
                    <div style="flex:1;min-width:0"><strong style="font-size:15px">{{ $step['label'] }}</strong><p style="font-size:13px;line-height:1.6">{{ $step['description'] }}</p>
                        <p><x-filament::badge :color="$step['complete'] ? 'success' : 'warning'">{{ $step['complete'] ? 'Configured' : 'Needs setup' }}</x-filament::badge></p>
                        @if($step['url'])<a class="store-text-link" href="{{ $step['url'] }}">{{ $step['action'] }} →</a>@else<p>Ask an administrator with the relevant permission to complete this step.</p>@endif
                    </div>
                </div>
            @endforeach
            <div class="store-panel-footer">Setup progress is not launch approval. No payments, emails or shipments are triggered by this guide.</div>
        </section>
    </div>
    <x-filament::section heading="Launch assessment" description="Read-only checks. This page never creates a payment, sends email, books delivery or changes settings.">
        <p>{{ $report['blocked_count'] }} configuration {{ $report['blocked_count'] === 1 ? 'blocker' : 'blockers' }} detected. Manual acceptance checks are still required, even when every configuration check passes.</p>
        <p class="mt-3 text-sm text-gray-600">{{ $report['scope'] }}</p>
    </x-filament::section>
    <x-filament::section heading="Configuration">
        <div class="divide-y divide-gray-200">
            @foreach ($report['checks'] as $check)
                <div class="py-4">
                    <div class="flex flex-wrap items-center justify-between gap-3"><h3 class="font-semibold">{{ $check['label'] }}</h3><x-filament::badge :color="$check['passed'] ? 'success' : 'danger'">{{ $check['passed'] ? 'Configured' : 'Action required' }}</x-filament::badge></div>
                    <p class="mt-2 text-sm text-gray-600">{{ $check['action'] }}</p>
                    @if($checkLinks[$check['id']] ?? null)<div class="mt-3"><x-filament::button tag="a" size="sm" color="gray" :href="$checkLinks[$check['id']]['url']">{{ $checkLinks[$check['id']]['label'] }}</x-filament::button></div>@elseif(! $check['passed'])<p class="mt-2 text-sm">Requires a deployment administrator; this check cannot be changed from the store editor.</p>@endif
                </div>
            @endforeach
        </div>
    </x-filament::section>
    <x-filament::section heading="Acceptance evidence still required" description="These checks are not automatically verified or marked complete by this system.">
        <ul class="list-disc space-y-3 pl-5">
            @foreach ($report['manual_reviews'] as $review)<li>{{ $review }}</li>@endforeach
        </ul>
        <div class="mt-6 flex flex-wrap gap-3">
            <x-filament::button tag="a" :href="\App\Filament\Admin\Pages\ManageGeneralSettings::getUrl()" color="gray">Business & store settings</x-filament::button>
            <x-filament::button tag="a" :href="\App\Filament\Admin\Pages\StoreIntegrations::getUrl()">Payments & delivery</x-filament::button>
        </div>
    </x-filament::section>
</x-filament-panels::page>
