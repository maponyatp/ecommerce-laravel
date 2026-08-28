<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Pages\Pages\CreatePage;
use App\Filament\Admin\Resources\Pages\Pages\EditPage;
use App\Filament\Admin\Resources\Pages\Pages\ListPages;
use App\Models\Page;
use App\Models\PageRevision;
use App\Models\User;
use App\Services\PagePublishingService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CmsPublishingTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $this->actor = User::factory()->create();
        $this->actor->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($this->actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function save(?Page $page = null, array $data = []): Page
    {
        return app(PagePublishingService::class)->saveDraft($page, $data + [
            'editor_version' => $page?->editor_version ?? 0, 'title' => 'Flower care', 'slug' => $page?->slug ?? 'flower-care',
            'content' => '<p>Keep flowers in fresh water.</p>', 'meta_title' => null, 'meta_description' => null, 'featured_image' => null,
        ], $this->actor);
    }

    private function publish(Page $page): Page
    {
        return app(PagePublishingService::class)->publish($page, $page->editor_version, $this->actor);
    }

    private function preview(Page $page, ?int $version = null): string
    {
        return URL::temporarySignedRoute('cms.pages.preview', now()->addMinutes(30), ['page' => $page->id, 'version' => $version ?? $page->editor_version]);
    }

    private function invalid(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Expected validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_draft_save_and_preview_do_not_change_the_public_page_or_sitemap(): void
    {
        $draft = $this->save();
        $this->get('/flower-care')->assertNotFound();
        $this->get('/pages/flower-care')->assertNotFound();
        $this->get('/sitemap.xml')->assertDontSee('/pages/flower-care', false);
        $this->get($this->preview($draft))->assertOk()->assertSee('Private preview')->assertSee('fresh water')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertHeader('Referrer-Policy', 'no-referrer');
        $live = $this->publish($draft);
        $lastPublished = $live->published_at;
        $this->travel(2)->hours();
        $changed = $this->save($live, ['title' => 'New private heading', 'content' => '<p>Private draft advice.</p>']);
        $this->get('/flower-care')->assertOk()->assertSee('fresh water')->assertDontSee('Private draft advice');
        $this->get('/pages/flower-care')->assertOk()->assertSee('fresh water')->assertDontSee('New private heading');
        $this->get($this->preview($changed))->assertOk()->assertSee('Private draft advice');
        $this->assertEquals($lastPublished, $changed->published_at);
        $this->get('/sitemap.xml')->assertSee($lastPublished->toAtomString(), false);
        $this->assertArrayNotHasKey('draft_data', $changed->toArray());
        Mail::assertNothingSent();
    }

    public function test_publish_and_restore_retain_history_and_restore_only_as_draft(): void
    {
        $first = $this->publish($this->save());
        $second = $this->publish($this->save($first, ['content' => '<p>Version two live content.</p>']));
        $this->get('/flower-care')->assertSee('Version two live content');
        $restored = app(PagePublishingService::class)->restore($second, 1, $second->editor_version, $this->actor);
        $this->get('/flower-care')->assertSee('Version two live content');
        $this->get($this->preview($restored))->assertSee('fresh water');
        $this->publish($restored);
        $this->get('/flower-care')->assertSee('fresh water')->assertDontSee('Version two live content');
        $this->assertDatabaseHas('page_revisions', ['page_id' => $first->id, 'version' => 5, 'source_version' => 1, 'event' => 'restored_as_draft', 'actor_id' => $this->actor->id]);
        $this->assertDatabaseCount('page_revisions', 6);
    }

    public function test_unsaved_data_and_direct_status_input_cannot_publish(): void
    {
        $page = $this->save(data: ['status' => 'published']);
        $this->assertTrue($page->isDraft());
        Livewire::test(EditPage::class, ['record' => $page->slug])->fillForm(['title' => 'Unsaved private title'])
            ->callAction('publish', data: ['editor_version' => $page->editor_version])->assertHasNoActionErrors();
        $this->get('/flower-care')->assertSee('Flower care')->assertDontSee('Unsaved private title');
    }

    public function test_stale_save_publish_unpublish_and_restore_cannot_overwrite_newer_work(): void
    {
        $first = $this->publish($this->save());
        $next = $this->save($first, ['title' => 'Other editor work']);
        $service = app(PagePublishingService::class);
        $this->invalid(fn () => $this->save($first, ['title' => 'Stale editor']), 'editor_version');
        $this->invalid(fn () => $service->publish($first, $first->editor_version, $this->actor), 'editor_version');
        $this->invalid(fn () => $service->unpublish($first, $first->editor_version, $this->actor), 'editor_version');
        $this->invalid(fn () => $service->restore($first, 1, $first->editor_version, $this->actor), 'editor_version');
        $this->assertSame('Other editor work', $next->fresh()->draft_data['title']);
        $this->assertDatabaseCount('page_revisions', 3);
    }

    public function test_urls_cannot_be_changed_or_shadow_store_routes(): void
    {
        foreach (['admin', 'products', 'checkout', 'cart', 'cms', 'pages'] as $slug) {
            $this->invalid(fn () => $this->save(data: ['slug' => $slug]), 'slug');
        }
        $page = $this->publish($this->save());
        $this->invalid(fn () => $this->save($page, ['slug' => 'changed-url']), 'slug');
        $this->invalid(fn () => $this->save(data: ['slug' => 'flower-care']), 'slug');
        $this->get('/flower-care')->assertOk();
        $this->assertSame('flower-care', $page->fresh()->slug);
    }

    public function test_html_and_head_metadata_are_safe_in_drafts_live_and_legacy_content(): void
    {
        $html = '<p onclick="alert(1)">Safe advice</p><script>alert(2)</script><iframe src="https://bad.invalid"></iframe><a href="javascript:alert(3)">link</a><img src="x" onerror="alert(4)"><form action="/checkout"><input name="price"></form>';
        $page = $this->publish($this->save(data: ['content' => $html, 'meta_title' => '</title><script>alert(5)</script>', 'meta_description' => '"><script>alert(6)</script>']));
        foreach (['/flower-care', $this->preview($page)] as $url) {
            $this->get($url)->assertOk()->assertSee('Safe advice')->assertDontSee('<script>alert', false)
                ->assertDontSee('onclick=', false)->assertDontSee('onerror=', false)->assertDontSee('javascript:alert', false)->assertDontSee('<iframe', false);
        }
        Page::create(['title' => 'Legacy', 'slug' => 'legacy', 'status' => 'published', 'content' => $html]);
        $this->get('/legacy')->assertOk()->assertDontSee('<script>alert', false)->assertDontSee('onerror=', false);
        $this->invalid(fn () => $this->save(data: ['slug' => 'unsafe-image', 'featured_image' => '../private.svg']), 'featured_image');
    }

    public function test_preview_requires_valid_signature_and_authorized_staff_login(): void
    {
        $page = $this->save();
        $url = $this->preview($page);
        $this->get($url)->assertOk();
        $this->get('/cms/preview/'.$page->id.'/'.$page->editor_version)->assertForbidden();
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        auth()->logout();
        $this->get($url)->assertRedirect();
        $this->actingAs($this->actor);
        $this->travel(31)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_editors_can_save_but_need_publish_permission_and_cannot_delete_history(): void
    {
        $page = $this->save();
        $editor = User::factory()->create();
        $editor->assignRole(Role::findOrCreate('admin', 'web'));
        foreach (['view_any_page', 'view_page', 'update_page'] as $permission) {
            $editor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($editor);
        Livewire::test(EditPage::class, ['record' => $page->slug])->assertActionHidden('publish')->assertActionHidden('unpublish')
            ->fillForm(['title' => 'Editor draft'])->call('save')->assertHasNoFormErrors();
        $this->assertTrue($page->fresh()->isDraft());
        $this->assertFalse(Gate::forUser($editor)->allows('publish', $page));
        $this->assertFalse(Gate::forUser($this->actor)->allows('delete', $page));
        $this->assertFalse(Gate::forUser($this->actor)->allows('deleteAny', Page::class));
        $customer = User::factory()->create();
        $customer->givePermissionTo(Permission::findOrCreate('update_page', 'web'));
        $this->actingAs($customer)->get('/admin/pages')->assertForbidden();
    }

    public function test_page_and_revision_changes_roll_back_together(): void
    {
        $page = $this->publish($this->save());
        $version = $page->editor_version;
        PageRevision::creating(fn () => throw new \RuntimeException('Simulated history failure'));
        try {
            $this->save($page, ['title' => 'Must roll back']);
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated history failure', $e->getMessage());
        }
        $this->assertSame($version, $page->fresh()->editor_version);
        $this->assertSame('Flower care', $page->fresh()->draft_data['title']);
        $this->assertDatabaseCount('page_revisions', 2);
    }

    public function test_unpublish_preserves_draft_and_removes_public_page_and_sitemap_entry(): void
    {
        $live = $this->publish($this->save());
        $draft = $this->save($live, ['content' => 'Unpublished draft survives']);
        $hidden = app(PagePublishingService::class)->unpublish($draft, $draft->editor_version, $this->actor);
        $this->get('/flower-care')->assertNotFound();
        $this->get('/sitemap.xml')->assertDontSee('/pages/flower-care', false);
        $this->get($this->preview($hidden))->assertSee('Unpublished draft survives');
        $this->publish($hidden);
        $this->get('/flower-care')->assertSee('Unpublished draft survives');
    }

    public function test_legacy_page_is_preserved_before_first_edit_and_can_be_restored(): void
    {
        $legacy = Page::create(['title' => 'Legacy care', 'slug' => 'legacy-care', 'content' => '<p>Original legacy text.</p>', 'status' => 'published']);
        $draft = $this->save($legacy->fresh(), ['content' => 'New private work']);
        $this->assertDatabaseHas('page_revisions', ['page_id' => $legacy->id, 'version' => 0, 'event' => 'legacy_snapshot', 'actor_id' => null]);
        $this->get('/legacy-care')->assertSee('Original legacy text')->assertDontSee('New private work');
        $restored = app(PagePublishingService::class)->restore($draft, 0, $draft->editor_version, $this->actor);
        $this->assertStringContainsString('Original legacy text', $restored->draft_data['content']);
        $this->invalid(fn () => app(PagePublishingService::class)->restore($restored, 999, $restored->editor_version, $this->actor), 'revision');
    }

    public function test_admin_create_save_publish_and_restore_actions_work_end_to_end(): void
    {
        Livewire::test(CreatePage::class)->fillForm(['title' => 'Delivery promise', 'slug' => 'delivery-promise', 'content' => '<p>Fresh on arrival.</p>'])
            ->call('create')->assertHasNoFormErrors();
        $page = Page::where('slug', 'delivery-promise')->firstOrFail();
        $this->assertTrue($page->isDraft());
        $editor = Livewire::test(EditPage::class, ['record' => $page->slug]);
        $editor->fillForm(['title' => 'Our delivery promise'])->call('save')->assertHasNoFormErrors();
        $page->refresh();
        $editor->callAction('publish', data: ['editor_version' => $page->editor_version])->assertHasNoActionErrors();
        $this->get('/delivery-promise')->assertSee('Our delivery promise');
        $editor->callAction('restore', data: ['editor_version' => $page->fresh()->editor_version, 'revision' => 1])->assertHasNoActionErrors();
        $this->get('/delivery-promise')->assertSee('Our delivery promise');
        $this->assertSame('Delivery promise', $page->fresh()->draft_data['title']);
    }

    public function test_revisions_are_immutable_and_repeated_identical_save_does_not_add_history(): void
    {
        $page = $this->save();
        $this->save($page);
        $this->assertDatabaseCount('page_revisions', 1);
        $revision = $page->revisions()->firstOrFail();
        foreach (['update', 'delete'] as $operation) {
            try {
                $operation === 'update' ? $revision->update(['event' => 'forged']) : $revision->delete();
                $this->fail('History must be immutable.');
            } catch (\LogicException $exception) {
                $this->assertStringContainsString('cannot be', $exception->getMessage());
            }
        }
    }

    public function test_draft_titles_are_searchable_and_stale_publish_errors_are_visible(): void
    {
        $page = $this->publish($this->save());
        $editor = Livewire::test(EditPage::class, ['record' => $page->slug]);
        $changed = $this->save($page, ['title' => 'Searchable draft heading']);
        Livewire::test(ListPages::class)->searchTable('Searchable draft heading')->assertCanSeeTableRecords([$changed]);
        $editor->callAction('publish', data: ['editor_version' => $page->editor_version])->assertHasActionErrors(['editor_version']);
        $this->get('/flower-care')->assertSee('Flower care')->assertDontSee('Searchable draft heading');
    }

    public function test_failed_publication_rolls_back_live_content_and_status(): void
    {
        $page = $this->save();
        PageRevision::creating(fn () => throw new \RuntimeException('Publication history failed'));
        try {
            $this->publish($page);
            $this->fail('Expected history failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Publication history failed', $e->getMessage());
        }
        $this->assertTrue($page->fresh()->isDraft());
        $this->assertNull($page->fresh()->published_at);
        $this->assertDatabaseCount('page_revisions', 1);
        $this->get('/flower-care')->assertNotFound();
    }

    public function test_editor_cannot_call_publish_service_without_publication_permission(): void
    {
        $page = $this->save();
        $editor = User::factory()->create();
        $editor->assignRole(Role::findOrCreate('admin', 'web'));
        $editor->givePermissionTo(Permission::findOrCreate('update_page', 'web'));
        try {
            app(PagePublishingService::class)->publish($page, $page->editor_version, $editor);
            $this->fail('Publishing must require its own permission.');
        } catch (AuthorizationException $e) {
            $this->assertTrue($page->fresh()->isDraft());
        }
    }

    public function test_built_in_about_page_returns_when_its_cms_override_is_unpublished(): void
    {
        $page = $this->publish($this->save(data: ['slug' => 'about', 'content' => 'Our temporary CMS about text']));
        $this->get('/about')->assertSee('Our temporary CMS about text');
        app(PagePublishingService::class)->unpublish($page, $page->editor_version, $this->actor);
        $this->get('/about')->assertOk()->assertDontSee('Our temporary CMS about text');
    }
}
