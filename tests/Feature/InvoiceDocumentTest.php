<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Pages\ManageGeneralSettings;
use App\Http\Controllers\InvoiceController;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\InvoiceDocumentService;
use App\Services\OrderPaymentService;
use App\Settings\GeneralSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class InvoiceDocumentTest extends TestCase
{
    use RefreshDatabase;

    private function order(bool $digital = false, bool $snapshot = true): Order
    {
        $category = ProductCategory::create(['name' => 'Catalogue', 'slug' => 'catalogue-'.uniqid()]);
        $product = Product::create(['name' => 'Current catalogue name', 'slug' => 'item-'.uniqid(), 'category_id' => $category->id,
            'price' => 100, 'inventory_count' => 10, 'is_downloadable' => $digital]);
        $order = Order::create(['customer_email' => 'buyer@example.test', 'order_date' => now(), 'currency' => 'ZAR',
            'total_amount' => 225, 'shipping_cost' => 20, 'tax_amount' => 15, 'discount_amount' => 10,
            'payment_status' => 'paid', 'payment_method' => 'ikhokha', 'status' => 'processing', 'shipping_status' => 'unfulfilled',
            'shipping_address' => $digital ? null : '1 Recipient Road', 'inventory_committed_at' => now()]);
        $order->items()->create(['product_id' => $product->id, 'quantity' => 2, 'price' => 100,
            'product_name_snapshot' => $snapshot ? 'Purchased item name' : null, 'is_downloadable_snapshot' => $digital]);

        return $order;
    }

    private function seller(): void
    {
        $settings = app(GeneralSettings::class);
        $settings->invoice_seller_name = 'Original Legal Seller';
        $settings->invoice_seller_address = "10 Business Avenue\nJohannesburg";
        $settings->invoice_registration_number = 'REG-TEST';
        $settings->invoice_tax_number = 'TAX-TEST';
        $settings->site_email = 'accounts@example.test';
        $settings->save();
    }

    private function signedInvoice(Invoice $invoice): string
    {
        return URL::temporarySignedRoute('invoices.print', now()->addHour(), ['invoice' => $invoice]);
    }

    private function staff(): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->current_team_id);
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        foreach (['view_any_order', 'view_order'] as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $user->assignRole($role);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }

    public function test_issue_captures_seller_line_items_and_exact_display_totals(): void
    {
        $this->seller();
        $invoice = app(InvoiceDocumentService::class)->issue($this->order());
        $document = $invoice->fresh()->document_snapshot;
        $this->assertSame('Original Legal Seller', $document['seller']['name']);
        $this->assertSame('TAX-TEST', $document['seller']['tax_number']);
        $this->assertSame('Purchased item name', $document['items'][0]['name']);
        $this->assertSame('100.00', $document['items'][0]['unit_price']);
        $this->assertSame('200.00', $document['items'][0]['amount']);
        $this->assertSame(['subtotal' => '200.00', 'shipping' => '20.00', 'tax' => '15.00', 'discount' => '10.00', 'total' => '225.00'], $document['totals']);
        $this->get($this->signedInvoice($invoice))->assertOk()->assertSee('Original Legal Seller')->assertSee('Purchased item name')
            ->assertSee('ZAR 225.00')->assertDontSee('Legacy invoice:')->assertDontSee('Tax invoice')->assertDontSee('Billed to');
    }

    public function test_issued_document_survives_catalogue_brand_order_and_item_changes(): void
    {
        $this->seller();
        $order = $this->order();
        $invoice = app(InvoiceDocumentService::class)->issue($order);
        $before = app(InvoiceDocumentService::class)->document($invoice);
        $order->items->first()->product->update(['name' => 'Renamed product']);
        $order->items->first()->update(['product_name_snapshot' => 'Changed item', 'quantity' => 9, 'price' => 999]);
        $order->update(['customer_email' => 'different@example.test', 'total_amount' => 9999, 'currency' => 'USD', 'shipping_address' => 'Changed address']);
        $settings = app(GeneralSettings::class);
        $settings->invoice_seller_name = 'Changed seller';
        $settings->site_name = 'Changed brand';
        $settings->save();
        $this->assertSame($before, app(InvoiceDocumentService::class)->document($invoice->fresh()));
        $this->get($this->signedInvoice($invoice))->assertOk()->assertSee('Original Legal Seller')->assertSee('Purchased item name')
            ->assertSee('buyer@example.test')->assertSee('ZAR 225.00')->assertDontSee('Changed seller')->assertDontSee('Changed item')->assertDontSee('Changed address');
    }

    public function test_soft_deleted_product_does_not_remove_invoice_description(): void
    {
        $order = $this->order();
        $invoice = app(InvoiceDocumentService::class)->issue($order);
        $order->items->first()->product->delete();
        $this->get($this->signedInvoice($invoice))->assertOk()->assertSee('Purchased item name');
    }

    public function test_repeated_issue_and_controller_helper_return_original_invoice(): void
    {
        $order = $this->order();
        $service = app(InvoiceDocumentService::class);
        $invoice = $service->issue($order);
        $this->assertSame($invoice->id, $service->issue($order)->id);
        $this->assertSame($invoice->id, app(InvoiceController::class)->createInvoiceForOrder($order->id)->id);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_legacy_invoice_is_not_backfilled_or_given_todays_seller_details(): void
    {
        $this->seller();
        $order = $this->order(snapshot: false);
        $invoice = Invoice::create(['order_id' => $order->id, 'invoice_date' => now(), 'total_amount' => 225, 'payment_status' => 'paid']);
        $before = $invoice->fresh()->getRawOriginal();
        $this->get($this->signedInvoice($invoice))->assertOk()->assertSee('Legacy invoice:')->assertSee('original name not recorded')
            ->assertDontSee('Original Legal Seller')->assertDontSee('Current catalogue name');
        $this->assertSame($before, $invoice->fresh()->getRawOriginal());
        $this->assertNull(app(InvoiceDocumentService::class)->issue($order)->document_snapshot);
    }

    public function test_legacy_invoice_uses_existing_order_name_snapshot(): void
    {
        $order = $this->order();
        $invoice = Invoice::create(['order_id' => $order->id, 'invoice_date' => now(), 'total_amount' => 225, 'payment_status' => 'paid']);
        $this->get($this->signedInvoice($invoice))->assertOk()->assertSee('Purchased item name')->assertDontSee('Current catalogue name');
    }

    public function test_issued_invoice_rejects_content_mutation_and_deletion(): void
    {
        $invoice = app(InvoiceDocumentService::class)->issue($this->order());
        foreach (['invoice_number' => 'FORGED', 'total_amount' => 1, 'document_snapshot' => [], 'order_id' => 999] as $field => $value) {
            try {
                $invoice->fresh()->forceFill([$field => $value])->save();
                $this->fail('Expected immutable invoice rejection');
            } catch (\LogicException $exception) {
                $this->assertStringContainsString('cannot be rewritten', $exception->getMessage());
            }
        }
        $this->expectException(\LogicException::class);
        $invoice->delete();
    }

    public function test_payment_status_may_change_without_rewriting_document(): void
    {
        $invoice = app(InvoiceDocumentService::class)->issue($this->order());
        $before = $invoice->document_snapshot;
        $invoice->update(['payment_status' => 'refunded']);
        $this->assertSame($before, $invoice->fresh()->document_snapshot);
        $this->get($this->signedInvoice($invoice))->assertOk()->assertSee('Current payment status: Refunded');
    }

    public function test_snapshot_escapes_markup_and_digital_order_has_no_delivery_address(): void
    {
        $settings = app(GeneralSettings::class);
        $settings->invoice_seller_name = '<script>alert(1)</script>';
        $settings->save();
        $order = $this->order(digital: true);
        $order->items()->update(['product_name_snapshot' => '<script>alert(2)</script>']);
        $invoice = app(InvoiceDocumentService::class)->issue($order);
        $this->get($this->signedInvoice($invoice))->assertOk()->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<script>alert(2)</script>', false)->assertSee('&lt;script&gt;alert(2)&lt;/script&gt;', false)->assertDontSee('Delivery address');
    }

    public function test_invoice_remains_private_and_requires_signature_or_ownership(): void
    {
        $invoice = app(InvoiceDocumentService::class)->issue($this->order());
        $this->get(route('invoices.print', $invoice))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('invoices.show', $invoice))->assertForbidden();
        $url = URL::temporarySignedRoute('invoices.print', now()->subMinute(), ['invoice' => $invoice]);
        $this->get($url)->assertForbidden();
    }

    public function test_business_settings_save_preserves_homepage_content(): void
    {
        $this->staff();
        $hero = app(GeneralSettings::class)->hero_title;
        Livewire::test(ManageGeneralSettings::class)->assertSee('Business &amp; invoices', false)
            ->set('data.invoice_seller_name', 'A general retail business')->set('data.invoice_tax_number', 'ISSUED-TEST')
            ->call('save')->assertHasNoErrors();
        $this->assertSame('A general retail business', app(GeneralSettings::class)->invoice_seller_name);
        $this->assertSame($hero, app(GeneralSettings::class)->hero_title);
    }

    public function test_dashboard_counts_orders_in_the_actual_preparing_status(): void
    {
        $this->staff();
        $order = $this->order();
        $order->update(['shipping_status' => 'processing']);
        $this->assertSame(1, Livewire::test(Dashboard::class)->viewData('tasks')[0]['count']);
        $this->get('/admin')->assertOk()->assertSee('A storefront that feels like your business.')->assertDontSee('your flower shop.');
    }

    public function test_settlement_failure_to_save_snapshot_rolls_back_payment_and_invoice(): void
    {
        Notification::fake();
        $order = $this->order();
        $order->update(['payment_method' => 'free', 'total_amount' => 0, 'payment_status' => 'pending', 'payment_processed_at' => null]);
        Invoice::creating(function (): void {
            throw new \RuntimeException('Snapshot storage unavailable');
        });
        try {
            app(OrderPaymentService::class)->settleFree($order);
            $this->fail('Expected settlement rollback');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Snapshot storage unavailable', $exception->getMessage());
        }
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertDatabaseCount('invoices', 0);
        Notification::assertNothingSent();
    }

    public function test_registered_vendor_invoice_records_full_sa_details_and_original_checkout_identity(): void
    {
        $this->seller();
        $settings = app(GeneralSettings::class);
        $settings->invoice_vat_status = 'registered';
        $settings->invoice_tax_number = '4123456789';
        $settings->invoice_footer_note = 'Thank you for choosing our store.';
        $settings->save();
        $order = $this->order();
        $order->update(['billing_details' => ['name' => 'Buyer Company', 'address' => "20 Buyer Street\nPretoria, 0002, South Africa", 'is_vat_vendor' => true, 'vat_number' => '4987654321'],
            'invoice_context' => app(InvoiceDocumentService::class)->context()]);
        $settings->invoice_tax_number = '4999999999';
        $settings->site_name = 'Different brand after checkout';
        $settings->save();
        $invoice = app(InvoiceDocumentService::class)->issue($order);
        $this->get($this->signedInvoice($invoice))->assertOk()->assertSee('Tax Invoice')
            ->assertSee('Buyer Company')->assertSee('4123456789')->assertSee('4987654321')->assertSee('Value excluding VAT')
            ->assertSee('ZAR 210.00')->assertSee('ZAR 15.00')->assertSee('ZAR 225.00')
            ->assertSee('Thank you for choosing our store.')->assertDontSee('4999999999')->assertDontSee('Different brand after checkout');
    }

    public function test_missing_full_recipient_details_or_foreign_currency_prevents_tax_invoice_label(): void
    {
        $this->seller();
        $settings = app(GeneralSettings::class);
        $settings->invoice_vat_status = 'registered';
        $settings->invoice_tax_number = '4123456789';
        $settings->save();
        $order = $this->order();
        $order->update(['currency' => 'USD', 'total_amount' => 6000, 'invoice_context' => app(InvoiceDocumentService::class)->context()]);
        $invoice = app(InvoiceDocumentService::class)->issue($order);
        $this->get($this->signedInvoice($invoice))->assertOk()->assertDontSee('Tax Invoice')
            ->assertSee('recipient name and billing address were not recorded')->assertSee('rand values were not recorded');
    }

    public function test_non_vendor_invoice_does_not_claim_vat_and_flags_conflicting_tax(): void
    {
        $this->seller();
        $settings = app(GeneralSettings::class);
        $settings->invoice_vat_status = 'not_registered';
        $settings->save();
        $order = $this->order();
        $order->update(['tax_amount' => 0, 'total_amount' => 210, 'invoice_context' => app(InvoiceDocumentService::class)->context()]);
        $invoice = app(InvoiceDocumentService::class)->issue($order);
        $this->get($this->signedInvoice($invoice))->assertOk()->assertSee('No VAT has been charged')->assertDontSee('Tax Invoice')->assertDontSee('TAX-TEST');
        $order = $this->order();
        $order->update(['invoice_context' => app(InvoiceDocumentService::class)->context()]);
        $invoice = app(InvoiceDocumentService::class)->issue($order);
        $this->get($this->signedInvoice($invoice))->assertOk()->assertSee('tax amount was charged although the seller was marked not VAT registered')->assertDontSee('No VAT has been charged');
    }

    public function test_registration_cannot_be_saved_without_a_valid_format_vat_number(): void
    {
        $this->staff();
        Livewire::test(ManageGeneralSettings::class)->set('data.invoice_vat_status', 'registered')->set('data.invoice_tax_number', 'income-tax-not-vat')
            ->call('save')->assertHasErrors(['data.invoice_tax_number']);
        Livewire::test(ManageGeneralSettings::class)->set('data.invoice_vat_status', 'registered')->set('data.invoice_tax_number', '4123456789')
            ->call('save')->assertHasNoErrors();
        $this->assertSame('registered', app(GeneralSettings::class)->invoice_vat_status);
    }

    public function test_invoice_logo_snapshot_survives_logo_replacement(): void
    {
        Storage::fake('public');
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j2ioAAAAASUVORK5CYII=');
        Storage::disk('public')->put('cms/branding/logo.png', $bytes);
        $settings = app(GeneralSettings::class);
        $settings->site_logo_path = 'cms/branding/logo.png';
        $settings->save();
        $invoice = app(InvoiceDocumentService::class)->issue($this->order());
        Storage::disk('public')->delete('cms/branding/logo.png');
        $this->get($this->signedInvoice($invoice))->assertOk()->assertSee('data:image/png;base64,'.base64_encode($bytes), false);
    }
}
