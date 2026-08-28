<?php

namespace App\Services;

use App\Models\Page;
use App\Models\PageRevision;
use App\Models\User;
use App\Support\CmsContent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PagePublishingService
{
    public const FIELDS = ['title', 'slug', 'content', 'meta_title', 'meta_description', 'featured_image'];

    public function draft(Page $page): array
    {
        return $page->draft_data ?? $page->only(self::FIELDS);
    }

    public function saveDraft(?Page $page, array $input, User $actor): Page
    {
        $this->authorize($actor, $page ? 'update' : 'create', $page);
        $input['slug'] ??= $page?->slug;
        $data = $this->validate($input, $page);
        try {
            return DB::transaction(function () use ($page, $data, $actor) {
                if (! $page) {
                    if ($data['editor_version'] !== 0) {
                        $this->conflict();
                    }
                    $page = Page::create(array_intersect_key($data, array_flip(self::FIELDS)) + ['status' => Page::STATUS_DRAFT]);
                    $page->refresh();
                } else {
                    $page = $this->lock($page, $data['editor_version']);
                    $this->authorize($actor, 'update', $page);
                    if ($page->slug !== $data['slug']) {
                        throw ValidationException::withMessages(['slug' => 'The saved page URL changed. Reload this page.']);
                    }
                    $this->captureLegacy($page);
                }
                $payload = array_intersect_key($data, array_flip(self::FIELDS));
                if ($page->draft_data === $payload) {
                    return $page;
                }
                $page->forceFill(['draft_data' => $payload, 'editor_version' => $page->editor_version + 1])->save();
                $this->record($page, 'draft_saved', $payload, $actor);

                return $page->fresh();
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['slug' => 'This page URL was just used by another editor. Choose a different URL.']);
        }
    }

    public function publish(Page $page, int $expectedVersion, User $actor): Page
    {
        $this->authorize($actor, 'publish', $page);

        return DB::transaction(function () use ($page, $expectedVersion, $actor) {
            $page = $this->lock($page, $expectedVersion);
            $this->authorize($actor, 'publish', $page);
            $this->captureLegacy($page);
            $payload = array_intersect_key($this->validate($this->draft($page) + ['editor_version' => $page->editor_version], $page), array_flip(self::FIELDS));
            if ($page->isPublished() && $page->only(self::FIELDS) === $payload) {
                return $page;
            }
            $next = $page->editor_version + 1;
            $page->forceFill($payload + ['draft_data' => $payload, 'status' => Page::STATUS_PUBLISHED,
                'editor_version' => $next, 'published_version' => $next, 'published_at' => now()])->save();
            $this->record($page, 'published', $payload, $actor);

            return $page->fresh();
        }, 3);
    }

    public function unpublish(Page $page, int $expectedVersion, User $actor): Page
    {
        $this->authorize($actor, 'publish', $page);

        return DB::transaction(function () use ($page, $expectedVersion, $actor) {
            $page = $this->lock($page, $expectedVersion);
            $this->authorize($actor, 'publish', $page);
            if (! $page->isPublished()) {
                return $page;
            }
            $this->captureLegacy($page);
            $page->forceFill(['status' => Page::STATUS_DRAFT, 'editor_version' => $page->editor_version + 1])->save();
            $this->record($page, 'unpublished', $this->draft($page), $actor);

            return $page->fresh();
        }, 3);
    }

    public function restore(Page $page, int $version, int $expectedVersion, User $actor): Page
    {
        $this->authorize($actor, 'update', $page);

        return DB::transaction(function () use ($page, $version, $expectedVersion, $actor) {
            $page = $this->lock($page, $expectedVersion);
            $this->authorize($actor, 'update', $page);
            $source = $page->revisions()->where('version', $version)->first();
            if (! $source) {
                throw ValidationException::withMessages(['revision' => 'Choose a revision number from this page’s history.']);
            }
            $payload = array_intersect_key($this->validate($source->data + ['editor_version' => $page->editor_version], $page), array_flip(self::FIELDS));
            $page->forceFill(['draft_data' => $payload, 'editor_version' => $page->editor_version + 1])->save();
            $this->record($page, 'restored_as_draft', $payload, $actor, $source->version);

            return $page->fresh();
        }, 3);
    }

    private function validate(array $input, ?Page $page): array
    {
        $input['title'] = is_string($input['title'] ?? null) ? trim($input['title']) : ($input['title'] ?? null);
        $data = Validator::make($input, [
            'editor_version' => 'required|integer|min:0',
            'title' => 'required|string|max:191',
            'slug' => ['required', 'string', 'max:191', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('pages', 'slug')->ignore($page?->id)],
            'content' => 'nullable|string|max:60000', 'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500', 'featured_image' => 'nullable|string|max:255',
        ])->validate();
        if ($page && $data['slug'] !== $page->slug) {
            throw ValidationException::withMessages(['slug' => 'Existing page URLs are locked to preserve menu links.']);
        }
        if (! $page) {
            $reserved = collect(app('router')->getRoutes())->map(fn ($route) => explode('/', $route->uri())[0])
                ->filter(fn ($segment) => $segment !== '' && ! str_contains($segment, '{'))->diff(['about', 'contact', 'shop']);
            if ($reserved->contains($data['slug'])) {
                throw ValidationException::withMessages(['slug' => 'This URL belongs to a store feature. Choose a different page URL.']);
            }
        }
        if (strlen($data['content'] ?? '') > 60000) {
            throw ValidationException::withMessages(['content' => 'The page is too large. Keep the HTML under 60 KB.']);
        }
        $data['content'] = CmsContent::sanitize($data['content'] ?? '');
        if (strlen($data['content']) > 60000) {
            throw ValidationException::withMessages(['content' => 'The formatted HTML exceeds 60 KB. Shorten this page.']);
        }
        foreach (['meta_title', 'meta_description', 'featured_image'] as $key) {
            $data[$key] = filled($data[$key] ?? null) ? $data[$key] : null;
        }
        if ($data['featured_image'] && ! CmsContent::image($data['featured_image'])) {
            throw ValidationException::withMessages(['featured_image' => 'Upload a PNG, JPEG, GIF or WebP image using this page’s image control.']);
        }
        $data['editor_version'] = (int) $data['editor_version'];

        return array_replace(array_fill_keys(self::FIELDS, null), $data);
    }

    private function authorize(User $actor, string $ability, ?Page $page): void
    {
        abort_unless($actor->hasAnyRole(['admin', 'super_admin']), 403);
        Gate::forUser($actor)->authorize($ability, $page ?? Page::class);
    }

    private function lock(Page $page, int $expectedVersion): Page
    {
        $page = Page::lockForUpdate()->findOrFail($page->id);
        if ($page->editor_version !== $expectedVersion) {
            $this->conflict();
        }

        return $page;
    }

    private function conflict(): never
    {
        throw ValidationException::withMessages(['editor_version' => 'Another editor changed this page. Reload before saving, publishing or restoring.']);
    }

    private function captureLegacy(Page $page): void
    {
        if ($page->editor_version === 0 && ! $page->revisions()->exists()) {
            if ($page->isPublished() && ! $page->published_at) {
                $page->forceFill(['published_at' => $page->updated_at, 'published_version' => 0])->save();
            }
            $this->record($page, 'legacy_snapshot', $page->only(self::FIELDS), null);
        }
    }

    private function record(Page $page, string $event, array $data, ?User $actor, ?int $sourceVersion = null): void
    {
        PageRevision::create(['page_id' => $page->id, 'version' => $page->editor_version, 'event' => $event,
            'data' => $data, 'actor_id' => $actor?->id, 'source_version' => $sourceVersion, 'created_at' => now()]);
    }
}
