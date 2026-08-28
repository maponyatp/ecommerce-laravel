<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\StoreIntegrations;
use App\Models\StoreIntegration;
use App\Models\User;
use App\Services\StoreIntegrationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdditionalPaymentSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        $user = User::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $user->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }

    private function credentials(string $provider): array
    {
        return array_combine(StoreIntegrationService::FIELDS[$provider], array_map(
            fn ($field) => 'synthetic-'.$provider.'-'.$field, StoreIntegrationService::FIELDS[$provider]
        ));
    }

    public function test_all_provider_forms_render_and_save_without_exposing_secrets_or_enabling_checkout(): void
    {
        $this->staff();
        Http::preventStrayRequests();
        $this->get('/admin/store-integrations')->assertOk()->assertSee('PayFast')->assertSee('Peach Payments')->assertSee('Ozow');
        $page = Livewire::test(StoreIntegrations::class)->assertOk()->set('dsvData.client_id', 'unsaved-courier');
        foreach (StoreIntegrationService::ADDITIONAL_PAYMENTS as $provider => $definition) {
            $page->assertSee('Save '.$definition['name'].' settings');
            foreach ($this->credentials($provider) as $field => $value) {
                $page->set($provider.'Data.'.$field, $value);
            }
            $page->call('save'.ucfirst($provider))->assertHasNoErrors()->assertSet('dsvData.client_id', 'unsaved-courier');
            $record = StoreIntegration::findOrFail($provider);
            $this->assertSame($this->credentials($provider), $record->credentials);
            $this->assertFalse($record->configuration['enabled']);
            foreach ($this->credentials($provider) as $field => $value) {
                $page->assertSet($provider.'Data.'.$field, '')->assertDontSee($value);
                $this->assertStringNotContainsString($value, $record->getRawOriginal('credentials'));
                $this->assertStringNotContainsString($value, json_encode(DB::table('store_integration_changes')->get()));
                $this->assertStringNotContainsString($value, json_encode(app(StoreIntegrationService::class)->additionalPaymentStatus()));
            }
            $status = app(StoreIntegrationService::class)->additionalPaymentStatus()[$provider];
            $this->assertTrue($status['credentials_complete']);
            $this->assertFalse($status['checkout_available']);
        }
        Http::assertNothingSent();
    }

    public function test_unimplemented_providers_cannot_be_enabled_even_with_complete_credentials(): void
    {
        $actor = $this->staff();
        foreach (array_keys(StoreIntegrationService::ADDITIONAL_PAYMENTS) as $provider) {
            try {
                app(StoreIntegrationService::class)->save($provider, ['enabled' => true, 'environment' => 'live'] + $this->credentials($provider), 0, $actor);
                $this->fail('Unimplemented checkout must not be enabled.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('settings', $exception->errors());
            }
        }
        $this->assertDatabaseCount('store_integrations', 0);
        $this->assertDatabaseCount('store_integration_changes', 0);
    }

    public function test_blank_fields_preserve_credentials_and_environment_switch_requires_replacement_or_removal(): void
    {
        $actor = $this->staff();
        $service = app(StoreIntegrationService::class);
        foreach (array_keys(StoreIntegrationService::ADDITIONAL_PAYMENTS) as $provider) {
            $base = ['enabled' => false, 'environment' => 'sandbox'];
            $service->save($provider, $base + $this->credentials($provider), 0, $actor);
            $service->save($provider, $base, 1, $actor);
            $this->assertSame($this->credentials($provider), StoreIntegration::find($provider)->credentials);
            try {
                $service->save($provider, ['enabled' => false, 'environment' => 'live'], 2, $actor);
                $this->fail('Must not reuse sandbox credentials in live mode.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('settings', $exception->errors());
            }
            $this->assertSame(2, StoreIntegration::find($provider)->version);
            $replacement = array_map(fn ($value) => $value.'-replacement', $this->credentials($provider));
            $service->save($provider, ['enabled' => false, 'environment' => 'live'] + $replacement, 2, $actor);
            $this->assertSame($replacement, StoreIntegration::find($provider)->credentials);
            $service->save($provider, $base + ['clear_credentials' => true], 3, $actor);
            $this->assertSame([], StoreIntegration::find($provider)->credentials);
        }
    }

    public function test_stale_and_invalid_input_do_not_overwrite_saved_settings(): void
    {
        $actor = $this->staff();
        $service = app(StoreIntegrationService::class);
        $base = ['enabled' => false, 'environment' => 'sandbox'];
        $service->save('payfast', $base + $this->credentials('payfast'), 0, $actor);
        foreach ([[$base + ['merchant_key' => 'changed'], 0], [array_replace($base, ['environment' => 'unknown']), 1], [$base + ['merchant_key' => "bad\nkey"], 1]] as [$input, $version]) {
            try {
                $service->save('payfast', $input, $version, $actor);
                $this->fail('Expected validation rejection.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertSame($this->credentials('payfast'), StoreIntegration::find('payfast')->credentials);
        $this->assertDatabaseCount('store_integration_changes', 1);
    }

    public function test_corrupt_credentials_are_reported_without_exposure_and_can_be_explicitly_cleared(): void
    {
        $actor = $this->staff();
        $service = app(StoreIntegrationService::class);
        $base = ['enabled' => false, 'environment' => 'sandbox'];
        $service->save('ozow', $base + $this->credentials('ozow'), 0, $actor);
        DB::table('store_integrations')->where('provider', 'ozow')->update(['credentials' => 'invalid-ciphertext']);
        $status = $service->additionalPaymentStatus()['ozow'];
        $this->assertFalse($status['credentials_readable']);
        $this->assertFalse($status['checkout_available']);
        Livewire::test(StoreIntegrations::class)->assertOk()->assertSee('Saved credentials cannot be read')->assertDontSee('invalid-ciphertext');
        $service->save('ozow', $base + ['clear_credentials' => true], 1, $actor);
        $this->assertSame([], StoreIntegration::find('ozow')->credentials);
    }

    public function test_revoked_super_admin_cannot_save_using_a_stale_user_instance(): void
    {
        $actor = $this->staff();
        $actor->hasRole('super_admin');
        User::find($actor->id)->syncRoles([]);
        try {
            app(StoreIntegrationService::class)->save('peach', ['enabled' => false, 'environment' => 'sandbox'], 0, $actor);
            $this->fail('Revoked access must be checked against current roles.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('store_integrations', 0);
    }
}
