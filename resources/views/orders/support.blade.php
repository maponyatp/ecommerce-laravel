@extends('layouts.app')
@section('title', 'Order support #'.$order->id)
@section('content')
<div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
    <p class="text-sm font-medium text-gray-500">Private order support</p>
    <h1 class="mt-2 text-3xl font-bold text-gray-950">How can we help with order #{{ $order->id }}?</h1>
    <p class="mt-3 text-sm text-gray-600">Tell us about damaged flowers, a missing delivery or another concern. Keep this page private. Replies appear here; automated support emails are not sent. For urgent flower delivery issues, <a href="{{ route('contact') }}" class="underline">contact the shop</a>.</p>
    <p class="mt-2 text-sm text-gray-600">A support case is not a refund, return approval or replacement booking. Please keep the flowers and packaging until the shop advises you. Do not include card details, passwords or other sensitive information.</p>
    @if($errors->any())
        <div role="alert" class="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif

    @if(!$active)
        <form method="POST" action="{{ URL::temporarySignedRoute('order-support.store', now()->addMinutes(30), ['order' => $order->id]) }}" class="mt-6 space-y-4 rounded-2xl border border-gray-200 bg-white p-6">
            @csrf
            <input type="hidden" name="action" value="open">
            <input type="hidden" name="submission_key" value="{{ old('submission_key', (string) \Illuminate\Support\Str::uuid()) }}">
            <h2 class="text-lg font-semibold">New support case</h2>
            <div><label for="support_category" class="block text-sm font-medium">What went wrong?</label><select id="support_category" name="category" required class="mt-1 w-full rounded-lg border-gray-300">@foreach(\App\Models\OrderIssue::CATEGORIES as $value => $label)<option value="{{ $value }}" @selected(old('category') === $value)>{{ $label }}</option>@endforeach</select></div>
            <div><label for="support_item" class="block text-sm font-medium">Affected item (optional)</label><select id="support_item" name="order_item_id" class="mt-1 w-full rounded-lg border-gray-300"><option value="">Whole order</option>@foreach($order->items as $item)<option value="{{ $item->id }}" @selected((string) old('order_item_id') === (string) $item->id)>{{ $item->product_name_snapshot ?? $item->product?->name ?? 'Item #'.$item->id }} ({{ $item->quantity }} ordered)</option>@endforeach</select></div>
            <div><label for="support_quantity" class="block text-sm font-medium">Affected quantity (required only when selecting an item)</label><input id="support_quantity" name="quantity" type="number" min="1" max="9999" value="{{ old('quantity') }}" class="mt-1 w-full rounded-lg border-gray-300"></div>
            <div><label for="support_message" class="block text-sm font-medium">Describe the issue</label><textarea id="support_message" name="message" required minlength="10" maxlength="4000" rows="5" class="mt-1 w-full rounded-lg border-gray-300">{{ old('message') }}</textarea></div>
            <button class="rounded-lg bg-gray-900 px-5 py-2.5 text-sm font-semibold text-white">Send support request</button>
        </form>
    @else
        <form method="POST" action="{{ URL::temporarySignedRoute('order-support.store', now()->addMinutes(30), ['order' => $order->id]) }}" class="mt-6 space-y-4 rounded-2xl border border-gray-200 bg-white p-6">
            @csrf
            <input type="hidden" name="action" value="reply">
            <input type="hidden" name="issue_id" value="{{ $active->id }}">
            <input type="hidden" name="submission_key" value="{{ old('submission_key', (string) \Illuminate\Support\Str::uuid()) }}">
            <h2 class="text-lg font-semibold">Reply to case #{{ $active->id }}</h2>
            <p class="text-sm text-gray-600">{{ \App\Models\OrderIssue::STATUSES[$active->status] }}</p>
            <label for="support_reply" class="block text-sm font-medium">Your reply</label>
            <textarea id="support_reply" name="message" required maxlength="4000" rows="4" class="w-full rounded-lg border-gray-300">{{ old('message') }}</textarea>
            <button class="rounded-lg bg-gray-900 px-5 py-2.5 text-sm font-semibold text-white">Send reply</button>
        </form>
    @endif

    <h2 class="mt-8 text-xl font-semibold">Your support cases</h2>
    @forelse($cases as $case)
        <section class="mt-4 rounded-2xl border border-gray-200 bg-white p-6">
            <h3 class="font-semibold">Case #{{ $case->id }} · {{ \App\Models\OrderIssue::CATEGORIES[$case->category] }}</h3>
            <p class="mt-1 text-sm text-gray-600">{{ \App\Models\OrderIssue::STATUSES[$case->status] }} · {{ $case->created_at->format('d M Y') }}</p>
            <p class="mt-2 text-xs text-gray-500">Latest 50 public messages. Closing a case does not confirm a refund or replacement.</p>
            @foreach($case->messages()->where('is_internal', false)->latest('id')->limit(50)->get()->reverse() as $message)
                <div class="mt-4 border-t border-gray-100 pt-4">
                    <p class="text-xs font-semibold text-gray-500">{{ match($message->author_type) { 'customer' => 'You', 'staff' => 'Shop team', default => 'Case update' } }} · {{ $message->created_at->copy()->timezone(config('commerce.delivery_timezone'))->format('d M Y H:i') }} (South Africa)</p>
                    <p class="mt-2 whitespace-pre-wrap text-sm text-gray-800">{{ $message->body }}</p>
                </div>
            @endforeach
        </section>
    @empty
        <p class="mt-3 text-sm text-gray-500">No support cases yet.</p>
    @endforelse
    <nav aria-label="Support history pages" class="mt-5 flex gap-5 text-sm">
        @if($cases->currentPage() > 1)<a class="underline" href="{{ URL::temporarySignedRoute('order-support.show', now()->addMinutes(30), ['order' => $order->id, 'page' => $cases->currentPage() - 1]) }}">Newer cases</a>@endif
        @if($cases->hasMorePages())<a class="underline" href="{{ URL::temporarySignedRoute('order-support.show', now()->addMinutes(30), ['order' => $order->id, 'page' => $cases->currentPage() + 1]) }}">Older cases</a>@endif
    </nav>
</div>
@endsection
