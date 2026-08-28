<?php

namespace App\Livewire;

use App\Services\CustomerChatService;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ChatWidget extends Component
{
    public bool $isOpen = false;

    #[Locked]
    public ?int $conversationId = null;

    #[Locked]
    public array $messages = [];

    #[Locked]
    public string $status = '';

    public string $newMessage = '';

    public bool $showRating = false;

    public int $rating = 0;

    public string $feedback = '';

    public function toggleChat(): void
    {
        $this->isOpen = ! $this->isOpen;
        if ($this->isOpen) {
            $this->loadOrCreateConversation();
        }
    }

    public function loadOrCreateConversation(): void
    {
        $chat = app(CustomerChatService::class);
        $conversation = $chat->start();
        $this->conversationId = $conversation->id;
        $this->showRating = false;
        $this->refreshMessages();
    }

    public function refreshMessages(): void
    {
        if (! $this->isOpen || ! $this->conversationId) {
            return;
        }
        $chat = app(CustomerChatService::class);
        $data = $chat->data($chat->authorize($this->conversationId));
        $this->messages = $data['messages'];
        $this->status = $data['status'];
    }

    public function sendMessage(): void
    {
        $this->validate(['newMessage' => 'required|string|max:5000']);
        abort_unless($this->conversationId, 404);
        app(CustomerChatService::class)->send($this->conversationId, $this->newMessage, asCustomer: true);
        $this->newMessage = '';
        $this->refreshMessages();
    }

    public function closeChat(): void
    {
        if ($this->conversationId) {
            app(CustomerChatService::class)->close($this->conversationId);
            $this->status = 'closed';
            $this->showRating = true;
        } else {
            $this->isOpen = false;
        }
    }

    public function submitRating(): void
    {
        $this->validate(['rating' => 'required|integer|min:1|max:5', 'feedback' => 'nullable|string|max:1000']);
        abort_unless($this->conversationId, 404);
        app(CustomerChatService::class)->rate($this->conversationId, ['rating' => $this->rating, 'feedback' => $this->feedback]);
        $this->reset(['isOpen', 'conversationId', 'messages', 'showRating', 'rating', 'feedback', 'status']);
    }

    public function render()
    {
        return view('livewire.chat-widget');
    }
}
