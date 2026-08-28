<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Refunds;
use App\Models\CreditNote;
use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use App\Models\RefundChange;
use App\Models\User;
use App\Services\InvoiceDocumentService;
use App\Services\OrderFulfillmentService;
use App\Services\OrderPaymentService;
use App\Services\RefundManagementService;
use App\Support\OrderWorkspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RefundWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        Http::preventStrayRequests();
        $this->actor = $this->staff();
    }

    private function staff(bool $refund = true, bool $super = false): User
    {
        $user = User::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $user->assignRole(Role::findOrCreate($super ? 'super_admin' : 'admin', 'web'));
        foreach (['view_any_order', 'view_order', 'update_order', ...($refund ? ['refund_order'] : [])] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }

    private function order(array $extra = []): Order
    {
        $context = ['vat_status' => 'registered', 'branding' => ['name' => 'Original Flowers', 'colour' => '#245844'],
            'seller' => ['name' => 'Original Flowers Ltd', 'address' => '10 Original Street, Pretoria', 'tax_number' => '4123456789',
                'registration_number' => 'REG-ORIGINAL', 'email' => 'billing@example.test'], 'footer_note' => 'Thank you'];
        $order = Order::create($extra + ['customer_email' => 'buyer@example.test', 'order_date' => now(),
            'currency' => 'ZAR', 'total_amount' => '230.00', 'shipping_cost' => 0, 'tax_amount' => '30.00', 'discount_amount' => 0,
            'payment_status' => 'paid', 'payment_method' => 'ikhokha', 'payment_processed_at' => now()->subDay(),
            'inventory_committed_at' => now()->subDay(), 'status' => 'processing', 'shipping_status' => 'unfulfilled',
            'billing_details' => ['name' => 'Original Buyer', 'address' => '20 Buyer Street', 'is_vat_vendor' => false],
            'invoice_context' => $context]);
        $product = Product::factory()->create(['inventory_count' => 8]);
        $order->items()->create(['product_id' => $product->id, 'quantity' => 2, 'price' => 100,
            'product_name_snapshot' => 'Rose bouquet', 'sku_snapshot' => 'ROSE-M', 'is_downloadable_snapshot' => false]);
        $order->paymentTransactions()->create(['gateway' => 'ikhokha', 'external_transaction_id' => 'PAY-'.Str::uuid(),
            'gateway_reference' => 'LINK-'.Str::uuid(), 'amount' => $order->total_amount, 'currency' => $order->currency, 'status' => 'paid',
            'paid_at' => now()->subDay()]);
        app(InvoiceDocumentService::class)->issue($order);

        return $order->fresh();
    }

    private function input(array $extra = []): array
    {
        return $extra + ['request_key' => (string) Str::uuid(), 'amount' => '115.00', 'tax_amount' => '15.00', 'reason' => 'One rose bouquet arrived damaged'];
    }

    private function request(Order $order, array $extra = []): Refund
    {
        return app(RefundManagementService::class)->request($order, $this->input($extra), $this->actor);
    }

    private function evidence(Refund $refund, array $extra = []): array
    {
        return $extra + ['version' => $refund->version, 'external_reference' => 'REF-'.Str::uuid(),
            'completed_at' => now()->subMinute()->format('Y-m-d H:i:s'), 'evidence_note' => 'Checked completed provider refund against original payment.',
            'confirmed' => true];
    }

    private function record(Refund $refund, array $extra = []): Refund
    {
        return app(RefundManagementService::class)->recordExternal($refund, $this->evidence($refund, $extra), $this->actor);
    }

    private function invalid(callable $action, string $field = 'amount'): void
    {
        try {
            $action();
            $this->fail('Expected validation failure.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_request_reserves_balance_and_pauses_fulfilment_without_moving_money(): void
    {
        $order = $this->order();
        $invoice = $order->invoice->document_snapshot;
        $refund = $this->request($order, ['status' => 'completed', 'processed_by' => 999, 'restock_items' => true]);
        $this->assertSame('pending', $refund->status);
        $this->assertFalse($refund->restock_items);
        $this->assertNull($refund->processed_by);
        $this->assertSame($this->actor->id, $refund->requested_by);
        $this->assertSame('0.00', $order->fresh()->refund_total);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame($invoice, $order->invoice->fresh()->document_snapshot);
        $this->assertSame(8, $order->items->first()->product->inventory_count);
        $this->assertDatabaseCount('credit_notes', 0);
        $this->assertDatabaseCount('refund_changes', 1);
        $this->assertFalse(app(OrderFulfillmentService::class)->canFulfil($order->fresh()));
        $this->assertStringContainsString('pending refund', OrderWorkspace::nextStep($order->fresh()));
        $balance = app(RefundManagementService::class)->balances($order->fresh());
        $this->assertSame(11500, $balance['remaining']);
        $this->assertSame(1500, $balance['remaining_tax']);
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_request_is_idempotent_but_changed_payload_or_cross_order_key_is_rejected(): void
    {
        $order = $this->order();
        $input = $this->input();
        $service = app(RefundManagementService::class);
        $refund = $service->request($order, $input, $this->actor);
        $this->assertSame($refund->id, $service->request($order, $input, $this->actor)->id);
        $this->invalid(fn () => $service->request($order, array_replace($input, ['amount' => '100.00']), $this->actor));
        $this->invalid(fn () => $service->request($this->order(), $input, $this->actor));
        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_pending_refunds_cannot_overallocate_gross_tax_or_net_and_integer_cents_are_exact(): void
    {
        $order = $this->order();
        $this->request($order);
        foreach ([['amount' => '115.01'], ['amount' => '110.00', 'tax_amount' => '15.01'], ['amount' => '101.00', 'tax_amount' => '0.00'],
            ['amount' => '1.001'], ['amount' => '0'], ['amount' => '-1'], ['amount' => '1e2']] as $extra) {
            $this->invalid(fn () => $this->request($order, $extra));
        }
        $this->assertSame(29, RefundManagementService::minor('0.29'));
        $this->assertSame('0.29', RefundManagementService::decimal(29));
        $this->request($order, ['amount' => '115', 'tax_amount' => '15']);
        $this->assertSame(0, app(RefundManagementService::class)->balances($order->fresh())['remaining']);
    }

    public function test_partial_external_record_issues_frozen_credit_and_never_calls_gateway_or_restock(): void
    {
        $order = $this->order();
        $original = $order->invoice->document_snapshot;
        $refund = $this->record($this->request($order));
        $this->assertSame('completed', $refund->status);
        $this->assertSame('staff_recorded_external', $refund->confirmation_basis);
        $this->assertSame('115.00', $order->fresh()->refund_total);
        $this->assertTrue($order->fresh()->partially_refunded);
        $this->assertFalse($order->fresh()->fully_refunded);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame('paid', $refund->paymentTransaction->status);
        $this->assertSame($original, $order->invoice->fresh()->document_snapshot);
        $credit = $refund->creditNote->document_snapshot;
        $this->assertSame(['net' => '100.00', 'tax' => '15.00', 'total' => '115.00'], $credit['totals']);
        $this->assertSame($original['seller'], $credit['seller']);
        $this->assertSame($original['buyer'], $credit['buyer']);
        $this->assertSame(8, $order->items->first()->product->inventory_count);
        $this->assertTrue(app(OrderFulfillmentService::class)->canFulfil($order->fresh()));
        $this->assertDatabaseCount('credit_notes', 1);
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_full_refund_closes_fulfilment_without_rewriting_invoice_or_replaying_settlement(): void
    {
        $order = $this->order();
        $this->record($this->request($order));
        $refund = $this->record($this->request($order));
        $this->assertSame('230.00', $order->fresh()->refund_total);
        $this->assertTrue($order->fresh()->fully_refunded);
        $this->assertFalse($order->fresh()->partially_refunded);
        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertSame('refunded', $order->invoice->fresh()->payment_status);
        $this->assertSame('230.00', $order->invoice->document_snapshot['totals']['total']);
        app(OrderPaymentService::class)->markPaid($refund->paymentTransaction);
        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertFalse(app(OrderFulfillmentService::class)->canFulfil($order->fresh()));
        $this->invalid(fn () => $this->request($order));
    }

    public function test_duplicate_confirmation_is_idempotent_and_reference_cannot_be_reused(): void
    {
        $order = $this->order();
        $refund = $this->request($order);
        $evidence = $this->evidence($refund);
        $service = app(RefundManagementService::class);
        $recorded = $service->recordExternal($refund, $evidence, $this->actor);
        $this->assertSame($recorded->id, $service->recordExternal($refund, $evidence, $this->actor)->id);
        $other = $this->request($this->order());
        $this->invalid(fn () => $this->record($other, ['external_reference' => strtolower($evidence['external_reference'])]), 'external_reference');
        $this->assertDatabaseCount('credit_notes', 1);
        $this->assertDatabaseCount('refund_changes', 3);
    }

    public function test_evidence_requires_attestation_nonfuture_time_and_refund_not_payment_reference(): void
    {
        $refund = $this->request($this->order());
        $this->invalid(fn () => $this->record($refund, ['confirmed' => false]), 'confirmed');
        $this->invalid(fn () => $this->record($refund, ['completed_at' => now()->addHour()->toIso8601String()]), 'completed_at');
        $this->invalid(fn () => $this->record($refund, ['completed_at' => now()->subDays(2)->toIso8601String()]));
        $this->invalid(fn () => $this->record($refund, ['evidence_note' => 'short']), 'evidence_note');
        $this->invalid(fn () => $this->record($refund, ['external_reference' => $refund->paymentTransaction->gateway_reference]), 'external_reference');
        $this->assertSame('pending', $refund->fresh()->status);
        $this->assertDatabaseCount('credit_notes', 0);
    }

    public function test_cancel_releases_reserved_amount_and_stale_or_cancelled_actions_are_rejected(): void
    {
        $order = $this->order();
        $refund = $this->request($order);
        $service = app(RefundManagementService::class);
        $this->invalid(fn () => $service->cancel($refund, 99, 'No refund occurred', $this->actor), 'version');
        $service->cancel($refund, $refund->version, 'No refund occurred; customer retained flowers', $this->actor);
        $this->assertTrue(app(OrderFulfillmentService::class)->canFulfil($order->fresh()));
        $this->assertSame(23000, $service->balances($order->fresh())['remaining']);
        $this->invalid(fn () => $this->record($refund), 'version');
        $this->assertSame('0.00', $order->fresh()->refund_total);
    }

    public function test_audit_failure_rolls_back_request_and_fulfilment_version(): void
    {
        $order = $this->order();
        RefundChange::creating(fn () => throw new \RuntimeException('Audit unavailable'));
        try {
            $this->request($order);
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        }
        $this->assertDatabaseCount('refunds', 0);
        $this->assertSame(0, $order->fresh()->fulfillment_version);
    }

    public function test_credit_failure_rolls_back_refund_order_invoice_and_audit(): void
    {
        $order = $this->order();
        $refund = $this->request($order, ['amount' => '230', 'tax_amount' => '30']);
        CreditNote::creating(fn () => throw new \RuntimeException('Credit unavailable'));
        try {
            $this->record($refund);
            $this->fail('Expected credit failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Credit unavailable', $e->getMessage());
        }
        $this->assertSame('pending', $refund->fresh()->status);
        $this->assertSame('0.00', $order->fresh()->refund_total);
        $this->assertSame('paid', $order->invoice->fresh()->payment_status);
        $this->assertDatabaseCount('credit_notes', 0);
        $this->assertDatabaseCount('refund_changes', 1);
    }

    public function test_legacy_missing_or_ambiguous_payment_and_incomplete_invoice_are_blocked(): void
    {
        $order = $this->order(['payment_status' => 'pending']);
        $this->invalid(fn () => $this->request($order));
        $digital = $this->order();
        $digital->items()->update(['is_downloadable_snapshot' => true]);
        $this->invalid(fn () => $this->request($digital));
        $legacy = $this->order();
        DB::table('invoices')->where('id', $legacy->invoice->id)->update(['document_snapshot' => null]);
        $this->invalid(fn () => $this->request($legacy));
        $unconfirmed = $this->order(['invoice_context' => ['vat_status' => 'unconfirmed', 'seller' => ['name' => 'Seller', 'address' => 'Address']]]);
        $this->invalid(fn () => $this->request($unconfirmed));
        $multiple = $this->order();
        $payment = $multiple->paymentTransactions->first()->replicate();
        $payment->external_transaction_id = 'OTHER-'.Str::uuid();
        $payment->save();
        $this->invalid(fn () => $this->request($multiple));
        $prior = $this->order();
        Refund::create(['order_id' => $prior->id, 'amount' => 10, 'reason' => 'Legacy refund']);
        $this->invalid(fn () => $this->request($prior));
        $drift = $this->order();
        $drift->forceFill(['refund_total' => '1.00'])->save();
        $this->invalid(fn () => $this->request($drift));
    }

    public function test_refund_permission_is_separate_from_order_edit_and_customer_permissions_do_not_bypass_role(): void
    {
        $order = $this->order();
        $editor = $this->staff(false);
        Livewire::withQueryParams(['order' => $order->id])->test(Refunds::class)->assertActionHidden('requestRefund');
        try {
            app(RefundManagementService::class)->request($order, $this->input(), $editor);
            $this->fail('Expected denied');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $customer = User::factory()->create();
        foreach (['view_any_order', 'view_order', 'update_order', 'refund_order'] as $permission) {
            $customer->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($customer)->get('/admin/refunds')->assertForbidden();
        $this->get(route('operations.refunds.export'))->assertForbidden();
        $super = $this->staff(false, true);
        $this->assertNotNull(app(RefundManagementService::class)->request($order, $this->input(), $super)->id);
    }

    public function test_credit_access_is_private_staff_owner_or_signed_and_evidence_is_not_exposed(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $order = $this->order(['user_id' => $owner->id]);
        $refund = $this->record($this->request($order), ['evidence_note' => 'SECRET INTERNAL EVIDENCE NOTE']);
        $credit = $refund->creditNote;
        $url = route('credit-notes.show', $credit);
        $this->get($url)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertSee('Original Flowers Ltd')->assertDontSee('SECRET INTERNAL EVIDENCE NOTE')->assertDontSee($refund->transaction_id);
        $this->actingAs($owner)->get($url)->assertOk();
        $this->actingAs($other)->get($url)->assertForbidden();
        auth()->logout();
        $this->get($url)->assertForbidden();
        $signed = URL::temporarySignedRoute('credit-notes.show', now()->addHour(), ['creditNote' => $credit]);
        $this->get($signed)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->get($signed.'x')->assertForbidden();
        $this->get(URL::temporarySignedRoute('credit-notes.show', now()->subMinute(), ['creditNote' => $credit]))->assertForbidden();
    }

    public function test_credit_and_refund_history_are_immutable_and_invoice_links_to_credit(): void
    {
        $order = $this->order();
        $refund = $this->record($this->request($order));
        $credit = $refund->creditNote;
        foreach ([$refund, $credit, $refund->changes()->first()] as $model) {
            try {
                $model->delete();
                $this->fail('Expected retained record');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('cannot be', $e->getMessage());
            }
        }
        try {
            $refund->update(['amount' => 1]);
            $this->fail('Expected immutable refund');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('cannot be', $e->getMessage());
        }
        try {
            $credit->update(['number' => 'FORGED']);
            $this->fail('Expected immutable credit');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('cannot be', $e->getMessage());
        }
        $order->update(['billing_details' => ['name' => 'Changed Buyer']]);
        $this->assertSame('Original Buyer', $credit->fresh()->document_snapshot['buyer']['name']);
        $this->get(URL::temporarySignedRoute('invoices.print', now()->addHour(), ['invoice' => $order->invoice]))->assertOk()->assertSee($credit->fresh()->number);
    }

    public function test_filament_request_and_record_actions_work_with_validation_and_locked_references(): void
    {
        $order = $this->order();
        Livewire::withQueryParams(['order' => $order->id])->test(Refunds::class)
            ->callAction('requestRefund', data: $this->input())->assertHasNoActionErrors();
        $refund = Refund::firstOrFail();
        Livewire::withQueryParams(['order' => $order->id, 'refund' => $refund->id])->test(Refunds::class)
            ->callAction('recordExternal', data: $this->evidence($refund))->assertHasNoActionErrors();
        $this->assertDatabaseCount('credit_notes', 1);
        $this->get('/admin/refunds?order='.$order->id.'&refund='.$refund->id)->assertOk()->assertSee('External refund recorded');
        $other = $this->order();
        $this->get('/admin/refunds?order='.$other->id.'&refund='.$refund->id)->assertNotFound();
    }

    public function test_export_contains_only_completed_records_and_no_private_evidence_or_customer_email(): void
    {
        $order = $this->order();
        $refund = $this->record($this->request($order), ['evidence_note' => 'PRIVATE RECONCILIATION DETAIL']);
        $this->request($order);
        $response = $this->get(route('operations.refunds.export', ['order' => $order->id]))->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString($refund->creditNote->number, $csv);
        $this->assertStringContainsString('115.00', $csv);
        $this->assertStringNotContainsString('PRIVATE RECONCILIATION DETAIL', $csv);
        $this->assertStringNotContainsString('buyer@example.test', $csv);
        $this->assertCount(3, explode("\n", $csv));
    }
}
