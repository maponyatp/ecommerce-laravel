@if(! auth()->user()->hasVerifiedEmail())
    <p class="mb-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
        To view previous guest purchases, <a href="{{ route('verification.notice') }}" class="font-semibold underline">verify your email address</a>.
        Orders placed while signed in remain linked to your account.
    </p>
@endif
