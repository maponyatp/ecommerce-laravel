<?php

namespace Tests\Feature;

use App\Mail\ContactEnquiryMail;
use App\Mail\InvoiceMail;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications;
use App\Services\InvoiceDocumentService;
use App\Settings\GeneralSettings;
use App\Support\StoreBranding;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmailBrandingTest extends TestCase
{
    use RefreshDatabase;

    private function brand(): void
    {
        config(['app.name' => 'UNBRANDED_APP', 'app.url' => 'https://store.example.test', 'mail.from.address' => 'noreply@example.test']);
        $settings = app(GeneralSettings::class);
        $settings->site_name = 'Garden & Grace';
        $settings->site_email = 'help@example.test';
        $settings->store_primary_color = '#14634a';
        $settings->save();
    }

    public function test_every_application_and_auth_notification_uses_store_branding_in_html_and_text(): void
    {
        $this->brand();
        $user = User::factory()->create();
        $product = new Product(['name' => 'Bouquet', 'inventory_count' => 2, 'low_stock_threshold' => 3]);
        $product->id = 7;
        $order = new Order(['total_amount' => 100, 'currency' => 'ZAR']);
        $order->id = 8;
        $order->setRelation('items', collect());
        $order->setRelation('shippingMethod', null);
        $notifications = [
            new ResetPassword('test-token'), new VerifyEmail,
            new Notifications\LowStockNotification($product),
            new Notifications\LoyaltyPointsEarnedNotification(10, 20),
            new Notifications\OrderConfirmationNotification($order),
            new Notifications\OrderRefundedNotification($order, 20),
            new Notifications\PaypalTransactionNotification(['type' => 'payment', 'amount' => 'ZAR 100.00']),
            new Notifications\ProductBackInStockNotification($product),
            new Notifications\SubscriptionUpdatedNotification(['type' => 'cancellation', 'cancellation_date' => '28 August 2026']),
            new Notifications\SupplierFailureNotification('Test supplier issue'),
            new Notifications\TransactionSuccessNotification(['transaction_id' => 1, 'amount' => 100, 'currency' => 'ZAR']),
            new \Filament\Auth\Notifications\ResetPassword('admin-token'),
        ];
        foreach ($notifications as $notification) {
            if ($notification instanceof \Filament\Auth\Notifications\ResetPassword) {
                $notification->url = 'https://store.example.test/admin/password-reset/reset?token=admin-token';
            }
            $message = $notification->toMail($user);
            $html = (string) app(Markdown::class)->render($message->markdown, $message->data());
            $text = (string) app(Markdown::class)->renderText($message->markdown, $message->data());
            $this->assertStringContainsString('Garden &amp; Grace', $html, $notification::class);
            $this->assertStringContainsString('#14634a', $html);
            $this->assertStringContainsString('help@example.test', $html);
            $this->assertStringContainsString('Garden & Grace', $text);
            $this->assertStringNotContainsString('UNBRANDED_APP', $html.$text);
            $this->assertStringNotContainsString('laravel.com/img', $html);
        }
    }

    public function test_invoice_and_contact_emails_render_and_sender_keeps_authenticated_address(): void
    {
        $this->brand();
        $order = Order::create(['customer_email' => 'buyer@example.test', 'order_date' => now(), 'total_amount' => 100, 'currency' => 'ZAR', 'payment_status' => 'paid', 'status' => 'processing', 'shipping_status' => 'not_required']);
        $invoice = Invoice::create(['order_id' => $order->id, 'invoice_date' => now(), 'total_amount' => 100, 'payment_status' => 'paid']);
        Mail::to('buyer@example.test')->send(new InvoiceMail($invoice));
        $email = Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame('Garden & Grace', $email->getFrom()[0]->getName());
        $this->assertSame('noreply@example.test', $email->getFrom()[0]->getAddress());
        $this->assertSame('help@example.test', $email->getReplyTo()[0]->getAddress());
        $this->assertStringContainsString('signature=', $email->getHtmlBody());
        $this->assertStringContainsString('ZAR 100.00', $email->getHtmlBody());
        $this->assertStringContainsString('Garden & Grace', $email->getTextBody());

        Mail::to('help@example.test')->send(new ContactEnquiryMail(['first_name' => 'Buyer', 'email' => 'buyer@example.test', 'message' => '<script>alert(1)</script>']));
        $email = Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame('buyer@example.test', $email->getReplyTo()[0]->getAddress());
        $this->assertStringNotContainsString('<script>', $email->getHtmlBody());
        $this->assertStringContainsString('Garden &amp; Grace', $email->getHtmlBody());
    }

    public function test_optional_team_invitation_template_is_branded(): void
    {
        $this->brand();
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->ownedTeams()->create(['name' => 'Store team', 'personal_team' => false]);
        $invitation = new TeamInvitation(['email' => 'colleague@example.test', 'role' => 'editor']);
        $invitation->id = 1;
        $invitation->setRelation('team', $team);
        // Team routes are disabled in this deployment. Check the optional template
        // without enabling invitations or changing their authentication workflow.
        $html = (string) app(Markdown::class)->render('emails.team-invitation', ['invitation' => $invitation, 'acceptUrl' => 'https://store.example.test/invitation?signature=synthetic']);
        $this->assertStringContainsString('Garden &amp; Grace', $html);
        $this->assertStringContainsString('Accept invitation', $html);
        $this->assertStringContainsString('signature=', $html);
    }

    public function test_logo_paths_are_local_and_missing_or_unsupported_images_fall_back_to_name(): void
    {
        Storage::fake('public');
        $branding = app(StoreBranding::class);
        foreach (['https://evil.test/logo.png', '../secret.png', 'cms/branding/../../secret.png', 'cms/branding/logo.svg', 'cms/branding/missing.png'] as $path) {
            $this->assertNull($branding->logoUrl($path));
        }
        Storage::disk('public')->put('cms/branding/logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j2ioAAAAASUVORK5CYII='));
        $settings = app(GeneralSettings::class);
        $settings->site_logo_path = 'cms/branding/logo.png';
        $settings->save();
        $this->assertStringContainsString('/storage/cms/branding/logo.png', $branding->current()['logo_url']);
        $this->assertStringStartsWith('data:image/png;base64,', $branding->invoiceSnapshot()['logo_data']);
    }

    public function test_untrusted_colour_is_rejected_and_light_buttons_get_dark_text(): void
    {
        $settings = app(GeneralSettings::class);
        $settings->store_primary_color = 'red; background:url(https://evil.test)';
        $settings->save();
        $this->assertSame('#18181b', app(StoreBranding::class)->current()['colour']);
        $settings->store_primary_color = '#ffffff';
        $settings->save();
        $this->assertSame('#000000', app(StoreBranding::class)->current()['ink']);
    }

    public function test_seeded_contact_details_never_appear_as_real_business_contacts(): void
    {
        $settings = app(GeneralSettings::class);
        $settings->site_email = 'info@example.com';
        $settings->site_phone = '+44 208 050 5865';
        $settings->site_address = '123 Ecommerce St, London, UK';
        $settings->save();
        $brand = app(StoreBranding::class)->current();
        $this->assertNull($brand['email']);
        $this->assertNull($brand['phone']);
        $this->assertNull($brand['address']);
        $this->assertNull(app(InvoiceDocumentService::class)->context()['seller']['address']);
        $html = (new ContactEnquiryMail(['first_name' => 'Buyer', 'email' => 'buyer@example.test', 'message' => 'Test']))->render();
        $this->assertStringNotContainsString('info@example.com', $html);
        $this->assertStringNotContainsString('123 Ecommerce St', $html);
    }

    public function test_contact_form_uses_branded_mail_and_refuses_placeholder_destination(): void
    {
        Mail::fake();
        $data = ['first_name' => 'Buyer', 'email' => 'buyer@example.test', 'message' => 'Please help with my order.'];
        $settings = app(GeneralSettings::class);
        $settings->site_email = 'info@example.com';
        $settings->save();
        $this->post('/contact', $data)->assertSessionHas('error');
        Mail::assertNothingSent();
        $settings->site_email = 'support@example.test';
        $settings->save();
        $this->post('/contact', $data)->assertSessionHas('success');
        Mail::assertSent(ContactEnquiryMail::class, fn ($mail) => $mail->hasTo('support@example.test') && $mail->enquiry['email'] === 'buyer@example.test');
    }
}
