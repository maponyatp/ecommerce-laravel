<?php

namespace Tests\Feature;

use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\DownloadableProduct;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\DigitalFulfillmentService;
use App\Services\OrderPaymentService;
use App\Settings\GeneralSettings;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class DigitalFulfillmentRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
    }

    private function product(array $overrides = []): Product
    {
        $category = ProductCategory::create(['name' => 'Digital', 'slug' => 'digital-'.uniqid()]);
        Storage::disk('local')->put('downloadable_products/guide.pdf', 'private test document');

        return Product::create(array_merge([
            'category_id' => $category->id, 'name' => 'Digital guide', 'slug' => 'guide-'.uniqid(),
            'price' => 50, 'inventory_count' => 100, 'is_downloadable' => true,
            'downloadable_file' => 'downloadable_products/guide.pdf', 'download_limit' => 2,
        ], $overrides));
    }

    private function orderItem(?Product $product = null, array $orderAttributes = []): OrderItem
    {
        $product ??= $this->product();
        $order = Order::create(array_merge([
            'customer_email' => 'buyer@example.test', 'order_date' => now(), 'total_amount' => 50,
            'payment_method' => 'ikhokha', 'payment_status' => 'paid', 'status' => 'processing',
            'shipping_status' => 'not_required', 'payment_processed_at' => now(),
        ], $orderAttributes));
        $item = $order->items()->create(['product_id' => $product->id, 'quantity' => 1, 'price' => 50]);
        app(DigitalFulfillmentService::class)->issue($order);

        return $item->fresh();
    }

    private function signed(OrderItem $item, ?string $token = null): string
    {
        return URL::temporarySignedRoute('downloads.file', now()->addMinutes(5), [
            'item' => $item->id, 'token' => $token ?? $item->download_link,
        ]);
    }

    public function test_async_payment_issues_downloads_once_and_does_not_reset_limits(): void
    {
        $item = $this->orderItem(orderAttributes: ['payment_status' => 'pending']);
        $this->assertNull($item->download_path);
        $transaction = PaymentTransaction::create([
            'order_id' => $item->order_id, 'gateway' => 'ikhokha', 'external_transaction_id' => 'digital-payment',
            'amount' => 50, 'currency' => 'ZAR', 'status' => 'pending',
        ]);
        app(OrderPaymentService::class)->markPaid($transaction);
        $item->refresh();
        $token = $item->download_link;
        $this->assertNotNull($token);
        $this->get($this->signed($item))->assertOk()->assertDownload('guide.pdf');
        app(OrderPaymentService::class)->markPaid($transaction);
        $this->assertSame($token, $item->fresh()->download_link);
        $this->assertSame(1, $item->fresh()->download_count);
        $this->assertSame(99, $item->product->fresh()->inventory_count);
        $this->assertSame(1, Invoice::count());
        Notification::assertCount(1);
    }

    public function test_guest_signed_confirmation_has_working_download_without_login(): void
    {
        $item = $this->orderItem();
        $this->get(URL::temporarySignedRoute('checkout.confirmation', now()->addHour(), ['order' => $item->order_id]))
            ->assertOk()->assertSee('Your digital downloads')->assertSee('Download file');
        $response = $this->get($this->signed($item))->assertOk()->assertDownload('guide.pdf');
        $this->assertSame('private test document', $response->streamedContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(1, $item->fresh()->download_count);
    }

    public function test_replayed_success_cannot_reactivate_a_refunded_download(): void
    {
        $item = $this->orderItem(orderAttributes: ['payment_status' => 'pending']);
        $transaction = PaymentTransaction::create([
            'order_id' => $item->order_id, 'gateway' => 'ikhokha', 'external_transaction_id' => 'refund-replay',
            'amount' => 50, 'currency' => 'ZAR', 'status' => 'pending',
        ]);
        app(OrderPaymentService::class)->markPaid($transaction);
        $item->refresh();
        $item->order->update(['payment_status' => 'refunded', 'status' => 'refunded']);
        app(OrderPaymentService::class)->markPaid($transaction);
        $this->assertSame('refunded', $item->order->fresh()->payment_status);
        $this->get($this->signed($item))->assertForbidden();
    }

    public function test_failure_of_another_payment_attempt_cannot_revoke_paid_order(): void
    {
        $item = $this->orderItem();
        $transaction = PaymentTransaction::create([
            'order_id' => $item->order_id, 'gateway' => 'ikhokha', 'external_transaction_id' => 'failed-attempt',
            'amount' => 50, 'currency' => 'ZAR', 'status' => 'pending',
        ]);
        app(OrderPaymentService::class)->markFailed($transaction);
        $this->assertSame('failed', $transaction->fresh()->status);
        $this->assertSame('paid', $item->order->fresh()->payment_status);
        $this->get($this->signed($item))->assertOk();
    }

    public function test_download_limits_are_separate_for_each_purchase(): void
    {
        $product = $this->product(['download_limit' => 1]);
        $first = $this->orderItem($product);
        $second = $this->orderItem($product, ['customer_email' => 'second@example.test']);
        $this->get($this->signed($first))->assertOk();
        $this->get($this->signed($first))->assertForbidden();
        $this->get($this->signed($second))->assertOk();
        $this->assertSame(1, $first->fresh()->download_count);
        $this->assertSame(1, $second->fresh()->download_count);
    }

    public function test_unsigned_expired_tampered_or_wrong_token_links_are_rejected(): void
    {
        $item = $this->orderItem();
        $this->get(route('downloads.file', ['item' => $item->id, 'token' => $item->download_link]))->assertForbidden();
        $this->get($this->signed($item).'&extra=1')->assertForbidden();
        $this->get($this->signed($item, 'wrong-token'))->assertForbidden();
        $this->get(URL::temporarySignedRoute('downloads.file', now()->subMinute(), [
            'item' => $item->id, 'token' => $item->download_link,
        ]))->assertForbidden();
        $this->assertSame(0, $item->fresh()->download_count);
    }

    public function test_a_token_cannot_be_reused_for_another_order_item(): void
    {
        $first = $this->orderItem();
        $second = $this->orderItem();
        $this->get($this->signed($second, $first->download_link))->assertForbidden();
    }

    public function test_pending_failed_refunded_and_cancelled_orders_cannot_download(): void
    {
        $item = $this->orderItem();
        foreach (['pending', 'failed', 'refunded'] as $status) {
            $item->order->update(['payment_status' => $status]);
            $this->get($this->signed($item))->assertForbidden();
        }
        $item->order->update(['payment_status' => 'paid', 'status' => 'cancelled']);
        $this->get($this->signed($item))->assertForbidden();
        $this->assertSame(0, $item->fresh()->download_count);
    }

    public function test_expired_entitlement_cannot_be_renewed_by_revisiting_confirmation(): void
    {
        $item = $this->orderItem();
        $item->update(['download_expires_at' => now()->subMinute()]);
        app(DigitalFulfillmentService::class)->issue($item->order);
        $this->get($this->signed($item))->assertForbidden();
        $this->assertTrue($item->fresh()->download_expires_at->isPast());
    }

    public function test_missing_file_does_not_spend_download_allowance(): void
    {
        $item = $this->orderItem();
        Storage::disk('local')->delete($item->download_path);
        $this->get($this->signed($item))->assertNotFound();
        $this->assertSame(0, $item->fresh()->download_count);
    }

    public function test_head_requests_do_not_spend_download_allowance(): void
    {
        $item = $this->orderItem();
        $this->head($this->signed($item))->assertOk();
        $this->assertSame(0, $item->fresh()->download_count);
    }

    public function test_paths_outside_private_product_directory_are_rejected(): void
    {
        foreach (['.env', 'public/logo.png', 'downloadable_products/../secret', 'downloadable_products/..\\secret'] as $path) {
            $this->assertFalse(DigitalFulfillmentService::isPrivateProductPath($path));
        }
        $item = $this->orderItem();
        $item->update(['download_path' => '.env']);
        $this->get($this->signed($item))->assertNotFound();
    }

    public function test_admin_file_and_limits_are_snapshotted_for_issued_access(): void
    {
        $product = $this->product(['expiration_time' => now()->addDay()]);
        $item = $this->orderItem($product);
        $product->update(['downloadable_file' => 'downloadable_products/replacement.zip', 'download_limit' => 9]);
        app(DigitalFulfillmentService::class)->issue($item->order);
        $this->assertSame('downloadable_products/guide.pdf', $item->fresh()->download_path);
        $this->assertSame(2, $item->fresh()->download_limit);
        $this->assertTrue($item->download_expires_at->lte(now()->addDay()));
    }

    public function test_legacy_private_file_metadata_is_supported_without_global_download_limit(): void
    {
        $product = $this->product(['downloadable_file' => null, 'download_limit' => null]);
        DownloadableProduct::create(['product_id' => $product->id, 'file_url' => 'downloadable_products/guide.pdf', 'download_limit' => 1]);
        $first = $this->orderItem($product);
        $second = $this->orderItem($product);
        $this->get($this->signed($first))->assertOk();
        $this->get($this->signed($second))->assertOk();
    }

    public function test_legacy_product_routes_resolve_both_parameters_and_require_paid_ownership(): void
    {
        $user = User::factory()->create();
        $item = $this->orderItem(orderAttributes: ['user_id' => $user->id]);
        $product = $item->product;
        $url = route('download.generate-link', ['category' => $product->category_id, 'product' => $product->id]);
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $response = $this->actingAs($user)->get($url)->assertOk()->assertJsonPath('downloads_remaining', 2);
        $this->get($response->json('url'))->assertOk();
        $this->get(route('download.generate-link', ['category' => $product->category->slug, 'product' => $product->slug]))->assertOk();
    }

    public function test_downloads_remain_available_while_storefront_is_paused(): void
    {
        $item = $this->orderItem();
        $settings = app(GeneralSettings::class);
        $settings->storefront_enabled = false;
        $settings->save();
        $this->get('/')->assertStatus(503);
        $this->get($this->signed($item))->assertOk();
    }

    public function test_free_checkout_links_account_and_issues_downloads_before_confirmation(): void
    {
        $product = $this->product(['price' => 0]);
        $user = User::factory()->unverified()->create();
        $response = $this->actingAs($user)->withSession(['cart' => [$product->id => ['quantity' => 1]]])
            ->post(route('checkout.process'), ['email' => 'receipt@example.test']);
        $response->assertRedirect()->assertSessionHasNoErrors();
        $order = Order::firstOrFail();
        $this->assertSame($user->id, $order->user_id);
        $this->assertNotNull($order->items->first()->download_path);
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('Download file');
        Notification::assertCount(1);
    }

    public function test_checkout_rejects_digital_products_with_missing_or_expired_files(): void
    {
        foreach ([['downloadable_file' => null], ['expiration_time' => now()->subDay()]] as $overrides) {
            $product = $this->product($overrides);
            $this->withSession(['cart' => [$product->id => ['quantity' => 1]]])
                ->post(route('checkout.process'), ['email' => 'buyer@example.test'])
                ->assertSessionHasErrors('cart');
        }
        $this->assertSame(0, Order::count());
    }

    public function test_unverified_email_match_cannot_read_legacy_orders_or_invoices(): void
    {
        $user = User::factory()->withPersonalTeam()->unverified()->create();
        $item = $this->orderItem(orderAttributes: ['customer_email' => $user->email]);
        $invoice = Invoice::create(['order_id' => $item->order_id, 'invoice_date' => now(), 'total_amount' => 50, 'payment_status' => 'paid']);
        $this->actingAs($user)->get('/orders/'.$item->order_id)->assertForbidden();
        $this->get('/invoices/'.$invoice->id)->assertForbidden();
        $this->assertCount(0, $this->get('/orders')->assertOk()->viewData('orders'));
        $this->assertCount(0, $this->get('/invoices')->assertOk()->viewData('invoices'));
        $user->markEmailAsVerified();
        $this->get('/orders/'.$item->order_id)->assertOk();
        $this->get('/invoices/'.$invoice->id)->assertOk();
    }

    public function test_account_ownership_survives_email_change_and_cannot_be_claimed_by_another_account(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->withPersonalTeam()->create();
        $item = $this->orderItem(orderAttributes: ['user_id' => $owner->id, 'customer_email' => $other->email]);
        $this->actingAs($owner)->get('/orders/'.$item->order_id)->assertOk();
        $this->actingAs($other)->get('/orders/'.$item->order_id)->assertForbidden();
    }

    public function test_email_change_revokes_verification_and_verification_route_is_available(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        app(UpdateUserProfileInformation::class)->update($user, ['name' => $user->name, 'email' => 'changed@example.test']);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->actingAs($user)->get(route('verification.notice'))->assertOk();
        $this->post(route('verification.send'))->assertRedirect();
        $this->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ]))->assertRedirect();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_free_product_page_renders_checkout_instead_of_broken_download_route(): void
    {
        $product = $this->product(['price' => 0, 'pricing_type' => 'free']);
        $this->get(route('products.show', $product))->assertOk()->assertSee('free checkout');
    }
}
