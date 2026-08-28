<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Returns;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\ReturnRequestChange;
use App\Models\User;
use App\Services\ReturnManagementService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ReturnOperationsTest extends TestCase
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
        $actor = User::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $actor->assignRole(Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']));
        foreach (['view_any_order', 'view_order', ...($edit ? ['update_order'] : [])] as $name) {
            $actor->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $actor;
    }

    private function order(array $extra = []): Order
    {
        $order = Order::create(array_merge(['customer_email' => 'flower-buyer@example.test', 'order_date' => now(),
            'total_amount' => 300, 'currency' => 'ZAR', 'payment_status' => 'paid', 'inventory_committed_at' => now(),
            'status' => 'completed', 'shipping_status' => 'delivered'], $extra));
        $product = Product::factory()->create(['inventory_count' => 5]);
        $order->items()->create(['product_id' => $product->id, 'quantity' => 3, 'price' => 100,
            'product_name_snapshot' => 'Purchased pink bouquet', 'is_downloadable_snapshot' => false]);

        return $order->fresh();
    }

    private function input(Order $order, array $extra = []): array
    {
        return array_merge(['request_key' => (string) Str::uuid(), 'reason' => 'Flowers arrived damaged',
            'description' => 'Private handling note', 'return_method' => 'drop_off',
            'items' => [['order_item_id' => $order->items()->first()->id, 'quantity' => 2]]], $extra);
    }

    private function review(ReturnRequest $record, string $status, int $received = 0, array $extra = []): array
    {
        return array_merge(['version' => $record->version, 'status' => $status, 'note' => 'Reviewed with customer',
            'items' => $record->items()->get()->map(fn ($item) => ['id' => $item->id, 'received_quantity' => $received,
                'condition' => $received ? 'damaged' : null, 'disposition' => $received ? 'discard' : null])->all()], $extra);
    }

    private function invalid(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Expected rejection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_guest_intake_is_audited_idempotent_and_does_not_mutate_order_or_stock(): void
    {
        $actor = $this->staff();
        $order = $this->order();
        $before = $order->getAttributes();
        $input = $this->input($order, ['created_by' => 999, 'customer_id' => 999, 'status' => 'completed']);
        $service = app(ReturnManagementService::class);
        $return = $service->open($order, $input, $actor);
        $this->assertSame('pending', $return->status);
        $this->assertNull($return->customer_id);
        $this->assertSame($actor->id, $return->created_by);
        $this->assertSame(1, $return->version);
        $this->assertSame('Purchased pink bouquet', $return->items()->first()->product_name_snapshot);
        $this->assertSame($return->id, $service->open($order, $input, $actor)->id);
        $this->assertDatabaseCount('return_requests', 1);
        $this->assertDatabaseCount('return_request_changes', 1);
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertSame(5, (int) $order->items()->first()->product->inventory_count);
        $this->assertDatabaseCount('refunds', 0);
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_changed_idempotency_payload_is_rejected(): void
    {
        $actor = $this->staff();
        $order = $this->order();
        $data = $this->input($order);
        app(ReturnManagementService::class)->open($order, $data, $actor);
        $data['reason'] = 'Changed reason';
        $this->invalid(fn () => app(ReturnManagementService::class)->open($order, $data, $actor), 'reason');
        $this->assertDatabaseCount('return_requests', 1);
    }

    public function test_complete_receipt_workflow_keeps_audit_and_does_not_refund_or_restock(): void
    {
        $actor = $this->staff();
        $order = $this->order();
        $service = app(ReturnManagementService::class);
        $return = $service->open($order, $this->input($order), $actor);
        $return = $service->update($return, $this->review($return, 'approved'), $actor);
        $this->assertSame($actor->id, $return->approved_by);
        $return = $service->update($return, $this->review($return, 'approved', 1), $actor);
        $this->assertNull($return->received_at);
        $return = $service->update($return, $this->review($return, 'received', 2), $actor);
        $this->assertNotNull($return->received_at);
        $return = $service->update($return, $this->review($return, 'completed', 2), $actor);
        $this->assertSame(5, $return->version);
        $this->assertDatabaseCount('return_request_changes', 5);
        $this->assertSame(5, (int) $order->items()->first()->product->inventory_count);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertEquals(0, $order->fresh()->refund_total);
        $this->invalid(fn () => $service->update($return, $this->review($return, 'approved', 2), $actor), 'status');
    }

    public function test_no_return_required_can_complete_only_after_approval_without_receipt(): void
    {
        $actor = $this->staff();
        $order = $this->order();
        $service = app(ReturnManagementService::class);
        $return = $service->open($order, $this->input($order, ['return_method' => 'no_return_required']), $actor);
        $this->invalid(fn () => $service->update($return, $this->review($return, 'completed'), $actor), 'status');
        $return = $service->update($return, $this->review($return, 'approved'), $actor);
        $this->invalid(fn () => $service->update($return, $this->review($return, 'approved', 1), $actor), 'items');
        $return = $service->update($return, $this->review($return, 'completed'), $actor);
        $this->assertSame('completed', $return->status);
        $this->assertNull($return->received_at);
    }

    public function test_prior_returns_limit_quantities_and_rejected_requests_release_them(): void
    {
        $actor = $this->staff();
        $order = $this->order();
        $service = app(ReturnManagementService::class);
        $return = $service->open($order, $this->input($order), $actor);
        $this->invalid(fn () => $service->open($order, $this->input($order), $actor), 'items');
        $service->update($return, $this->review($return, 'rejected'), $actor);
        $service->open($order, $this->input($order), $actor);
        $this->assertDatabaseCount('return_requests', 2);
    }

    public function test_invalid_order_item_ownership_duplicate_and_digital_items_are_rejected(): void
    {
        $actor = $this->staff();
        $order = $this->order();
        $other = $this->order();
        $service = app(ReturnManagementService::class);
        $id = $order->items()->first()->id;
        $this->invalid(fn () => $service->open($order, $this->input($other), $actor), 'items');
        $this->invalid(fn () => $service->open($order, $this->input($order, ['items' => [
            ['order_item_id' => $id, 'quantity' => 1], ['order_item_id' => $id, 'quantity' => 1],
        ]]), $actor), 'items.0.order_item_id');
        foreach ([null, true] as $type) {
            $order->items()->first()->update(['is_downloadable_snapshot' => $type]);
            $this->invalid(fn () => $service->open($order, $this->input($order), $actor), 'items');
        }
        $this->assertDatabaseCount('return_requests', 0);
    }

    public function test_unpaid_or_undispatched_orders_are_rejected(): void
    {
        $actor = $this->staff();
        foreach ([['payment_status' => 'pending'], ['inventory_committed_at' => null], ['shipping_status' => 'processing'], ['status' => 'cancelled']] as $attributes) {
            $order = $this->order($attributes);
            $this->invalid(fn () => app(ReturnManagementService::class)->open($order, $this->input($order), $actor), 'reason');
        }
    }

    public function test_stale_updates_and_receipt_before_approval_are_rejected(): void
    {
        $actor = $this->staff();
        $order = $this->order();
        $service = app(ReturnManagementService::class);
        $return = $service->open($order, $this->input($order), $actor);
        $this->invalid(fn () => $service->update($return, $this->review($return, 'approved', 1), $actor), 'items');
        $approved = $service->update($return, $this->review($return, 'approved'), $actor);
        $this->invalid(fn () => $service->update($return, $this->review($return, 'approved'), $actor), 'status');
        $this->invalid(fn () => $service->update($approved, $this->review($approved, 'received', 1), $actor), 'status');
        $this->assertSame(0, $approved->items()->first()->received_quantity);
        $this->assertDatabaseCount('return_request_changes', 2);
    }

    public function test_received_quantities_require_condition_cannot_exceed_or_decrease(): void
    {
        $actor = $this->staff();
        $order = $this->order();
        $service = app(ReturnManagementService::class);
        $return = $service->open($order, $this->input($order), $actor);
        $return = $service->update($return, $this->review($return, 'approved'), $actor);
        $this->invalid(fn () => $service->update($return, $this->review($return, 'received', 3), $actor), 'items');
        $data = $this->review($return, 'approved', 1);
        $data['items'][0]['condition'] = null;
        $this->invalid(fn () => $service->update($return, $data, $actor), 'items');
        $return = $service->update($return, $this->review($return, 'approved', 1), $actor);
        $this->invalid(fn () => $service->update($return, $this->review($return, 'approved', 0), $actor), 'items');
    }

    public function test_audit_failure_rolls_back_intake(): void
    {
        $actor = $this->staff();
        $order = $this->order();
        ReturnRequestChange::creating(fn () => throw new \RuntimeException('Audit failure'));
        try {
            app(ReturnManagementService::class)->open($order, $this->input($order), $actor);
            $this->fail('Expected failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit failure', $exception->getMessage());
        } finally {
            ReturnRequestChange::flushEventListeners();
        }
        $this->assertDatabaseCount('return_requests', 0);
        $this->assertDatabaseCount('return_request_items', 0);
    }

    public function test_read_only_staff_cannot_create_and_customer_cannot_view(): void
    {
        $actor = $this->staff(false);
        $order = $this->order();
        Livewire::withQueryParams(['order' => $order->id])->test(Returns::class)->assertActionHidden('openReturn');
        try {
            app(ReturnManagementService::class)->open($order, $this->input($order), $actor);
            $this->fail('Expected denial');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('return_requests', 0);
        }
        $this->actingAs(User::factory()->create())->get('/admin/returns')->assertForbidden();
    }

    public function test_locked_targets_and_wrong_order_do_not_expose_return(): void
    {
        $actor = $this->staff();
        $order = $this->order();
        $return = app(ReturnManagementService::class)->open($order, $this->input($order), $actor);
        $other = $this->order();
        $this->get('/admin/returns?order='.$other->id.'&return='.$return->id)->assertNotFound();
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::withQueryParams(['order' => $order->id, 'return' => $return->id])->test(Returns::class)->set('returnId', 999);
    }

    public function test_admin_actions_intake_approval_and_visible_validation(): void
    {
        $this->staff();
        $order = $this->order();
        Livewire::withQueryParams(['order' => $order->id])->test(Returns::class)
            ->callAction('openReturn', data: $this->input($order))->assertHasNoActionErrors();
        $return = ReturnRequest::sole();
        $this->assertSame(2, $return->items()->first()->quantity);
        $page = Livewire::withQueryParams(['order' => $order->id, 'return' => $return->id])->test(Returns::class);
        $page->callAction('reviewReturn', data: ['status' => 'completed', 'note' => 'Wrong sequence'])->assertHasActionErrors(['status']);
        $page->unmountAction();
        $page->callAction('reviewReturn', data: ['status' => 'approved', 'note' => 'Approval documented'])->assertHasNoActionErrors();
        $this->assertSame('approved', $return->fresh()->status);
        $this->get('/admin/returns?order='.$order->id.'&return='.$return->id)->assertOk()->assertSee('Approval documented')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function test_legacy_returns_are_read_only_and_notes_are_escaped(): void
    {
        $actor = $this->staff();
        $order = $this->order();
        $return = app(ReturnManagementService::class)->open($order, $this->input($order, ['description' => '<script>private</script>']), $actor);
        $this->get('/admin/returns?order='.$order->id.'&return='.$return->id)->assertOk()->assertSee('&lt;script&gt;private&lt;/script&gt;', false);
        $return->update(['version' => null]);
        Livewire::withQueryParams(['order' => $order->id, 'return' => $return->id])->test(Returns::class)->assertActionHidden('reviewReturn');
        $this->invalid(fn () => app(ReturnManagementService::class)->update($return, $this->review($return, 'approved', 0, ['version' => 1]), $actor), 'status');
    }

    public function test_approved_returns_can_be_cancelled_only_before_receipt(): void
    {
        $actor = $this->staff();
        $order = $this->order();
        $service = app(ReturnManagementService::class);
        $return = $service->open($order, $this->input($order), $actor);
        $return = $service->update($return, $this->review($return, 'approved'), $actor);
        $return = $service->update($return, $this->review($return, 'cancelled'), $actor);
        $this->assertSame('cancelled', $return->status);
        $next = $service->open($order, $this->input($order), $actor);
        $next = $service->update($next, $this->review($next, 'approved'), $actor);
        $next = $service->update($next, $this->review($next, 'approved', 1), $actor);
        $this->invalid(fn () => $service->update($next, $this->review($next, 'cancelled', 1), $actor), 'status');
    }

    public function test_foreign_receipt_line_and_missing_lines_roll_back(): void
    {
        $actor = $this->staff();
        $service = app(ReturnManagementService::class);
        $order = $this->order();
        $other = $this->order();
        $return = $service->open($order, $this->input($order), $actor);
        $foreign = $service->open($other, $this->input($other), $actor);
        $this->invalid(fn () => $service->update($return, $this->review($foreign, 'approved'), $actor), 'items');
        $this->invalid(fn () => $service->update($return, $this->review($return, 'approved', 0, ['items' => []]), $actor), 'items');
        $this->assertSame(1, $return->fresh()->version);
    }

    public function test_revoked_permission_blocks_an_open_editor(): void
    {
        $actor = $this->staff();
        $order = $this->order();
        $return = app(ReturnManagementService::class)->open($order, $this->input($order), $actor);
        $page = Livewire::withQueryParams(['order' => $order->id, 'return' => $return->id])->test(Returns::class)->mountAction('reviewReturn');
        $actor->revokePermissionTo('update_order');
        $actor->unsetRelation('permissions');
        $page->setActionData(['status' => 'approved', 'note' => 'Not authorized'])->callMountedAction();
        $this->assertSame('pending', $return->fresh()->status);
    }

    public function test_account_returns_use_order_owner_and_status_filters_work(): void
    {
        $actor = $this->staff();
        $owner = User::factory()->create();
        $order = $this->order(['user_id' => $owner->id]);
        $service = app(ReturnManagementService::class);
        $return = $service->open($order, $this->input($order), $actor);
        $this->assertSame($owner->id, $return->customer_id);
        $this->get('/admin/returns?status=pending')->assertOk()->assertSee($return->rma_number);
        $this->get('/admin/returns?status=completed')->assertOk()->assertDontSee($return->rma_number);
        $this->get('/admin/returns?status=bad')->assertStatus(422);
    }

    public function test_customer_deletion_keeps_return_and_audit_history(): void
    {
        $actor = $this->staff();
        $owner = User::factory()->create();
        $order = $this->order(['user_id' => $owner->id]);
        $return = app(ReturnManagementService::class)->open($order, $this->input($order), $actor);
        $owner->delete();
        $this->assertNull($return->fresh()->customer_id);
        $this->assertDatabaseCount('return_request_changes', 1);
        $this->assertSame('Purchased pink bouquet', $return->items()->first()->product_name_snapshot);
    }
}
