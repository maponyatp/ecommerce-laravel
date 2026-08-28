<?php

namespace Tests\Feature;

use App\Http\Middleware\PrivateCustomerDirectory;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\SavedPaymentMethodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class SavedPaymentMethodSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->actingAs(User::factory()->create());
    }

    private function method(array $data = []): PaymentMethod
    {
        return PaymentMethod::create($data + ['user_id' => auth()->id(), 'name' => 'Personal', 'details' => 'pm_private_reference_1234', 'is_default' => false]);
    }

    public function test_get_and_head_edit_are_read_only_even_with_update_parameters(): void
    {
        $method = $this->method();
        $before = $method->fresh()->getRawOriginal();
        $url = '/payment_methods/edit/'.$method->id.'?name=Changed&details=pm_attacker_5678&is_default=1';
        $this->get($url)->assertOk()->assertViewIs('payment_methods.edit')->assertSee('Save changes')
            ->assertDontSee('pm_private_reference_1234')->assertDontSee('pm_attacker_5678')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->head($url)->assertOk();
        $this->assertSame($before, $method->fresh()->getRawOriginal());
    }

    public function test_browser_forms_redirect_and_blank_replacement_keeps_the_reference(): void
    {
        $this->post('/payment_methods/store', ['name' => 'Personal', 'details' => 'pm_created_1234', 'is_default' => 1])->assertRedirect('/payment_methods');
        $method = PaymentMethod::firstOrFail();
        $this->post('/payment_methods/update/'.$method->id, ['name' => 'Renamed', 'details' => '', 'is_default' => 0])->assertRedirect('/payment_methods');
        $this->assertSame('pm_created_1234', $method->fresh()->details);
        $this->assertSame('Renamed', $method->fresh()->name);
        $this->get('/payment_methods')->assertOk()->assertSee('Renamed')->assertDontSee('pm_created_1234')->assertSee('Make default');
        $this->delete('/payment_methods/destroy/'.$method->id)->assertRedirect('/payment_methods');
        $this->assertDatabaseCount('payment_methods', 0);
        Http::assertNothingSent();
    }

    public function test_add_edit_and_explicit_default_share_one_default_invariant(): void
    {
        $one = $this->method(['is_default' => true]);
        $this->postJson('/payment_methods/store', ['name' => 'Second', 'details' => 'pm_second_1234', 'is_default' => true])->assertCreated();
        $two = PaymentMethod::orderByDesc('id')->first();
        $this->assertFalse((bool) $one->fresh()->is_default);
        $this->postJson('/payment_methods/update/'.$one->id, ['is_default' => true])->assertOk();
        $this->assertFalse((bool) $two->fresh()->is_default);
        $this->post('/payment_methods/set_default/'.$two->id)->assertRedirect('/payment_methods');
        $this->assertSame(1, PaymentMethod::where('user_id', auth()->id())->where('is_default', true)->count());
        $this->assertTrue((bool) $two->fresh()->is_default);
    }

    public function test_legacy_duplicate_defaults_are_normalized_without_changing_another_customer(): void
    {
        $one = $this->method(['is_default' => true]);
        $two = $this->method(['is_default' => true]);
        $other = $this->method(['user_id' => User::factory()->create()->id, 'is_default' => true]);
        app(SavedPaymentMethodService::class)->save(auth()->id(), ['name' => 'Updated'], $two->id);
        $this->assertTrue((bool) $one->fresh()->is_default);
        $this->assertFalse((bool) $two->fresh()->is_default);
        $this->assertTrue((bool) $other->fresh()->is_default);
    }

    public function test_other_customers_cannot_read_update_delete_or_select_a_reference(): void
    {
        $method = $this->method(['user_id' => User::factory()->create()->id]);
        $this->get('/payment_methods/edit/'.$method->id)->assertNotFound();
        $this->postJson('/payment_methods/update/'.$method->id, ['name' => 'Changed'])->assertNotFound();
        $this->postJson('/payment_methods/set_default/'.$method->id)->assertNotFound();
        $this->deleteJson('/payment_methods/destroy/'.$method->id)->assertNotFound();
        $this->assertSame('Personal', $method->fresh()->name);
    }

    public function test_invalid_references_are_rejected_and_ownership_input_is_ignored(): void
    {
        foreach (['pm_', '4111111111111111', "pm_test_1234\nextra", 'pm_1234 api-secret'] as $reference) {
            $this->postJson('/payment_methods/store', ['name' => 'Invalid', 'details' => $reference])->assertUnprocessable();
        }
        $this->postJson('/payment_methods/store', ['user_id' => User::factory()->create()->id, 'name' => 'Own', 'details' => 'pm_owned_1234'])->assertCreated();
        $this->assertSame(auth()->id(), PaymentMethod::first()->user_id);
    }

    public function test_form_write_requires_csrf_outside_the_test_environment(): void
    {
        $this->app->instance('env', 'local');
        try {
            $this->post('/payment_methods/store', ['name' => 'Untrusted', 'details' => 'pm_csrf_1234'])->assertStatus(419);
        } finally {
            $this->app->instance('env', 'testing');
        }
        $this->assertDatabaseCount('payment_methods', 0);
    }

    public function test_invalid_browser_input_does_not_flash_reference_or_card_data(): void
    {
        $this->from('/payment_methods')->post('/payment_methods/store', ['name' => 'Bad', 'details' => '4111111111111111'])
            ->assertRedirect('/payment_methods')->assertSessionHasErrors('details')->assertSessionMissing('_old_input.details');
    }

    public function test_guest_cannot_access_saved_references(): void
    {
        auth()->logout();
        $this->get('/payment_methods')->assertRedirect('/login');
        $this->postJson('/payment_methods/store', ['name' => 'Guest', 'details' => 'pm_guest_1234'])->assertUnauthorized();
    }

    public function test_private_headers_support_streamed_and_binary_downloads(): void
    {
        foreach ([new StreamedResponse(fn () => null), new BinaryFileResponse(base_path('README.md'))] as $response) {
            $actual = (new PrivateCustomerDirectory)->handle(Request::create('/private'), fn () => $response);
            $this->assertSame($response, $actual);
            $this->assertTrue($actual->headers->hasCacheControlDirective('no-store'));
            $this->assertSame('no-referrer', $actual->headers->get('Referrer-Policy'));
            $this->assertSame('noindex, nofollow, noarchive', $actual->headers->get('X-Robots-Tag'));
        }
    }
}
