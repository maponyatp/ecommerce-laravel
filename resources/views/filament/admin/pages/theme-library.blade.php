<x-filament-panels::page>
    <x-filament::section heading="Design freely. Publish deliberately.">
        <p class="text-sm text-gray-600 dark:text-gray-300">Export the current design for your designer, edit the documented theme.json and images, then upload a new version. Uploading does not change the live store.</p>
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">Preview → review desktop and mobile → activate. The previous design is saved automatically. Use Activate / restore to switch back. Current settings revision: {{ $state->version }}.</p>
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">Packages include homepage content, colours, fonts, layout tokens and branding images. They do not include products, customer data, credentials, menus or CMS pages. This is the store's own format—not a Shopify, WordPress or executable-plugin installer.</p>
        <a class="mt-4 inline-block text-sm font-semibold text-primary-600 underline" href="{{ \App\Filament\Admin\Pages\ManageGeneralSettings::getUrl(['tab' => 'branding']) }}">Edit the current design and branding</a>
    </x-filament::section>

    <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
    @forelse($themes as $theme)
        <x-filament::section>
            <p class="text-xs font-semibold uppercase tracking-wide text-primary-600">{{ $state->active_theme_id === $theme->id ? 'Active base theme' : ($theme->source === 'snapshot' ? 'Saved previous design' : 'Ready for preview') }}</p>
            <h2 class="mt-2 text-lg font-semibold">{{ $theme->name }}</h2>
            <p class="mt-1 text-sm text-gray-500">#{{ $theme->id }} · {{ $theme->version }}{{ $theme->author ? ' · '.$theme->author : '' }}</p>
            <p class="mt-2 text-xs text-gray-500">{{ $theme->created_at->format('d M Y, H:i') }}</p>
            <div class="mt-4 flex gap-3">
                <x-filament::button tag="a" :href="\Illuminate\Support\Facades\URL::temporarySignedRoute('themes.preview', now()->addMinutes(30), ['theme' => $theme])" target="_blank" rel="noopener" size="sm">Private preview</x-filament::button>
                <x-filament::button tag="a" :href="route('themes.export', $theme)" color="gray" size="sm">Export ZIP</x-filament::button>
            </div>
        </x-filament::section>
    @empty
        <x-filament::section heading="Start with your current design">
            <p class="text-sm text-gray-500">Choose Export current design. The ZIP contains the design settings, supported uploaded images and a designer guide. Send it to your designer, then upload the redesigned package here.</p>
        </x-filament::section>
    @endforelse
    </div>
    {{ $themes->links() }}
    <x-filament::section heading="Recent theme activity">
        @forelse($events as $event)
            <p class="py-2 text-sm text-gray-600 dark:text-gray-300">{{ $event->created_at }} · {{ ucfirst($event->action) }} · Theme #{{ $event->theme_id }} · Staff #{{ $event->actor_id ?? 'removed' }}{{ $event->previous_theme_id ? ' · Previous design #'.$event->previous_theme_id : '' }}</p>
        @empty
            <p class="text-sm text-gray-500">Theme imports and activations will appear here.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
