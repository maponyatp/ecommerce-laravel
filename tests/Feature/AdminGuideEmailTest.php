<?php

namespace Tests\Feature;

use App\Mail\AdminSystemGuideMail;
use App\Models\User;
use App\Services\AdminGuideDeliveryService;
use App\Settings\GeneralSettings;
use App\Support\AdminFeatureGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminGuideEmailTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = 'admin', ?string $email = null): User
    {
        $user = User::factory()->create($email ? ['email' => $email] : []);
        $user->assignRole(Role::findOrCreate($role, 'web'));

        return $user;
    }

    private function brand(): void
    {
        config(['app.url' => 'https://store.example.test', 'mail.default' => 'array', 'mail.from.address' => 'noreply@example.test']);
        $settings = app(GeneralSettings::class);
        $settings->site_name = 'Garden & Grace';
        $settings->store_primary_color = '#14634a';
        $settings->site_email = 'help@example.test';
        $settings->save();
    }

    public function test_preview_has_no_send_or_database_side_effects(): void
    {
        $this->admin();
        Mail::fake();
        $this->artisan('commerce:email-admin-guide', ['--campaign' => 'preview'])->assertSuccessful();
        Mail::assertNothingSent();
        $this->assertDatabaseCount('admin_email_deliveries', 0);
    }

    public function test_only_active_admins_are_selected_without_duplicate_addresses(): void
    {
        $admin = $this->admin('admin', 'admin@example.test');
        $super = $this->admin('super_admin', 'super@example.test');
        $duplicate = $this->admin('super_admin', 'ADMIN@example.test');
        $disabled = $this->admin();
        DB::table('users')->where('id', $disabled->id)->update(['staff_access_disabled_at' => now()]);
        User::factory()->create();
        $this->admin('customer');
        $this->assertSame([$admin->id, $super->id], app(AdminGuideDeliveryService::class)->recipients()->modelKeys());
    }

    public function test_recipient_selection_supports_the_deployed_schema_without_pending_security_migration(): void
    {
        $admin = $this->admin();
        Schema::table('users', fn ($table) => $table->dropColumn('staff_access_disabled_at'));
        $this->assertSame([$admin->id], app(AdminGuideDeliveryService::class)->recipients()->modelKeys());
    }

    public function test_feature_and_test_messages_render_html_text_branding_and_private_single_recipient(): void
    {
        $this->brand();
        $admin = $this->admin('super_admin', 'admin@example.test');
        $service = app(AdminGuideDeliveryService::class);
        foreach (['test', 'features'] as $kind) {
            $this->assertSame(['status' => 'accepted', 'skipped' => false], $service->deliver($admin, $kind, 'release-test'));
            $message = Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage();
            $this->assertCount(1, $message->getTo());
            $this->assertSame('admin@example.test', $message->getTo()[0]->getAddress());
            $this->assertEmpty($message->getCc());
            $this->assertEmpty($message->getBcc());
            $this->assertSame('Garden & Grace', $message->getFrom()[0]->getName());
            $this->assertStringContainsString('Garden &amp; Grace', $message->getHtmlBody());
            $this->assertStringContainsString('#14634a', $message->getHtmlBody());
            $this->assertStringContainsString('Garden & Grace', $message->getTextBody());
            $this->assertStringContainsString('https://store.example.test/admin', $message->getTextBody());
            $this->assertLessThan(95000, strlen($message->getHtmlBody()), 'Avoid common email-client clipping thresholds.');
            $this->assertStringNotContainsString('@endif', $message->getHtmlBody());
            if ($kind === 'features') {
                $this->assertStringContainsString('PayFast, Peach Payments and Ozow', $message->getTextBody());
                $this->assertStringContainsString('not operational', $message->getTextBody());
                $this->assertStringContainsString('not launch certification', $message->getTextBody());
                $this->assertStringContainsString('14.', $message->getTextBody());
                $this->assertSame(15, preg_match_all('/<li(?:\s|>)/', $message->getHtmlBody()), 'Render eight configuration checks and seven acceptance steps as separate list items.');
            }
        }
        $row = DB::table('admin_email_deliveries')->first();
        $this->assertNotSame('admin@example.test', $row->recipient_email);
        $this->assertSame('admin@example.test', Crypt::decryptString($row->recipient_email));
        $this->assertNotNull($row->message_id);
        $this->assertNotNull($row->accepted_at);
        $this->assertNull($row->error_class);
    }

    public function test_repeat_send_is_idempotent_even_when_another_account_shares_the_email(): void
    {
        $this->brand();
        $admin = $this->admin();
        $service = app(AdminGuideDeliveryService::class);
        $service->deliver($admin, 'features', 'same-release');
        $this->assertSame(['status' => 'accepted', 'skipped' => true], $service->deliver($admin, 'features', 'same-release'));
        $this->assertCount(1, Mail::mailer()->getSymfonyTransport()->messages());
        $this->assertDatabaseCount('admin_email_deliveries', 1);
    }

    public function test_uncertain_sends_are_recorded_without_secret_exception_details_and_not_retried(): void
    {
        $this->brand();
        $admin = $this->admin();
        $renderer = Mail::mailer();
        Mail::shouldReceive('mailer')->withNoArgs()->andReturn($renderer);
        Mail::shouldReceive('mailer')->once()->with('array')->andThrow(new \RuntimeException('SMTP_PASSWORD=do-not-store'));
        $service = app(AdminGuideDeliveryService::class);
        $this->assertSame(['status' => 'uncertain', 'skipped' => false], $service->deliver($admin, 'test', 'timeout'));
        $this->assertSame(['status' => 'uncertain', 'skipped' => true], $service->deliver($admin, 'test', 'timeout'));
        $row = DB::table('admin_email_deliveries')->first();
        $this->assertSame(\RuntimeException::class, $row->error_class);
        $this->assertStringNotContainsString('do-not-store', json_encode($row));
        $this->assertNull($row->accepted_at);
    }

    public function test_interrupted_sending_claim_is_not_automatically_retried(): void
    {
        $admin = $this->admin('admin', 'admin@example.test');
        DB::table('admin_email_deliveries')->insert(['campaign' => 'interrupted', 'kind' => 'test', 'recipient_hash' => hash('sha256', $admin->email), 'user_id' => $admin->id, 'recipient_email' => Crypt::encryptString($admin->email), 'status' => 'sending', 'created_at' => now(), 'updated_at' => now()]);
        Mail::fake();
        $this->assertSame(['status' => 'sending', 'skipped' => true], app(AdminGuideDeliveryService::class)->deliver($admin, 'test', 'interrupted'));
        Mail::assertNothingSent();
    }

    public function test_command_sends_one_message_to_each_admin_and_repeat_is_safe(): void
    {
        $this->brand();
        $this->admin('admin');
        $this->admin('super_admin');
        User::factory()->create();
        foreach ([1, 2] as $attempt) {
            $this->artisan('commerce:email-admin-guide', ['--campaign' => 'release', '--kind' => 'test', '--send' => true])->assertSuccessful();
        }
        $this->assertDatabaseCount('admin_email_deliveries', 2);
        $this->assertCount(2, Mail::mailer()->getSymfonyTransport()->messages());
    }

    public function test_revoked_role_cannot_receive_a_message_even_with_a_stale_model(): void
    {
        $admin = $this->admin();
        $admin->syncRoles([]);
        $this->expectException(\RuntimeException::class);
        app(AdminGuideDeliveryService::class)->deliver($admin, 'test', 'revoked');
    }

    public function test_command_rejects_invalid_or_missing_campaign_and_empty_recipients(): void
    {
        Mail::fake();
        $this->artisan('commerce:email-admin-guide')->assertFailed();
        $this->artisan('commerce:email-admin-guide', ['--campaign' => '../bad', '--send' => true])->assertFailed();
        $this->artisan('commerce:email-admin-guide', ['--campaign' => 'valid', '--kind' => 'other'])->assertFailed();
        $this->artisan('commerce:email-admin-guide', ['--campaign' => 'valid', '--send' => true])->assertFailed();
        Mail::assertNothingSent();
    }

    public function test_every_guide_link_matches_an_existing_get_route(): void
    {
        $guide = app(AdminFeatureGuide::class)->content();
        $this->assertCount(14, $guide['modules']);
        foreach ($guide['modules'] as $module) {
            $route = Route::getRoutes()->match(Request::create('https://localhost'.$module['path'], 'GET'));
            $this->assertNotSame('cms.pages.slug', $route->getName(), $module['path']);
        }
    }

    public function test_recipient_name_is_html_escaped(): void
    {
        $this->brand();
        $html = (new AdminSystemGuideMail('<img src=x onerror=alert(1)>', 'test', []))->render();
        $this->assertStringNotContainsString('<img src=x', $html);
    }
}
