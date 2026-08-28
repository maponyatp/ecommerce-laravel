<div class="fixed bottom-4 right-4 z-50">
    @if($isOpen)
        <section aria-label="Customer support chat" class="flex h-[500px] w-80 max-w-[calc(100vw-2rem)] flex-col rounded-2xl border border-gray-200 bg-white shadow-2xl">
            <header class="flex items-center justify-between rounded-t-2xl bg-blue-600 p-4 text-white">
                <h2 class="font-semibold">Customer support</h2>
                <button type="button" wire:click="toggleChat" aria-label="Minimize chat">−</button>
            </header>
            <div role="alert" class="px-4 text-sm text-red-700">
                @error('chat') <p>{{ $message }}</p> @enderror
                @error('newMessage') <p>{{ $message }}</p> @enderror
                @error('rating') <p>{{ $message }}</p> @enderror
                @error('feedback') <p>{{ $message }}</p> @enderror
            </div>
            @if($showRating)
                <form wire:submit="submitRating" class="flex flex-1 flex-col gap-4 p-4">
                    <label for="chat-rating" class="font-medium">How was your experience?</label>
                    <select id="chat-rating" wire:model="rating" class="rounded-lg border-gray-300">
                        <option value="0">Choose a rating</option>
                        @for($score = 1; $score <= 5; $score++)<option value="{{ $score }}">{{ $score }} / 5</option>@endfor
                    </select>
                    <label for="chat-feedback">Feedback (optional)</label>
                    <textarea id="chat-feedback" wire:model="feedback" maxlength="1000" rows="3" class="rounded-lg border-gray-300"></textarea>
                    <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-blue-600 px-4 py-2 text-white">Submit feedback</button>
                    <button type="button" wire:click="toggleChat" class="text-sm text-gray-600">Skip and minimize</button>
                </form>
            @else
                <div wire:poll.5s="refreshMessages" role="log" aria-label="Chat messages" aria-live="polite" class="flex-1 space-y-3 overflow-y-auto bg-gray-50 p-4">
                    @forelse($messages as $message)
                        <div wire:key="chat-message-{{ $message['id'] }}" class="rounded-lg p-3 {{ $message['sender_type'] === 'customer' ? 'bg-gray-200' : 'bg-blue-100' }}">
                            <span class="text-xs font-semibold text-gray-600">{{ $message['sender_type'] === 'customer' ? 'You' : 'Support' }}</span>
                            <p class="whitespace-pre-wrap break-words text-sm text-gray-900">{{ $message['message'] }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-gray-600">Start a conversation with our flower shop team.</p>
                    @endforelse
                    <p class="text-xs text-gray-500">Showing the latest 100 messages.</p>
                </div>
                @if($status === 'closed')
                    <div class="p-4"><p class="mb-2 text-sm">This chat is closed.</p><button type="button" wire:click="loadOrCreateConversation" wire:loading.attr="disabled" class="text-blue-700">Start a new chat</button></div>
                @else
                    <form wire:submit="sendMessage" class="flex gap-2 border-t p-4">
                        <label for="chat-message" class="sr-only">Your message</label>
                        <input id="chat-message" wire:model="newMessage" maxlength="5000" placeholder="Type your message…" class="min-w-0 flex-1 rounded-lg border-gray-300" autocomplete="off">
                        <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-blue-600 px-3 py-2 text-white">Send</button>
                    </form>
                    <button type="button" wire:click="closeChat" wire:loading.attr="disabled" class="pb-3 text-sm text-gray-600">End chat</button>
                @endif
            @endif
        </section>
    @else
        <button type="button" wire:click="toggleChat" wire:loading.attr="disabled" aria-label="Open customer support chat" class="rounded-full bg-blue-600 px-5 py-3 text-white shadow-lg">Chat with us</button>
    @endif
</div>
