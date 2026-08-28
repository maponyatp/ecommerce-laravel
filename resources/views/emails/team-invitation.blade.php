<x-mail::message>
# You’re invited

You have been invited to join the {{ $invitation->team->name }} team.

@if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::registration()))
If you do not have an account yet, create one before accepting this invitation.

<x-mail::button :url="route('register')">Create account</x-mail::button>
@endif

<x-mail::button :url="$acceptUrl">Accept invitation</x-mail::button>

If you were not expecting this invitation, you can ignore this email.

<x-slot:subcopy>
[Accept your invitation]({{ $acceptUrl }})
</x-slot:subcopy>
</x-mail::message>
