<?php

namespace App\Services;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerChatService
{
    public function isStaff(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super_admin']) ?? false;
    }

    public function canAccess(ChatConversation $conversation): bool
    {
        if ($this->isStaff()) {
            return true;
        }
        if ($conversation->user_id !== null) {
            return auth()->check() && (int) $conversation->user_id === (int) auth()->id();
        }

        return (int) session('chat_conversation_id') === $conversation->id
            && is_string(session('chat_session_id'))
            && hash_equals($conversation->session_id, session('chat_session_id'));
    }

    public function authorize(int $id): ChatConversation
    {
        $conversation = ChatConversation::findOrFail($id);
        abort_unless($this->canAccess($conversation), 404);

        return $conversation;
    }

    public function current(): ?ChatConversation
    {
        $conversation = ChatConversation::find(session('chat_conversation_id'));

        return $conversation && $this->canAccess($conversation) ? $conversation : null;
    }

    private function limit(string $action, int $maximum): void
    {
        $key = 'customer-chat:'.$action.':'.hash('sha256', (string) request()->ip());
        if (! RateLimiter::attempt($key, $maximum, fn () => true, 60)) {
            throw ValidationException::withMessages(['chat' => 'Too many chat requests. Please wait a minute and try again.']);
        }
    }

    public function start(array $input = []): ChatConversation
    {
        $current = $this->current();
        if ($current && $current->status !== 'closed') {
            return $current;
        }
        $this->limit('start', 5);
        $data = Validator::make($input, ['customer_name' => 'nullable|string|max:255', 'customer_email' => 'nullable|email|max:255'])->validate();
        $data['session_id'] = (string) Str::uuid();
        $conversation = DB::transaction(fn () => app(ChatService::class)->createConversation($data));
        session(['chat_conversation_id' => $conversation->id, 'chat_session_id' => $conversation->session_id]);

        return $conversation;
    }

    public function send(int $id, string $message, bool $asCustomer = false): ChatMessage
    {
        $this->authorize($id);
        $this->limit('message', 30);
        $data = Validator::make(['message' => trim($message)], ['message' => 'required|string|max:5000'])->validate();

        return DB::transaction(function () use ($id, $data, $asCustomer) {
            $conversation = ChatConversation::lockForUpdate()->findOrFail($id);
            abort_unless($this->canAccess($conversation), 404);
            if ($conversation->status === 'closed') {
                throw ValidationException::withMessages(['chat' => 'This conversation is closed. Start a new chat to continue.']);
            }

            return app(ChatService::class)->addMessage($id, ['message' => $data['message'],
                'sender_type' => ! $asCustomer && $this->isStaff() ? 'agent' : 'customer']);
        });
    }

    public function close(int $id): ChatConversation
    {
        $this->authorize($id);
        $this->limit('close', 10);

        return DB::transaction(function () use ($id) {
            $conversation = ChatConversation::lockForUpdate()->findOrFail($id);
            abort_unless($this->canAccess($conversation), 404);

            return $conversation->status === 'closed' ? $conversation : app(ChatService::class)->closeConversation($id);
        });
    }

    public function rate(int $id, array $input): void
    {
        $this->authorize($id);
        $this->limit('rating', 10);
        $data = Validator::make($input, ['rating' => 'required|integer|min:1|max:5', 'feedback' => 'nullable|string|max:1000'])->validate();
        app(ChatService::class)->addSatisfactionRating($id, $data['rating'], $data['feedback'] ?? null);
    }

    public function data(ChatConversation $conversation): array
    {
        abort_unless($this->canAccess($conversation), 404);

        // Do not expose customer/agent account records or the session ownership key.
        return ['id' => $conversation->id, 'status' => $conversation->status,
            'messages' => $conversation->messages()->orderByDesc('id')->limit(100)->get()
                ->reverse()->values()->map(fn ($message) => $message->only(['id', 'message', 'sender_type', 'created_at']))->all()];
    }
}
