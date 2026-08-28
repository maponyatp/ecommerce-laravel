<x-filament-panels::page>
    <div class="store-panel">
        <div class="store-panel-heading"><div><p class="store-eyebrow">Connection overview</p><h2>Payments and delivery, clearly managed</h2></div></div>
        <div class="store-setup-row"><span class="store-setup-indicator {{ $paymentConfigured ? 'is-configured' : '' }}"><x-filament::icon icon="heroicon-o-credit-card" /></span><div><strong>iKhokha · {{ $paymentConfigured ? 'Credentials configured' : 'Setup required' }}</strong><p>Configuration does not confirm that credentials are valid or that payment acceptance has been tested.</p></div></div>
        @foreach($additionalPayments as $provider => $status)
            <div class="store-setup-row"><span class="store-setup-indicator"><x-filament::icon icon="heroicon-o-credit-card" /></span><div>
                <strong>{{ $status['name'] }} · Disabled at checkout</strong>
                <p>{{ ! $status['credentials_readable'] ? 'Saved credentials cannot be read; contact the administrator.' : ($status['credentials_complete'] ? 'Credential fields saved' : ($status['saved'] ? 'Setup partially saved' : 'Not configured')) }}. Checkout integration pending.</p>
                <a href="#provider-{{ $provider }}" class="text-sm font-semibold text-primary-600 underline">Manage {{ $status['name'] }}</a>
            </div></div>
        @endforeach
        <div class="store-setup-row"><span class="store-setup-indicator"><x-filament::icon icon="heroicon-o-truck" /></span><div><strong>DSV · {{ $dsvSaved ? 'Settings saved — integration pending' : 'Awaiting account details' }}</strong><p>Existing manually configured delivery methods continue to work independently.</p></div></div>
        <p class="store-setup-disclaimer">Credentials are encrypted in the database and never loaded into these fields. Keep a secure backup of the application encryption key. Blank fields retain saved values; the removal option clears them. Values configured on the server remain in use until iKhokha settings are explicitly saved here.</p>
    </div>
    <form wire:submit="saveIkhokha" class="space-y-4">
        {{ $this->ikhokhaForm }}
        <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="saveIkhokha" icon="heroicon-o-lock-closed">Save iKhokha settings</x-filament::button>
    </form>
    @foreach($additionalPayments as $provider => $status)
        <section id="provider-{{ $provider }}" class="space-y-4" style="scroll-margin-top:6rem">
            <form wire:submit="save{{ ucfirst($provider) }}" class="space-y-4">
                {{ $this->{$provider.'Form'} }}
                <div class="flex flex-wrap items-center gap-4">
                    <x-filament::button type="submit" wire:loading.attr="disabled" icon="heroicon-o-lock-closed">Save {{ $status['name'] }} settings</x-filament::button>
                    <a href="{{ $status['docs'] }}" target="_blank" rel="noopener noreferrer" class="text-sm font-semibold text-primary-600 underline">Official {{ $status['name'] }} setup guide</a>
                </div>
            </form>
        </section>
    @endforeach
    <form wire:submit="saveDsv" class="space-y-4">
        {{ $this->dsvForm }}
        <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="saveDsv" icon="heroicon-o-lock-closed">Save DSV details</x-filament::button>
    </form>
</x-filament-panels::page>
