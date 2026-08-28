@php
    $page = $getRecord();
    $history = $page->revisions()->with('actor:id,name')->orderByDesc('version')->paginate(10, ['*'], 'history_page')->withQueryString();
    $events = ['legacy_snapshot' => 'Legacy content captured', 'draft_saved' => 'Draft saved', 'published' => 'Published', 'unpublished' => 'Unpublished', 'restored_as_draft' => 'Restored as draft'];
@endphp
<div>
    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:1rem"><x-filament::badge :color="$page->isPublished() ? 'success' : 'gray'">{{ $page->publicationLabel() }}</x-filament::badge><span>Saved revision {{ $page->editor_version }}</span></div>
    <p style="margin:1rem 0;font-size:.875rem;color:#64748b">Save before previewing or publishing. Preview links expire after 30 minutes and require a permitted staff login. Restoring a revision creates another draft; it never replaces live content immediately.</p>
    <p style="font-size:.875rem;overflow-wrap:anywhere">Stable page URL: {{ $page->publicUrl() }}</p>
    @forelse($history as $revision)
        <article style="padding:1rem 0;border-bottom:1px solid #e2e8f0">
            <div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:.75rem">
                <strong>Revision {{ $revision->version }} · {{ $events[$revision->event] ?? $revision->event }}</strong>
                <x-filament::link :href="\Illuminate\Support\Facades\URL::temporarySignedRoute('cms.pages.preview', now()->addMinutes(30), ['page' => $page->id, 'version' => $revision->version])" target="_blank">Preview revision {{ $revision->version }}</x-filament::link>
            </div>
            <p style="margin-top:.35rem;font-size:.875rem">{{ $revision->data['title'] ?? 'Untitled' }} · {{ $revision->created_at->timezone(config('commerce.delivery_timezone'))->format('d M Y H:i') }} (South Africa)</p>
            <p style="font-size:.875rem;color:#64748b">{{ $revision->event === 'legacy_snapshot' ? 'Captured before the first managed edit; original author not recorded.' : ($revision->actor?->name ?? 'Staff account unavailable') }}@if($revision->source_version !== null) · From revision {{ $revision->source_version }}@endif</p>
        </article>
    @empty
        <p style="margin-top:1rem;font-size:.875rem">No managed revisions yet. Existing content is preserved when you first save or publish.</p>
    @endforelse
    <div style="margin-top:1rem">{{ $history->links() }}</div>
</div>
