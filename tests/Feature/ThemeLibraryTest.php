<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\ManageGeneralSettings;
use App\Filament\Admin\Pages\ThemeLibrary;
use App\Models\StoreTheme;
use App\Models\User;
use App\Services\ThemeLibraryService;
use App\Services\ThemePackageService;
use App\Settings\GeneralSettings;
use App\Support\ThemeManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use ZipArchive;

class ThemeLibraryTest extends TestCase
{
    use RefreshDatabase;

    private array $temporary = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $settings = app(GeneralSettings::class);
        foreach (ThemeManifest::IMAGES as $key) {
            $settings->$key = null;
        }
        $settings->save();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function admin(string $role = 'super_admin'): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate($role, 'web'));

        return $user;
    }

    private function manifest(): array
    {
        return ['schema' => ThemeManifest::FORMAT, 'name' => 'Studio Bloom', 'version' => '1.2.0', 'author' => 'Design studio',
            'settings' => app(ThemeLibraryService::class)->currentSettings(), 'design' => ['hero_layout' => 'centered', 'content_width' => 'comfortable', 'corner_style' => 'soft']];
    }

    private function zip(?array $manifest = null, array $entries = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'theme-test-');
        $this->temporary[] = $path;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('theme.json', json_encode($manifest ?? $this->manifest()));
        foreach ($entries as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }
        $zip->close();

        return $path;
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    public function test_export_import_round_trip_preserves_design_and_excludes_sensitive_settings(): void
    {
        $actor = $this->admin();
        $settings = app(GeneralSettings::class);
        $settings->site_email = 'private-business@example.test';
        $settings->invoice_tax_number = '1234567890';
        $settings->hero_image_path = 'cms/home/example.png';
        $settings->save();
        Storage::disk('public')->put('cms/home/example.png', $this->png());
        $service = app(ThemePackageService::class);
        $path = $service->export(null, $actor);
        $this->temporary[] = $path;
        $contents = $service->inspect($path);
        $json = json_encode($contents['manifest']);
        $this->assertStringNotContainsString('private-business', $json);
        $this->assertStringNotContainsString('1234567890', $json);
        $theme = $service->import($path, $actor);
        $this->assertSame('assets/hero_image_path.png', $theme->settings['hero_image_path']);
        Storage::disk('local')->assertExists('theme-library/'.$theme->uuid.'/hero_image_path.png');
        $this->assertSame(0, app(ThemeLibraryService::class)->state()->version);
        $this->assertSame('cms/home/example.png', app(GeneralSettings::class)->refresh()->hero_image_path);
        $exported = $service->export($theme, $actor);
        $this->temporary[] = $exported;
        $this->assertSame($contents['manifest'], $service->inspect($exported)['manifest']);
    }

    public function test_activation_snapshots_previous_design_and_rollback_does_not_change_business_data(): void
    {
        $actor = $this->admin();
        $library = app(ThemeLibraryService::class);
        $original = $library->currentSettings();
        $business = app(GeneralSettings::class)->site_email;
        $manifest = $this->manifest();
        $manifest['settings']['hero_title'] = 'Fresh from the studio';
        $manifest['settings']['site_logo_path'] = 'assets/logo.png';
        $theme = app(ThemePackageService::class)->import($this->zip($manifest, ['assets/logo.png' => $this->png()]), $actor);
        $library->activate($theme, 0, $library->fingerprint(), $actor);
        $this->assertSame('Fresh from the studio', app(GeneralSettings::class)->hero_title);
        Storage::disk('public')->assertExists('cms/branding/themes/'.$theme->uuid.'/logo.png');
        $this->assertSame($business, app(GeneralSettings::class)->site_email);
        $previous = StoreTheme::where('source', 'snapshot')->firstOrFail();
        $library->activate($previous, 1, $library->fingerprint(), $actor);
        $this->assertSame($original, $library->currentSettings());
        $this->assertDatabaseCount('store_theme_events', 3);
        $this->assertSame(2, $library->state()->version);
    }

    public function test_stale_activation_and_unversioned_settings_changes_are_rejected(): void
    {
        $actor = $this->admin();
        $library = app(ThemeLibraryService::class);
        $theme = app(ThemePackageService::class)->import($this->zip(), $actor);
        $fingerprint = $library->fingerprint();
        $settings = app(GeneralSettings::class);
        $settings->hero_title = 'Another editor';
        $settings->save();
        $this->expectException(ValidationException::class);
        $library->activate($theme, 0, $fingerprint, $actor);
    }

    public function test_preview_is_private_signed_read_only_and_works_while_store_is_closed(): void
    {
        $actor = $this->admin();
        $manifest = $this->manifest();
        $manifest['settings']['hero_title'] = 'Private preview headline';
        $manifest['settings']['hero_image_path'] = 'assets/hero.png';
        $theme = app(ThemePackageService::class)->import($this->zip($manifest, ['assets/hero.png' => $this->png()]), $actor);
        $original = app(GeneralSettings::class)->hero_title;
        $url = URL::temporarySignedRoute('themes.preview', now()->addMinutes(30), ['theme' => $theme]);
        $this->get($url)->assertRedirect();
        $this->actingAs($this->admin('admin'))->get($url)->assertForbidden();
        $this->actingAs($actor)->get(route('themes.preview', $theme))->assertForbidden();
        $settings = app(GeneralSettings::class);
        $settings->storefront_enabled = false;
        $settings->save();
        $response = $this->actingAs($actor)->get($url)->assertOk()->assertSee('Private preview headline')->assertSee('Private homepage preview')
            ->assertSee(route('themes.asset', ['theme' => $theme, 'file' => 'hero.png']), false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString("form-action 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertSame($original, app(GeneralSettings::class)->refresh()->hero_title);
        $this->assertFalse(app(GeneralSettings::class)->storefront_enabled);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->actingAs($actor)->get(route('themes.asset', ['theme' => $theme, 'file' => 'hero.png']))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($this->admin('admin'))->get(route('themes.asset', ['theme' => $theme, 'file' => 'hero.png']))->assertForbidden();
    }

    public function test_theme_library_and_export_are_super_admin_only(): void
    {
        $this->get('/admin/theme-library')->assertRedirect();
        $this->actingAs($this->admin('admin'))->get('/admin/theme-library')->assertForbidden();
        $this->get(route('themes.export'))->assertForbidden();
        $this->actingAs($this->admin())->get('/admin/theme-library')->assertOk()->assertSee('Export current design')->assertSee('Upload theme');
        $this->get(route('themes.export'))->assertOk()->assertHeader('Content-Type', 'application/zip');
    }

    public function test_invalid_archive_entries_are_rejected_without_writing_assets(): void
    {
        foreach (['../evil.php', '/evil.png', 'assets/../evil.png', 'assets\\evil.png', 'theme.php', 'assets/evil.svg', '.env', 'assets/script.js'] as $entry) {
            try {
                app(ThemePackageService::class)->import($this->zip(null, [$entry => 'unsafe']), $this->admin());
                $this->fail($entry);
            } catch (ValidationException) {
                $this->assertSame([], Storage::disk('local')->allFiles());
            }
        }
        $this->assertDatabaseCount('store_themes', 0);
    }

    public function test_manifest_rejects_unknown_version_privileged_fields_and_unsafe_links(): void
    {
        $bad = [];
        $manifest = $this->manifest();
        $manifest['schema'] = 'flowershop-theme/v99';
        $bad[] = $manifest;
        $manifest = $this->manifest();
        $manifest['settings']['mail_password'] = 'secret';
        $bad[] = $manifest;
        $manifest = $this->manifest();
        $manifest['design']['css'] = 'body{}';
        $bad[] = $manifest;
        foreach (['javascript:alert(1)', '//evil.test', '/%2fevil.test', 'https://name:secret@evil.test', '/x%0d%0aevil', '/\\evil.test'] as $url) {
            $manifest = $this->manifest();
            $manifest['settings']['hero_primary_url'] = $url;
            $bad[] = $manifest;
        }
        foreach ($bad as $manifest) {
            try {
                app(ThemePackageService::class)->inspect($this->zip($manifest));
                $this->fail('Invalid manifest accepted');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_forged_images_missing_assets_and_duplicate_sections_are_rejected(): void
    {
        $manifest = $this->manifest();
        $manifest['settings']['hero_image_path'] = 'assets/hero.png';
        foreach ([[], ['assets/hero.png' => '<?php echo "unsafe";']] as $entries) {
            try {
                app(ThemePackageService::class)->inspect($this->zip($manifest, $entries));
                $this->fail('Invalid asset accepted');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $manifest = $this->manifest();
        $manifest['settings']['homepage_sections'][1]['section'] = 'hero';
        $this->expectException(ValidationException::class);
        app(ThemePackageService::class)->inspect($this->zip($manifest));
    }

    public function test_validate_command_has_no_import_side_effects(): void
    {
        $this->artisan('theme:validate', ['zip' => $this->zip()])->assertSuccessful();
        $this->assertDatabaseCount('store_themes', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_stale_settings_form_cannot_overwrite_an_activated_theme(): void
    {
        $actor = $this->admin();
        $this->actingAs($actor);
        $form = Livewire::test(ManageGeneralSettings::class);
        $manifest = $this->manifest();
        $manifest['settings']['hero_title'] = 'Activated design';
        $theme = app(ThemePackageService::class)->import($this->zip($manifest), $actor);
        $library = app(ThemeLibraryService::class);
        $library->activate($theme, 0, $library->fingerprint(), $actor);
        $form->call('save')->assertHasErrors(['theme']);
        $this->assertSame('Activated design', app(GeneralSettings::class)->refresh()->hero_title);
    }

    public function test_symlinks_duplicate_names_and_zip_bombs_are_rejected(): void
    {
        $duplicate = $this->zip(null, ['assets/photo.png' => $this->png(), 'assets/PHOTO.png' => $this->png()]);
        $symlink = $this->zip(null, ['assets/link.png' => $this->png()]);
        $zip = new ZipArchive;
        $zip->open($symlink);
        $zip->setExternalAttributesName('assets/link.png', 3, 0120777 << 16);
        $zip->close();
        $bomb = $this->zip(null, ['assets/huge.png' => str_repeat('A', 2 * 1024 * 1024)]);
        foreach ([$duplicate, $symlink, $bomb] as $path) {
            try {
                app(ThemePackageService::class)->inspect($path);
                $this->fail('Unsafe archive accepted');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_failed_activation_rolls_back_database_and_new_public_assets(): void
    {
        $actor = $this->admin();
        $library = app(ThemeLibraryService::class);
        $before = $library->currentSettings();
        $manifest = $this->manifest();
        $manifest['settings']['site_logo_path'] = 'assets/logo.png';
        $manifest['settings']['hero_image_path'] = 'assets/hero.png';
        $theme = app(ThemePackageService::class)->import($this->zip($manifest, ['assets/logo.png' => $this->png(), 'assets/hero.png' => $this->png()]), $actor);
        Storage::disk('local')->delete('theme-library/'.$theme->uuid.'/hero.png');
        try {
            $library->activate($theme, 0, $library->fingerprint(), $actor);
            $this->fail('Missing image accepted');
        } catch (ValidationException) {
            $this->assertSame($before, $library->currentSettings());
            $this->assertSame([], Storage::disk('public')->allFiles());
            $this->assertDatabaseCount('store_themes', 1);
            $this->assertSame(0, $library->state()->version);
        }
    }

    public function test_dark_background_and_executable_colour_are_rejected(): void
    {
        foreach (['#000000', 'red; background:url(https://evil.test)'] as $colour) {
            $manifest = $this->manifest();
            $manifest['settings']['store_background_color'] = $colour;
            try {
                ThemeManifest::validate($manifest);
                $this->fail('Unreadable or unsafe colour accepted');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_disabled_super_admin_and_expired_preview_are_rejected(): void
    {
        $actor = $this->admin();
        $theme = app(ThemePackageService::class)->import($this->zip(), $actor);
        $expired = URL::temporarySignedRoute('themes.preview', now()->subMinute(), ['theme' => $theme]);
        $this->actingAs($actor)->get($expired)->assertForbidden();
        DB::table('users')->where('id', $actor->id)->update(['staff_access_disabled_at' => now()]);
        $this->expectException(HttpException::class);
        app(ThemePackageService::class)->export($theme, $actor);
    }

    public function test_manual_settings_edits_save_a_restorable_design_and_increment_revision(): void
    {
        $this->actingAs($this->admin());
        $title = app(GeneralSettings::class)->hero_title;
        Livewire::test(ManageGeneralSettings::class)->fillForm(['hero_title' => 'Manual redesign'])->call('save')->assertHasNoErrors();
        $this->assertSame('Manual redesign', app(GeneralSettings::class)->refresh()->hero_title);
        $this->assertSame($title, StoreTheme::where('source', 'snapshot')->firstOrFail()->settings['hero_title']);
        $this->assertSame(1, app(ThemeLibraryService::class)->state()->version);
    }

    public function test_theme_actions_upload_activate_and_show_validation_errors_in_the_modal(): void
    {
        $actor = $this->admin();
        $this->actingAs($actor);
        Storage::disk('local')->put('theme-uploads/valid.zip', file_get_contents($this->zip()));
        $component = Livewire::test(ThemeLibrary::class)
            ->callAction('uploadTheme', data: ['package' => ['upload' => 'theme-uploads/valid.zip']])->assertHasNoActionErrors();
        $theme = StoreTheme::where('source', 'import')->firstOrFail();
        $component->callAction('activateTheme', data: ['theme' => $theme->id, 'confirmed' => true])->assertHasNoActionErrors();
        $this->assertSame($theme->id, app(ThemeLibraryService::class)->state()->active_theme_id);
        Storage::disk('local')->put('theme-uploads/bad.zip', file_get_contents($this->zip(null, ['script.php' => 'bad'])));
        Livewire::test(ThemeLibrary::class)->callAction('uploadTheme', data: ['package' => ['upload' => 'theme-uploads/bad.zip']])->assertHasActionErrors(['package']);
        Storage::disk('local')->assertMissing('theme-uploads/bad.zip');
    }

    public function test_new_theme_cannot_be_changed_or_deleted_in_place(): void
    {
        $theme = app(ThemePackageService::class)->import($this->zip(), $this->admin());
        $this->expectException(\LogicException::class);
        $theme->update(['name' => 'Overwritten']);
    }
}
