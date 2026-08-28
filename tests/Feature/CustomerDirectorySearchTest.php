<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\Team;
use App\Models\User;
use App\Services\CustomerDirectoryService;
use App\Services\CustomerProfileService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CustomerDirectorySearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Http::preventStrayRequests();
        $user = User::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $user->assignRole(Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']));
        foreach (['view_any_order', 'view_order', 'update_order'] as $name) {
            $user->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function order(array $extra = []): Order
    {
        return Order::create(array_merge(['customer_email' => 'contact@example.test', 'order_date' => now(),
            'total_amount' => 100, 'currency' => 'ZAR', 'payment_status' => 'paid',
            'status' => 'processing', 'shipping_status' => 'unfulfilled'], $extra))->fresh();
    }

    private function profile(Order $order, array $extra = []): CustomerProfile
    {
        $service = app(CustomerProfileService::class);

        return $service->update($order, array_merge(['identity_key' => $service->identityKey($order),
            'version' => $service->find($order)?->version ?? 0, 'preferred_name' => 'Orchid customer',
            'labels' => ['Orchids'], 'staff_notes' => 'DO NOT SEARCH PRIVATE NOTES'], $extra), auth()->user());
    }

    private function url(array $query = []): string
    {
        return route('filament.admin.pages.customer-directory', $query);
    }

    public function test_internal_names_and_labels_are_visible_but_notes_are_not_in_directory(): void
    {
        $order = $this->order();
        $this->profile($order);
        $this->get($this->url())->assertOk()->assertSee('Orchid customer')->assertSee('orchids')
            ->assertDontSee('DO NOT SEARCH PRIVATE NOTES');
        $this->get($this->url(['search' => 'Orchid customer']))->assertOk()->assertSee('1 purchase contact group');
        $this->get($this->url(['search' => 'DO NOT SEARCH PRIVATE NOTES']))->assertOk()->assertSee('No purchase contacts match');
    }

    public function test_name_and_email_match_use_or_but_label_and_type_filters_use_and(): void
    {
        $guest = $this->order();
        $this->profile($guest);
        $account = $this->order(['user_id' => auth()->id(), 'customer_email' => 'orchid-buyer@example.test']);
        $this->profile($account, ['preferred_name' => 'Other name', 'labels' => ['Roses']]);
        $directory = app(CustomerDirectoryService::class);
        $this->assertSame(2, $directory->profiles('orchid')->count());
        $this->assertSame(1, $directory->profiles('orchid', 'guest', 'ORCHIDS')->count());
        $this->assertSame(0, $directory->profiles('orchid', 'account', 'orchids')->count());
        $this->assertSame(1, $directory->profiles('orchid', 'account', 'roses')->count());
    }

    public function test_label_matches_whole_label_and_clearing_labels_updates_results(): void
    {
        $order = $this->order();
        $this->profile($order, ['labels' => ['Rare orchids']]);
        $directory = app(CustomerDirectoryService::class);
        $this->assertSame(0, $directory->profiles('', 'all', 'orchids')->count());
        $this->assertSame(1, $directory->profiles('', 'all', 'RARE ORCHIDS')->count());
        $this->profile($order, ['labels' => []]);
        $this->assertSame(0, $directory->profiles('', 'all', 'rare orchids')->count());
    }

    public function test_profiles_do_not_cross_account_guest_case_whitespace_or_team_boundaries(): void
    {
        $guest = $this->order();
        $this->profile($guest);
        $this->order(['user_id' => auth()->id()]);
        $this->order(['customer_email' => 'Contact@example.test']);
        $this->order(['customer_email' => 'contact@example.test ']);
        $team = Team::forceCreate(['user_id' => auth()->id(), 'name' => 'Separate team', 'personal_team' => false]);
        $other = $this->order();
        DB::table('orders')->where('id', $other->id)->update(['team_id' => $team->id]);
        $directory = app(CustomerDirectoryService::class);
        $this->assertSame(5, $directory->profiles()->count());
        $this->assertSame(1, $directory->profiles('', 'all', 'orchids')->count());
        $this->assertSame($guest->id, $directory->profiles('Orchid customer')->first()->profile_id);
    }

    public function test_account_email_changes_keep_full_counts_and_correct_profile(): void
    {
        $first = $this->order(['user_id' => auth()->id(), 'customer_email' => 'old@example.test']);
        $this->profile($first);
        $this->order(['user_id' => auth()->id(), 'customer_email' => 'new@example.test']);
        $row = app(CustomerDirectoryService::class)->profiles('old@example.test', 'account', 'orchids')->first();
        $this->assertSame(2, $row->order_count);
        $this->assertSame('Orchid customer', $row->preferred_name);
    }

    public function test_old_profile_is_not_joined_after_order_identity_changes(): void
    {
        $order = $this->order();
        $this->profile($order);
        $order->update(['customer_email' => 'changed@example.test']);
        $directory = app(CustomerDirectoryService::class);
        $this->assertSame(0, $directory->profiles('', 'all', 'orchids')->count());
        $this->assertNull($directory->profiles()->first()->preferred_name);
        $this->assertDatabaseCount('customer_profiles', 1);
    }

    public function test_name_search_escapes_wildcards_and_label_validation_rejects_invalid_input(): void
    {
        $this->profile($this->order(), ['preferred_name' => 'Contact 100%_!']);
        $directory = app(CustomerDirectoryService::class);
        $this->assertSame(1, $directory->profiles('%_!')->count());
        $this->assertSame(0, $directory->profiles("' OR 1=1 --")->count());
        foreach (['<script>', str_repeat('a', 31), ['bad']] as $label) {
            $this->get($this->url(['label' => $label]))->assertUnprocessable();
        }
    }

    public function test_filter_paginates_all_matches_and_preserves_query_parameters(): void
    {
        for ($i = 0; $i < 26; $i++) {
            $this->profile($this->order(['customer_email' => 'contact'.$i.'@example.test']), ['preferred_name' => 'Orchid buyer '.$i]);
        }
        $response = $this->get($this->url(['search' => 'Orchid buyer', 'label' => 'orchids', 'kind' => 'guest']))
            ->assertOk()->assertSee('26 purchase contact groups')->assertDontSee('contact0@example.test')
            ->assertSee('label=orchids', false)->assertSee('kind=guest', false);
        $html = new \DOMDocument;
        @$html->loadHTML($response->getContent());
        $next = (new \DOMXPath($html))->query('//a[@rel="next"]')->item(0)?->getAttribute('href');
        $this->assertNotEmpty($next, 'Pagination must be a usable GET link, not an unbound Livewire action.');
        parse_str(parse_url($next, PHP_URL_QUERY), $query);
        $this->assertSame(['search' => 'Orchid buyer', 'label' => 'orchids', 'kind' => 'guest', 'contacts_page' => '2'], $query);
        $this->get($next)
            ->assertOk()->assertSee('contact0@example.test');
    }

    public function test_lookup_migration_backfills_only_matching_profiles_without_changing_audit_or_timestamps(): void
    {
        $order = $this->order();
        $profile = $this->profile($order);
        $before = $profile->only('identity_key', 'preferred_name', 'labels', 'staff_notes', 'version', 'created_at', 'updated_at');
        $migration = require database_path('migrations/2026_08_27_000018_add_customer_directory_lookup.php');
        $migration->down();
        $migration->up();
        $this->assertEquals($before, $profile->fresh()->only(array_keys($before)));
        $this->assertSame('guest', $profile->fresh()->directory_kind);
        $this->assertSame(1, app(CustomerDirectoryService::class)->profiles('', 'all', 'orchids')->count());
        $this->assertDatabaseCount('customer_profile_changes', 1);
    }

    public function test_search_is_read_only_and_escapes_staff_display_names(): void
    {
        $profile = $this->profile($this->order(), ['preferred_name' => '<script>staff-only</script>']);
        $before = $profile->getAttributes();
        $response = $this->get($this->url(['search' => 'staff-only', 'label' => 'ORCHIDS']))->assertOk()
            ->assertSee('&lt;script&gt;staff-only&lt;/script&gt;', false)->assertDontSee('<script>staff-only</script>', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame($before, $profile->fresh()->getAttributes());
        $this->assertDatabaseCount('customer_profile_changes', 1);
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }
}
