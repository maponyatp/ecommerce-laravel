<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\OrderIssues\Pages\EditOrderIssue;
use App\Filament\Admin\Resources\OrderIssues\Pages\ListOrderIssues;
use App\Models\Order;
use App\Models\OrderIssue;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\OrderSupportService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OrderSupportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Http::preventStrayRequests();
    }

    private function order(?User $owner = null): Order
    {
        $order = Order::create(['user_id' => $owner?->id, 'customer_email' => $owner?->email ?? 'guest@example.test',
            'order_date' => now(), 'currency' => 'ZAR', 'total_amount' => 150,
            'payment_status' => 'paid', 'shipping_status' => 'delivered', 'status' => 'completed',
            'shipping_address' => '1 Flower Road', 'inventory_committed_at' => now()]);
        $category = ProductCategory::create(['name' => 'Flowers', 'slug' => 'flowers-'.uniqid()]);
        $product = Product::create(['name' => 'Rose bouquet', 'slug' => 'roses-'.uniqid(),
            'price' => 75, 'inventory_count' => 20, 'is_downloadable' => false, 'category_id' => $category->id]);
        $order->items()->create(['product_id' => $product->id, 'price' => 75, 'quantity' => 2]);

        return $order->fresh();
    }

    private function data(array $extra = []): array
    {
        return array_merge(['action' => 'open', 'submission_key' => (string) Str::uuid(),
            'category' => 'damaged_flowers', 'message' => 'The flowers arrived with damaged stems.'], $extra);
    }

    private function signed(Order $order, string $route = 'order-support.show', array $extra = []): string
    {
        return URL::temporarySignedRoute($route, now()->addMinutes(30), ['order' => $order->id, ...$extra]);
    }

    private function admin(): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->current_team_id);
        $user->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }

    private function open(Order $order): OrderIssue
    {
        return app(OrderSupportService::class)->open($order, $this->data(), null)->fresh();
    }

    private function invalid(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Expected validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_guest_can_open_and_reply_using_private_signed_link(): void
    {
        $order = $this->order();
        $url = $this->signed($order);
        $response = $this->get($url)->assertOk()->assertSee('New support case');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->post($url, $this->data())->assertRedirect()->assertSessionHas('success');
        $issue = OrderIssue::firstOrFail();
        $this->get($url)->assertOk()->assertSee('damaged stems')->assertSee('Reply to case');
        $this->post($url, $this->data(['action' => 'reply', 'issue_id' => $issue->id, 'message' => 'Two stems are broken.']))
            ->assertRedirect();
        $this->assertSame(2, $issue->messages()->count());
        $this->assertNull($issue->messages()->first()->author_id);
    }

    public function test_unsigned_expired_and_tampered_links_cannot_read_or_write(): void
    {
        $order = $this->order();
        $other = $this->order();
        $url = $this->signed($order);
        $this->get(route('order-support.show', $order))->assertForbidden();
        $this->post(route('order-support.store', $order), $this->data())->assertForbidden();
        $this->get(str_replace('/order-support/'.$order->id.'?', '/order-support/'.$other->id.'?', $url))->assertForbidden();
        $this->travel(31)->minutes();
        $this->get($url)->assertForbidden();
        $this->post($url, $this->data())->assertForbidden();
        $this->assertDatabaseCount('order_issues', 0);
    }

    public function test_account_owner_can_use_unsigned_page_but_other_accounts_cannot(): void
    {
        $owner = User::factory()->create();
        $order = $this->order($owner);
        $this->actingAs($owner)->get(route('order-support.show', $order))->assertOk();
        $this->post(route('order-support.store', $order), $this->data())->assertRedirect();
        $this->actingAs(User::factory()->create())->get(route('order-support.show', $order))->assertForbidden();
        $this->post(route('order-support.store', $order), $this->data())->assertForbidden();
    }

    public function test_guest_email_ownership_requires_email_verification(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'guest@example.test']);
        $order = $this->order();
        $this->actingAs($user)->get(route('order-support.show', $order))->assertForbidden();
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->get(route('order-support.show', $order))->assertOk();
    }

    public function test_order_item_and_quantity_are_scoped_to_this_order(): void
    {
        $order = $this->order();
        $foreignItem = $this->order()->items()->first();
        $url = $this->signed($order, 'order-support.store');
        $this->postJson($url, $this->data(['order_item_id' => $foreignItem->id, 'quantity' => 1]))
            ->assertUnprocessable()->assertJsonValidationErrors('order_item_id');
        $this->postJson($url, $this->data(['order_item_id' => $order->items()->first()->id, 'quantity' => 3]))
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->postJson($url, $this->data(['quantity' => 1]))
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->post($url, $this->data(['order_item_id' => $order->items()->first()->id, 'quantity' => 2]))->assertRedirect();
        $this->assertSame(2, OrderIssue::first()->quantity);
    }

    public function test_duplicate_submissions_reuse_case_and_only_one_open_case_is_allowed(): void
    {
        $order = $this->order();
        $service = app(OrderSupportService::class);
        $data = $this->data();
        $first = $service->open($order, $data, null);
        $this->assertSame($first->id, $service->open($order, $data, null)->id);
        $this->invalid(fn () => $service->open($order, $this->data(), null), 'message');
        $this->assertDatabaseCount('order_issues', 1);
        $this->assertDatabaseCount('order_issue_messages', 1);
    }

    public function test_reply_cannot_target_another_orders_case(): void
    {
        $order = $this->order();
        $foreign = $this->open($this->order());
        $this->postJson($this->signed($order, 'order-support.store'), $this->data([
            'action' => 'reply', 'issue_id' => $foreign->id, 'message' => 'Attempted cross-order reply.',
        ]))->assertNotFound();
        $this->assertSame(1, $foreign->messages()->count());
    }

    public function test_admin_public_reply_and_private_note_have_separate_visibility(): void
    {
        $order = $this->order();
        $issue = $this->open($order);
        $admin = $this->admin();
        app(OrderSupportService::class)->update($issue, ['version' => 0, 'status' => 'waiting_customer',
            'public_message' => 'Please confirm which flowers were damaged.',
            'internal_note' => 'PRIVATE INTERNAL SUPPLIER REVIEW'], $admin);
        $this->get($this->signed($order))->assertOk()->assertSee('Please confirm which flowers were damaged.')
            ->assertDontSee('PRIVATE INTERNAL SUPPLIER REVIEW');
        $this->assertSame(4, $issue->messages()->count());
        $this->assertSame($admin->id, $issue->messages()->where('is_internal', true)->first()->author_id);
    }

    public function test_customer_reply_moves_waiting_case_under_review_and_replay_is_idempotent(): void
    {
        $order = $this->order();
        $issue = $this->open($order);
        app(OrderSupportService::class)->update($issue, ['version' => 0, 'status' => 'waiting_customer',
            'public_message' => 'Which flowers were damaged?'], $this->admin());
        $data = ['submission_key' => (string) Str::uuid(), 'message' => 'The two roses.'];
        $service = app(OrderSupportService::class);
        $service->reply($order, $issue->id, $data, null);
        $count = $issue->messages()->count();
        $service->reply($order, $issue->id, $data, null);
        $this->assertSame($count, $issue->messages()->count());
        $this->assertSame('investigating', $issue->fresh()->status);
        $this->assertSame(2, $issue->fresh()->version);
    }

    public function test_closing_requires_customer_explanation_and_never_refunds_or_restocks(): void
    {
        $order = $this->order();
        $before = $order->getAttributes();
        $issue = $this->open($order);
        $admin = $this->admin();
        $service = app(OrderSupportService::class);
        $this->invalid(fn () => $service->update($issue, ['version' => 0, 'status' => 'resolved'], $admin), 'public_message');
        $service->update($issue, ['version' => 0, 'status' => 'resolved', 'public_message' => 'We have discussed your delivery enquiry.'], $admin);
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertSame(20, $order->items()->first()->product->inventory_count);
        $this->assertDatabaseCount('refunds', 0);
        $this->assertDatabaseCount('return_requests', 0);
        $this->assertDatabaseCount('inventory_logs', 0);
        $this->assertNull($issue->fresh()->active_order_id);
        $this->assertNotNull($issue->fresh()->resolved_at);
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_closed_cases_are_read_only_but_customer_can_open_a_new_case(): void
    {
        $order = $this->order();
        $data = $this->data();
        $service = app(OrderSupportService::class);
        $issue = $service->open($order, $data, null);
        $admin = $this->admin();
        $service->update($issue, ['version' => 0, 'status' => 'resolved', 'public_message' => 'Question answered by phone.'], $admin);
        $this->invalid(fn () => $service->reply($order, $issue->id, $this->data(), null), 'message');
        $this->invalid(fn () => $service->update($issue->fresh(), ['version' => 1, 'status' => 'open'], $admin), 'status');
        $this->assertSame($issue->id, $service->open($order, $data, null)->id);
        $new = $service->open($order, $this->data(), null);
        $this->assertNotSame($issue->id, $new->id);
        $this->assertSame(1, OrderIssue::whereNotNull('active_order_id')->count());
    }

    public function test_stale_admin_reply_does_not_overwrite_customer_activity(): void
    {
        $order = $this->order();
        $issue = $this->open($order);
        $admin = $this->admin();
        $service = app(OrderSupportService::class);
        $service->reply($order, $issue->id, $this->data(), null);
        $this->invalid(fn () => $service->update($issue, ['version' => 0, 'status' => 'resolved',
            'public_message' => 'Stale closure should fail.'], $admin), 'status');
        $this->assertSame('open', $issue->fresh()->status);
        $this->assertSame(0, $issue->messages()->where('body', 'Stale closure should fail.')->count());
    }

    public function test_customer_messages_are_escaped_and_admin_fields_cannot_be_mass_assigned(): void
    {
        $order = $this->order();
        $this->post($this->signed($order, 'order-support.store'), $this->data([
            'message' => '<script>alert("customer")</script>', 'status' => 'resolved',
            'is_internal' => true, 'author_id' => 999, 'active_order_id' => null,
        ]))->assertRedirect();
        $issue = OrderIssue::first();
        $this->assertSame('open', $issue->status);
        $this->assertFalse($issue->messages()->first()->is_internal);
        $this->get($this->signed($order))->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert("customer")</script>', false);
    }

    public function test_admin_resource_lists_and_saves_sequential_responses_without_repeating_message(): void
    {
        $issue = $this->open($this->order());
        $this->admin();
        Livewire::test(ListOrderIssues::class)->assertSuccessful()->assertCanSeeTableRecords([$issue]);
        Livewire::test(EditOrderIssue::class, ['record' => $issue->id])
            ->fillForm(['status' => 'investigating', 'public_message' => 'We are investigating.', 'internal_note' => 'Private warehouse note.'])
            ->call('save')->assertHasNoFormErrors()
            ->fillForm(['status' => 'waiting_customer', 'public_message' => 'Please confirm delivery time.'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame(2, $issue->fresh()->version);
        $this->assertSame(1, $issue->messages()->where('body', 'Private warehouse note.')->count());
        $this->assertSame('waiting_customer', $issue->fresh()->status);
    }

    public function test_admin_closure_validation_is_visible_next_to_the_response_field(): void
    {
        $issue = $this->open($this->order());
        $this->admin();
        Livewire::test(EditOrderIssue::class, ['record' => $issue->id])
            ->fillForm(['status' => 'resolved'])->call('save')->assertHasFormErrors(['public_message']);
    }

    public function test_admin_only_policy_disallows_customer_access_and_case_deletion(): void
    {
        $issue = $this->open($this->order());
        $customer = User::factory()->create();
        $this->assertFalse(Gate::forUser($customer)->allows('viewAny', OrderIssue::class));
        $this->assertFalse(Gate::forUser($customer)->allows('update', $issue));
        $this->assertFalse(Gate::forUser($this->admin())->allows('delete', $issue));
    }

    public function test_guest_history_pagination_links_have_valid_signatures(): void
    {
        $order = $this->order();
        for ($i = 0; $i < 11; $i++) {
            $order->issues()->create(['submission_key' => (string) Str::uuid(), 'category' => 'other', 'status' => 'resolved']);
        }
        $response = $this->get($this->signed($order))->assertOk()->assertSee('Older cases');
        preg_match('/href="([^"]*order-support[^"]*page=2[^"]*)"/', $response->getContent(), $match);
        $this->assertNotEmpty($match);
        $this->get(html_entity_decode($match[1]))->assertOk()->assertSee('Newer cases');
    }

    public function test_missing_csrf_token_cannot_submit_a_case(): void
    {
        $order = $this->order();
        $url = $this->signed($order, 'order-support.store');
        app()->instance('env', 'production');
        $this->post($url, $this->data())->assertStatus(419);
        $this->assertDatabaseCount('order_issues', 0);
    }

    public function test_support_post_rate_limit_is_enforced(): void
    {
        $order = $this->order();
        $url = $this->signed($order, 'order-support.store');
        $data = $this->data();
        for ($i = 0; $i < 6; $i++) {
            $this->post($url, $data)->assertRedirect();
        }
        $this->post($url, $data)->assertStatus(429);
        $this->assertDatabaseCount('order_issues', 1);
    }

    public function test_post_route_has_throttle_session_lock_and_web_csrf_middleware(): void
    {
        $route = app('router')->getRoutes()->getByName('order-support.store');
        $this->assertContains('throttle:6,1', $route->gatherMiddleware());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertSame(30, $route->locksFor());
    }
}
