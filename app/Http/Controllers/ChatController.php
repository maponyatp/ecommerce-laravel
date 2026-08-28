<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Services\ChatService;
use App\Services\CustomerChatService;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function __construct(private CustomerChatService $chat) {}

    public function start(Request $request)
    {
        return response()->json(['success' => true, 'conversation' => $this->chat->data($this->chat->start($request->all()))]);
    }

    public function sendMessage(Request $request, int $conversationId)
    {
        $this->chat->authorize($conversationId);
        $data = $request->validate(['message' => 'required|string|max:5000']);
        $message = $this->chat->send($conversationId, $data['message']);

        return response()->json(['success' => true, 'message' => $message->only(['id', 'message', 'sender_type', 'created_at'])]);
    }

    public function getMessages(int $conversationId)
    {
        $conversation = $this->chat->authorize($conversationId);

        return response()->json(['success' => true, 'conversation' => $this->chat->data($conversation)]);
    }

    public function getBySession(string $sessionId)
    {
        $conversation = ChatConversation::where('session_id', $sessionId)->first();
        if ($conversation) {
            $this->chat->authorize($conversation->id);
        }

        return response()->json(['success' => true, 'conversation' => $conversation ? $this->chat->data($conversation) : null]);
    }

    public function close(int $conversationId)
    {
        return response()->json(['success' => true, 'conversation' => $this->chat->data($this->chat->close($conversationId))]);
    }

    public function submitRating(Request $request, int $conversationId)
    {
        $this->chat->rate($conversationId, $request->all());

        return response()->json(['success' => true, 'message' => 'Thank you for your feedback!']);
    }

    public function agentConversations()
    {
        abort_unless($this->chat->isStaff(), 403);

        return response()->json(['success' => true, 'conversations' => app(ChatService::class)->getAgentConversations(auth()->id())]);
    }

    public function assignAgent(int $conversationId)
    {
        abort_unless($this->chat->isStaff(), 403);

        return response()->json(['success' => true, 'conversation' => app(ChatService::class)->assignAgent($conversationId, auth()->id())]);
    }

    public function nextQueued()
    {
        abort_unless($this->chat->isStaff(), 403);

        return response()->json(['success' => true, 'conversation' => app(ChatService::class)->getNextQueuedConversation()]);
    }
}
