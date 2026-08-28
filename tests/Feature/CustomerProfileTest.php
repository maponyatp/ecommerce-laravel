<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\CustomerDirectory;
use App\Models\CustomerProfile;
use App\Models\CustomerProfileChange;
use App\Models\Order;
use App\Models\Team;
use App\Models\User;
use App\Services\CustomerProfileService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CustomerProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Http::preventStrayRequests();
    }

    private function staff(bool $edit = true): User
    {
        $user = User::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $user->assignRole(Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']));
        foreach (['view_any_order', 'view_order', ...($edit ? ['update_order'] : [])] as $name) {
            $user->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }

    private function order(array $data = []): Order
    {
        return Order::create(array_merge(['customer_email' => 'contact@example.test', 'order_date' => now(),
            'total_amount' => 100, 'currency' => 'ZAR', 'payment_status' => 'paid', 'status' => 'processing',
            'shipping_status' => 'unfulfilled'], $data))->fresh();
    }

    private function data(Order $order, array $extra = []): array
    {
        return array_merge(['identity_key' => app(CustomerProfileService::class)->identityKey($order),
            'version' => 0, 'preferred_name' => 'Flower contact', 'labels' => ['Repeat buyer'],
            'staff_notes' => 'PRIVATE CUSTOMER NOTE'], $extra);
    }

    private function invalid(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Expected validation rejection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_edit_creates_private_profile_and_audits_server_owned_actor_without_order_changes(): void
    {
        $actor = $this->staff();
        $order = $this->order();
        $before = $order->getAttributes();
        $profile = app(CustomerProfileService::class)->update($order, $this->data($order, ['actor_id' => 999, 'user_id' => 999]), $actor);
        $this->assertSame(1, $profile->version);
        $this->assertSame(['repeat buyer'], $profile->labels);
        $change = $profile->changes()->firstOrFail();
        $this->assertSame($actor->id, $change->actor_id);
        $this->assertNull($change->before_values['staff_notes']);
        $this->assertSame('PRIVATE CUSTOMER NOTE', $change->after_values['staff_notes']);
        $this->assertSame($before, $order->fresh()->getAttributes());
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_two_orders_for_one_guest_share_a_profile_and_reject_stale_creation(): void
    {
        $actor = $this->staff();
        $first = $this->order();
        $second = $this->order();
        $service = app(CustomerProfileService::class);
        $saved = $service->update($second, $this->data($second), $actor);
        $this->assertSame($saved->id, $service->find($first)->id);
        $this->invalid(fn () => $service->update($first, $this->data($first), $actor), 'staff_notes');
        $this->assertDatabaseCount('customer_profiles', 1);
        $this->assertDatabaseCount('customer_profile_changes', 1);
    }

    public function test_account_guest_case_and_team_boundaries_do_not_share_notes(): void
    {
        $actor = $this->staff();
        $guest = $this->order();
        $account = $this->order(['user_id' => $actor->id]);
        $caseVariant = $this->order(['customer_email' => 'Contact@example.test']);
        $otherTeam = $this->order();
        $team = Team::forceCreate(['name' => 'Other team', 'user_id' => $actor->id, 'personal_team' => false]);
        DB::table('orders')->where('id', $otherTeam->id)->update(['team_id' => $team->id]);
        $service = app(CustomerProfileService::class);
        $service->update($guest, $this->data($guest), $actor);
        foreach ([$account, $caseVariant, $otherTeam->fresh()] as $order) {
            $this->assertNull($service->find($order));
        }
    }

    public function test_account_email_change_keeps_notes_but_identity_reassignment_is_rejected(): void
    {
        $actor = $this->staff();
        $order = $this->order(['user_id' => $actor->id]);
        $service = app(CustomerProfileService::class);
        $profile = $service->update($order, $this->data($order), $actor);
        $order->update(['customer_email' => 'new@example.test']);
        $this->assertSame($profile->id, $service->find($order->fresh())->id);
        $stale = $this->data($order, ['version' => 1]);
        $order->update(['user_id' => null]);
        $this->invalid(fn () => $service->update($order, $stale, $actor), 'staff_notes');
        $this->assertNull($service->find($order->fresh()));
        $this->assertSame('PRIVATE CUSTOMER NOTE', $profile->fresh()->staff_notes);
    }

    public function test_repeated_unchanged_save_does_not_create_audit_noise(): void
    {
        $actor = $this->staff();
        $order = $this->order();
        $service = app(CustomerProfileService::class);
        $profile = $service->update($order, $this->data($order, ['labels' => ['Priority', 'repeat buyer', 'priority']]), $actor);
        $service->update($order, $this->data($order, ['version' => 1, 'labels' => ['repeat buyer', 'priority']]), $actor);
        $this->assertSame(1, $profile->fresh()->version);
        $this->assertDatabaseCount('customer_profile_changes', 1);
    }

    public function test_clearing_notes_retains_previous_values_in_audit(): void
    {
        $actor = $this->staff();
        $order = $this->order();
        $service = app(CustomerProfileService::class);
        $service->update($order, $this->data($order), $actor);
        $profile = $service->update($order, $this->data($order, ['version' => 1, 'preferred_name' => '', 'labels' => [], 'staff_notes' => '']), $actor);
        $this->assertNull($profile->staff_notes);
        $this->assertSame(2, $profile->version);
        $this->assertSame('PRIVATE CUSTOMER NOTE', $profile->changes()->where('version', 2)->first()->before_values['staff_notes']);
    }

    public function test_empty_first_save_does_not_create_an_empty_profile(): void
    {
        $actor = $this->staff();
        $order = $this->order();
        $this->assertNull(app(CustomerProfileService::class)->update($order,
            $this->data($order, ['preferred_name' => null, 'staff_notes' => null, 'labels' => []]), $actor));
        $this->assertDatabaseCount('customer_profiles', 0);
    }

    public function test_input_limits_and_forged_identity_are_rejected(): void
    {
        $actor = $this->staff();
        $order = $this->order();
        foreach ([['labels' => array_fill(0, 11, 'label')], ['staff_notes' => str_repeat('x', 4001)],
            ['preferred_name' => str_repeat('x', 121)], ['labels' => 'bad'], ['version' => -1]] as $extra) {
            $this->invalid(fn () => app(CustomerProfileService::class)->update($order, $this->data($order, $extra), $actor), array_key_first($extra));
        }
        $this->invalid(fn () => app(CustomerProfileService::class)->update($order, $this->data($order, ['labels' => ['<script>']]), $actor), 'labels.0');
        $this->invalid(fn () => app(CustomerProfileService::class)->update($order, $this->data($order, ['identity_key' => str_repeat('0', 64)]), $actor), 'staff_notes');
        $this->assertDatabaseCount('customer_profiles', 0);
    }

    public function test_profile_and_audit_are_atomic_if_audit_write_fails(): void
    {
        $actor = $this->staff();
        $order = $this->order();
        CustomerProfileChange::creating(fn () => throw new \RuntimeException('Simulated audit failure'));
        try {
            app(CustomerProfileService::class)->update($order, $this->data($order), $actor);
            $this->fail('Expected audit write failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated audit failure', $exception->getMessage());
        } finally {
            CustomerProfileChange::flushEventListeners();
        }
        $this->assertDatabaseCount('customer_profiles', 0);
        $this->assertDatabaseCount('customer_profile_changes', 0);
    }

    public function test_read_only_staff_cannot_edit_even_by_direct_action(): void
    {
        $actor = $this->staff(false);
        $order = $this->order();
        Livewire::withQueryParams(['profile' => $order->id])->test(CustomerDirectory::class)
            ->assertActionHidden('editPrivateProfile');
        $this->assertDatabaseCount('customer_profiles', 0);
        $this->expectException(AuthorizationException::class);
        app(CustomerProfileService::class)->update($order, $this->data($order), $actor);
    }

    public function test_real_admin_action_saves_twice_keeps_profile_context_and_exposes_validation(): void
    {
        $this->staff();
        $order = $this->order();
        $component = Livewire::withQueryParams(['profile' => $order->id])->test(CustomerDirectory::class)
            ->assertSet('profileId', $order->id)
            ->callAction('editPrivateProfile', data: ['preferred_name' => 'Staff nickname', 'labels' => ['Orchids'], 'staff_notes' => 'PRIVATE SAVED NOTE'])
            ->assertHasNoActionErrors()->assertSee('PRIVATE SAVED NOTE')->assertSee('Internal profile audit history');
        $component->callAction('editPrivateProfile', data: ['staff_notes' => 'Second note'])->assertHasNoActionErrors();
        $this->assertSame(2, CustomerProfile::firstOrFail()->version);
        $component->callAction('editPrivateProfile', data: ['version' => 0, 'staff_notes' => 'Stale note'])
            ->assertHasActionErrors(['staff_notes']);
        $this->assertSame('Second note', CustomerProfile::firstOrFail()->staff_notes);
    }

    public function test_private_data_is_escaped_and_never_rendered_on_customer_confirmation(): void
    {
        $actor = $this->staff();
        $order = $this->order();
        app(CustomerProfileService::class)->update($order, $this->data($order, ['staff_notes' => '<script>PRIVATE_NOTE</script>']), $actor);
        $this->get(route('filament.admin.pages.customer-directory', ['profile' => $order->id]))->assertOk()
            ->assertSee('&lt;script&gt;PRIVATE_NOTE&lt;/script&gt;', false)->assertDontSee('<script>PRIVATE_NOTE</script>', false);
        $this->get(URL::temporarySignedRoute('checkout.confirmation', now()->addHour(), ['order' => $order->id]))->assertOk()
            ->assertDontSee('PRIVATE_NOTE')->assertDontSee('Flower contact');
    }

    public function test_customer_cannot_access_internal_profile_or_audit(): void
    {
        $actor = $this->staff();
        $order = $this->order();
        app(CustomerProfileService::class)->update($order, $this->data($order), $actor);
        $this->actingAs(User::factory()->create())->get(route('filament.admin.pages.customer-directory', ['profile' => $order->id]))->assertForbidden();
    }

    public function test_profile_target_cannot_be_replaced_by_a_livewire_payload(): void
    {
        $this->staff();
        $order = $this->order();
        $other = $this->order(['customer_email' => 'other@example.test']);
        $component = Livewire::withQueryParams(['profile' => $order->id])->test(CustomerDirectory::class);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('profileId', $other->id);
    }

    public function test_audit_pagination_keeps_profile_and_every_revision_accessible(): void
    {
        $actor = $this->staff();
        $order = $this->order();
        for ($version = 0; $version < 12; $version++) {
            app(CustomerProfileService::class)->update($order, $this->data($order, ['version' => $version, 'staff_notes' => 'Note '.$version]), $actor);
        }
        $this->get(route('filament.admin.pages.customer-directory', ['profile' => $order->id]))->assertOk()
            ->assertSee('Revision 12')->assertDontSee('Revision 1 ·');
        $this->get(route('filament.admin.pages.customer-directory', ['profile' => $order->id, 'profile_audit_page' => 2]))
            ->assertOk()->assertSee('Revision 1 ·')->assertSee('Note 0');
    }
}
