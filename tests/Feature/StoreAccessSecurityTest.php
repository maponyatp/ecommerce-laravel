<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\ChatAgentDashboard;
use App\Filament\Admin\Resources\ChatConversations\Pages\ViewChatConversation;
use App\Livewire\ChatWidget;
use App\Models\ChatConversation;
use App\Models\Product;
use App\Models\User;
use App\Services\ChatService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\StoreApiStaff;
use Tests\TestCase;

class StoreAccessSecurityTest extends TestCase
{
    use RefreshDatabase;
    use StoreApiStaff;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Notification::fake();
    }

    public function test_unknown_guest_cannot_read_write_close_or_rate_another_chat(): void
    {
        $chat = app(ChatService::class)->createConversation(['customer_email' => 'private@example.test']);
        $this->getJson('/chat/'.$chat->id.'/messages')->assertNotFound();
        $this->getJson('/chat/session/'.$chat->session_id)->assertNotFound();
        $this->postJson('/chat/'.$chat->id.'/message', ['message' => 'forged'])->assertNotFound();
        $this->postJson('/chat/'.$chat->id.'/close')->assertNotFound();
        $this->postJson('/chat/'.$chat->id.'/rating', ['rating' => 5])->assertNotFound();
        $this->assertSame('queued', $chat->fresh()->status);
        $this->assertSame(1, $chat->messages()->count());
        $this->assertFalse($chat->messages()->first()->is_read);
    }

    public function test_guest_start_owns_and_recovers_chat_without_exposing_account_data(): void
    {
        $first = $this->postJson('/chat/start', ['customer_email' => 'owner@example.test', 'user_id' => 999, 'session_id' => 'forged'])
            ->assertOk()->assertJsonMissingPath('conversation.session_id')->assertJsonMissingPath('conversation.customer_email');
        $id = $first->json('conversation.id');
        $this->postJson('/chat/start')->assertOk()->assertJsonPath('conversation.id', $id);
        $this->assertDatabaseCount('chat_conversations', 1);
        $chat = ChatConversation::findOrFail($id);
        $this->assertNull($chat->user_id);
        $this->assertNotSame('forged', $chat->session_id);
        $this->postJson('/chat/'.$id.'/message', ['message' => 'My flowers', 'sender_type' => 'agent'])
            ->assertOk()->assertJsonPath('message.sender_type', 'customer');
        $this->getJson('/chat/'.$id.'/messages')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->getJson('/chat/session/'.$chat->session_id)->assertOk();
        $this->postJson('/chat/'.$id.'/close')->assertOk();
        $ended = $chat->fresh()->ended_at;
        $this->postJson('/chat/'.$id.'/close')->assertOk();
        $this->assertEquals($ended, $chat->fresh()->ended_at);
        $this->postJson('/chat/'.$id.'/message', ['message' => 'Too late'])->assertUnprocessable();
        $this->postJson('/chat/'.$id.'/rating', ['rating' => 5])->assertOk();
        $this->postJson('/chat/start')->assertOk();
        $this->assertDatabaseCount('chat_conversations', 2);
    }

    public function test_account_ownership_cannot_be_replaced_by_session_key_or_another_account(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $chat = app(ChatService::class)->createConversation(['user_id' => $owner->id]);
        $this->withSession(['chat_session_id' => $chat->session_id, 'chat_conversation_id' => $chat->id])
            ->actingAs($other)->getJson('/chat/'.$chat->id.'/messages')->assertNotFound();
        $this->actingAs($owner)->getJson('/chat/'.$chat->id.'/messages')->assertOk()
            ->assertJsonMissingPath('conversation.customer')->assertJsonMissingPath('conversation.agent');
    }

    public function test_widget_can_send_poll_recover_close_and_rate_own_chat(): void
    {
        $widget = Livewire::test(ChatWidget::class)->call('toggleChat')->assertSet('isOpen', true);
        $id = $widget->get('conversationId');
        $widget->set('newMessage', '<script>not executable</script>')->call('sendMessage')->assertHasNoErrors()
            ->assertSee('&lt;script&gt;not executable&lt;/script&gt;', false);
        $widget->call('refreshMessages')->assertHasNoErrors();
        Livewire::test(ChatWidget::class)->call('toggleChat')->assertSet('conversationId', $id);
        $widget->call('closeChat')->assertSet('showRating', true)->set('rating', 4)->call('submitRating')
            ->assertSet('isOpen', false)->assertHasNoErrors();
        $this->assertSame('closed', ChatConversation::findOrFail($id)->status);
        $this->assertDatabaseHas('chat_analytics', ['conversation_id' => $id, 'satisfaction_rating' => 4]);
    }

    public function test_widget_conversation_target_is_locked(): void
    {
        $widget = Livewire::test(ChatWidget::class)->call('toggleChat');
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $widget->set('conversationId', 999);
    }

    public function test_guest_start_rate_limit_and_long_messages_are_rejected(): void
    {
        $chat = $this->postJson('/chat/start')->assertOk()->json('conversation.id');
        $this->postJson('/chat/'.$chat.'/message', ['message' => str_repeat('x', 5001)])->assertUnprocessable();
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/chat/'.$chat.'/close')->assertOk();
            $chat = $this->postJson('/chat/start')->assertOk()->json('conversation.id');
        }
        $this->postJson('/chat/'.$chat.'/close')->assertOk();
        $this->postJson('/chat/start')->assertUnprocessable();
    }

    public function test_staff_can_reply_from_admin_and_customer_cannot_open_agent_dashboard(): void
    {
        $chat = app(ChatService::class)->createConversation([]);
        $staff = User::factory()->create();
        $this->grantStoreApiStaff($staff);
        $this->actingAs($staff);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ViewChatConversation::class, ['record' => $chat->id])
            ->callAction('reply', data: ['message' => 'Your bouquet is ready'])->assertHasNoActionErrors();
        $this->assertDatabaseHas('chat_messages', ['conversation_id' => $chat->id, 'message' => 'Your bouquet is ready', 'sender_type' => 'agent']);
        $this->actingAs(User::factory()->create());
        Livewire::test(ChatAgentDashboard::class)->assertForbidden();
    }

    public function test_customers_are_denied_all_management_api_actions_before_side_effects(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        foreach ([
            ['GET', '/api/products'], ['POST', '/api/products'], ['GET', '/api/products/1'],
            ['PUT', '/api/products/1'], ['PATCH', '/api/products/1'], ['DELETE', '/api/products/1'],
            ['GET', '/api/collections'], ['POST', '/api/collections'], ['GET', '/api/collections/1'],
            ['PUT', '/api/collections/1'], ['DELETE', '/api/collections/1'],
            ['POST', '/api/collections/1/products'], ['DELETE', '/api/collections/1/products'],
            ['GET', '/api/dropshipping/suppliers'], ['POST', '/api/dropshipping/check-availability'],
            ['POST', '/api/dropshipping/place-order'], ['POST', '/api/dropshipping/track-order'],
        ] as [$method, $url]) {
            $this->json($method, $url, [])->assertForbidden();
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_staff_need_permissions_and_token_scope_and_revocations_take_effect(): void
    {
        $staff = User::factory()->create();
        $this->grantStoreApiStaff($staff);
        $product = Product::factory()->create(['price' => 100]);
        Sanctum::actingAs($staff, ['catalog:read']);
        $this->getJson('/api/products')->assertOk();
        $this->putJson('/api/products/'.$product->id, ['price' => 1])->assertForbidden();
        Sanctum::actingAs($staff, ['catalog:write']);
        $this->putJson('/api/products/'.$product->id, ['price' => 110])->assertOk();
        $staff->revokePermissionTo('update_product');
        $this->putJson('/api/products/'.$product->id, ['price' => 1])->assertForbidden();
        $this->assertSame('110.00', $product->fresh()->price);
    }

    public function test_real_customer_bearer_token_cannot_update_product(): void
    {
        $customer = User::factory()->create();
        $token = $customer->createToken('security-test', ['catalog:write'])->plainTextToken;
        $product = Product::factory()->create(['price' => 100]);
        $this->withToken($token)->putJson('/api/products/'.$product->id, ['price' => 1])->assertForbidden();
        $this->assertSame('100.00', $product->fresh()->price);
    }

    public function test_real_staff_token_requires_scope_and_unexpired_token(): void
    {
        $staff = User::factory()->create();
        $this->grantStoreApiStaff($staff);
        $product = Product::factory()->create(['price' => 100]);
        $token = $staff->createToken('staff-read', ['catalog:read'])->plainTextToken;
        $this->withToken($token)->getJson('/api/products')->assertOk();
        app('auth')->forgetGuards();
        $this->withToken($token)->putJson('/api/products/'.$product->id, ['price' => 1])->assertForbidden();
        $write = $staff->createToken('staff-write', ['catalog:write'])->plainTextToken;
        app('auth')->forgetGuards();
        $this->withToken($write)->putJson('/api/products/'.$product->id, ['price' => 120])->assertOk();
        $expired = $staff->createToken('expired', ['*'], now()->subMinute())->plainTextToken;
        app('auth')->forgetGuards();
        $this->withToken($expired)->putJson('/api/products/'.$product->id, ['price' => 1])->assertUnauthorized();
        $this->assertSame('120.00', $product->fresh()->price);
    }
}
