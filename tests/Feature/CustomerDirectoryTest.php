<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\CustomerDirectory;
use App\Filament\Admin\Resources\Orders\Pages\EditOrder;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderIssue;
use App\Models\Team;
use App\Models\User;
use App\Services\CustomerDirectoryService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CustomerDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Http::preventStrayRequests();
    }

    private function staff(array $permissions = ['view_any_order', 'view_order', 'update_order'], bool $role = true): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->current_team_id);
        if ($role) {
            $user->assignRole(Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']));
        }
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }

    private function order(array $data = []): Order
    {
        return Order::create(array_merge(['order_date' => now(), 'customer_email' => 'buyer@example.test',
            'total_amount' => 100, 'currency' => 'ZAR', 'payment_status' => 'paid',
            'status' => 'processing', 'shipping_status' => 'unfulfilled'], $data))->fresh();
    }

    private function legacy(): Customer
    {
        return Customer::create(['first_name' => 'Legacy', 'last_name' => 'Buyer', 'email' => 'buyer@example.test',
            'phone_number' => 123, 'address' => 'Address', 'city' => 'City', 'state' => 'State', 'postal_code' => '0001']);
    }

    private function caseFor(Order $order, string $category = 'other'): OrderIssue
    {
        return OrderIssue::create(['order_id' => $order->id, 'submission_key' => (string) Str::uuid(),
            'category' => $category, 'status' => 'resolved', 'version' => 0]);
    }

    private function url(array $query = []): string
    {
        return route('filament.admin.pages.customer-directory', $query);
    }

    public function test_guest_and_non_staff_cannot_access_directory_or_profile(): void
    {
        $order = $this->order();
        $this->get($this->url())->assertRedirect();
        $this->staff(role: false);
        $this->get($this->url())->assertForbidden();
        $this->get($this->url(['profile' => $order->id]))->assertForbidden();
        $this->assertFalse(CustomerDirectory::canAccess());
    }

    public function test_admin_requires_both_order_list_and_order_view_permissions(): void
    {
        $this->staff(['view_any_order']);
        $this->get($this->url())->assertForbidden();
        $this->assertFalse(CustomerDirectory::canAccess());
    }

    public function test_account_legacy_and_guest_contacts_with_same_email_remain_distinct(): void
    {
        $this->staff();
        $account = User::factory()->create();
        $this->order(['user_id' => $account->id]);
        $this->order(['customer_id' => $this->legacy()->id]);
        $this->order();
        $this->get($this->url())->assertOk()->assertSee('3 purchase contact groups')
            ->assertSee('Registered accounts')->assertSee('Legacy customer records')->assertSee('Guest receipt contacts');
        $this->assertSame(3, app(CustomerDirectoryService::class)->profiles()->count());
    }

    public function test_guest_case_and_whitespace_variants_are_not_silently_merged(): void
    {
        $first = $this->order();
        $this->order();
        $this->order(['customer_email' => 'Buyer@example.test']);
        $this->order(['customer_email' => 'buyer@example.test ']);
        $directory = app(CustomerDirectoryService::class);
        $this->assertSame(3, $directory->profiles()->count());
        $this->assertSame(2, $directory->ordersFor($first)->count());
    }

    public function test_unknown_contacts_do_not_merge_and_empty_customer_records_are_not_included(): void
    {
        $this->legacy();
        $empty = $this->order(['customer_email' => null]);
        $this->order(['customer_email' => null]);
        $this->order(['customer_email' => '']);
        $this->order(['customer_email' => '   ']);
        $directory = app(CustomerDirectoryService::class);
        $this->assertSame(4, $directory->profiles()->count());
        $this->assertSame(1, $directory->ordersFor($empty)->count());
        $this->assertSame('unknown', $directory->kind($empty));
    }

    public function test_account_link_survives_email_change_without_claiming_guests(): void
    {
        $account = User::factory()->create(['email' => 'current@example.test']);
        $anchor = $this->order(['user_id' => $account->id, 'customer_email' => 'old@example.test']);
        $this->order(['user_id' => $account->id, 'customer_email' => 'current@example.test']);
        $guest = $this->order(['customer_email' => 'current@example.test']);
        $directory = app(CustomerDirectoryService::class);
        $this->assertSame(2, $directory->ordersFor($anchor)->count());
        $this->assertSame(1, $directory->ordersFor($guest)->count());
        $this->assertSame(2, $directory->profiles('old@example.test')->first()->order_count);
        $this->assertNull($guest->fresh()->user_id);
    }

    public function test_duplicate_legacy_records_and_order_teams_remain_separate(): void
    {
        $this->staff();
        $first = $this->order(['customer_id' => $this->legacy()->id]);
        $this->order(['customer_id' => $this->legacy()->id]);
        $otherTeam = Team::forceCreate(['user_id' => auth()->id(), 'name' => 'Other order team', 'personal_team' => false])->id;
        $other = $this->order(['customer_id' => $first->customer_id]);
        DB::table('orders')->where('id', $other->id)->update(['team_id' => $otherTeam]);
        $directory = app(CustomerDirectoryService::class);
        $this->assertSame(3, $directory->profiles()->count());
        $this->assertSame(1, $directory->ordersFor($first)->count());
    }

    public function test_search_treats_wildcards_as_literal_and_group_filter_keeps_full_counts(): void
    {
        $this->order(['customer_email' => 'a_%!@example.test']);
        $this->order(['customer_email' => 'another@example.test']);
        $directory = app(CustomerDirectoryService::class);
        $this->assertSame(1, $directory->profiles('_%!')->count());
        $this->assertSame(0, $directory->profiles("' OR 1=1 --")->count());
        $this->assertSame(0, $directory->profiles('', 'account')->count());
    }

    public function test_profile_totals_separate_currency_and_only_count_paid_orders(): void
    {
        $this->staff();
        $anchor = $this->order();
        $this->order(['total_amount' => 50]);
        $this->order(['currency' => 'USD', 'total_amount' => 20]);
        $this->order(['currency' => null, 'total_amount' => 7]);
        $this->order(['payment_status' => 'pending', 'total_amount' => 900]);
        $this->order(['payment_status' => 'refunded', 'total_amount' => 800]);
        $this->order(['customer_email' => 'other@example.test', 'total_amount' => 600]);
        $totals = app(CustomerDirectoryService::class)->paidTotals($anchor);
        $this->assertCount(3, $totals);
        $this->assertEquals(150, $totals->firstWhere('currency', 'ZAR')->paid_total);
        $this->get($this->url(['profile' => $anchor->id]))->assertOk()->assertSee('ZAR 150.00')->assertSee('USD 20.00')
            ->assertSee('7.00 (currency not recorded)')->assertSee('6 orders in this purchase history')->assertDontSee('other@example.test');
    }

    public function test_profile_shows_only_related_cases_without_loading_messages_or_recipient_details(): void
    {
        $this->staff();
        $anchor = $this->order(['shipping_address' => 'SECRET RECIPIENT ADDRESS', 'gift_message' => 'SECRET GIFT']);
        $case = $this->caseFor($anchor, 'damaged_flowers');
        $case->messages()->create(['body' => 'SECRET SUPPORT NOTE', 'is_internal' => true, 'author_type' => 'staff']);
        $other = $this->caseFor($this->order(['customer_email' => 'other@example.test']), 'payment_question');
        $this->get($this->url(['profile' => $anchor->id]))->assertOk()->assertSee('Review case #'.$case->id)
            ->assertDontSee('Review case #'.$other->id)->assertDontSee('SECRET SUPPORT NOTE')->assertDontSee('SECRET RECIPIENT ADDRESS')
            ->assertDontSee('SECRET GIFT');
    }

    public function test_read_only_staff_do_not_get_order_edit_links(): void
    {
        $this->staff(['view_any_order', 'view_order']);
        $anchor = $this->order();
        $this->get($this->url(['profile' => $anchor->id]))->assertOk()->assertSee('View-only access')
            ->assertDontSee('Manage order #'.$anchor->id);
    }

    public function test_bad_filters_and_missing_profiles_are_rejected(): void
    {
        $this->staff();
        foreach ([['search' => str_repeat('a', 161)], ['search' => ['injected']], ['kind' => 'fake'],
            ['profile' => 0], ['profile' => 'abc'], ['orders_page' => -1], ['contacts_page' => 1000001], ['cases_page' => 'bad']] as $query) {
            $this->get($this->url($query))->assertUnprocessable();
        }
        $this->get($this->url(['profile' => 999999]))->assertNotFound();
    }

    public function test_directory_and_profile_pagination_keep_all_records_accessible(): void
    {
        $this->staff();
        for ($i = 0; $i < 26; $i++) {
            $this->order(['customer_email' => 'contact'.$i.'@example.test']);
        }
        $this->get($this->url())->assertOk()->assertSee('26 purchase contact groups')->assertDontSee('contact0@example.test');
        $this->get($this->url(['contacts_page' => 2]))->assertOk()->assertSee('contact0@example.test');
        $anchor = $this->order(['customer_email' => 'repeat@example.test']);
        for ($i = 0; $i < 15; $i++) {
            $this->order(['customer_email' => 'repeat@example.test']);
        }
        for ($i = 0; $i < 11; $i++) {
            $this->caseFor($anchor);
        }
        $this->get($this->url(['profile' => $anchor->id]))->assertOk()->assertSee('16 orders in this purchase history')
            ->assertDontSee('Manage order #'.$anchor->id.'<', false)->assertDontSee('Review case #1<', false);
        $this->get($this->url(['profile' => $anchor->id, 'orders_page' => 2, 'cases_page' => 2]))
            ->assertOk()->assertSee('Manage order #'.$anchor->id)->assertSee('Review case #1');
    }

    public function test_reads_are_private_and_never_merge_or_send_notifications(): void
    {
        $this->staff();
        $anchor = $this->order();
        $before = $anchor->getAttributes();
        $response = $this->get($this->url(['profile' => $anchor->id]))->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->assertSame($before, $anchor->fresh()->getAttributes());
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('order_notes', 0);
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_livewire_directory_and_order_history_action_render(): void
    {
        $this->staff();
        $anchor = $this->order();
        Livewire::test(CustomerDirectory::class)->assertSuccessful()->assertSee('Search customers');
        Livewire::test(EditOrder::class, ['record' => $anchor->id])->assertSuccessful()->assertActionVisible('customerHistory');
    }

    public function test_revoked_permissions_block_subsequent_livewire_renders(): void
    {
        $staff = $this->staff();
        $component = Livewire::test(CustomerDirectory::class)->assertSuccessful();
        $staff->revokePermissionTo('view_order');
        $staff->unsetRelation('permissions');
        $component->call('refresh')->assertForbidden();
    }
}
