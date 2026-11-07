<x-guest-layout :title="__('Invitation')">
    @if ($invitation)
        <h2 class="mb-2 text-lg font-semibold">{{ $invitation->household->name }}</h2>
        <p class="mb-6 text-sm text-stone-600">
            {{ __(':email invited you to the household ":household". Lists shared with the household are shared with all its members.', [
                'email' => $invitation->inviter?->email ?? '?',
                'household' => $invitation->household->name,
            ]) }}
        </p>
    @endif

    @switch ($state)
        @case ('acceptable')
            <form method="POST" action="{{ route('households.invitations.accept', $token) }}">
                @csrf
                <x-button>{{ __('Join household') }}</x-button>
            </form>
            @break
        @case ('guest')
            <p class="mb-4 text-sm">{{ __('Log in or register with :email to accept the invitation.', ['email' => $invitation->email]) }}</p>
            <a href="{{ route('login') }}" class="button mb-3 flex w-full items-center justify-center">{{ __('Log in') }}</a>
            @if (Route::has('register'))
                <a href="{{ route('register') }}" class="button-secondary flex w-full items-center justify-center">{{ __('Register') }}</a>
            @endif
            @break
        @case ('other_account')
            <p class="notice">{{ __('This invitation is for :email. Log out and log in with this address to accept it.', ['email' => $invitation->email]) }}</p>
            @break
        @case ('member')
            <p class="mb-4 text-sm">{{ __('You are already a member of this household.') }}</p>
            <a href="{{ route('households.show', $invitation->household) }}" class="button flex w-full items-center justify-center">{{ __('Open household') }}</a>
            @break
        @case ('accepted')
            <p class="notice">{{ __('This invitation has already been accepted.') }}</p>
            @break
        @case ('expired')
            <p class="notice">{{ __('This invitation has expired. Ask for a new one.') }}</p>
            @break
        @case ('read_only')
            <p class="notice">{{ __('Households are managed in your account at the provider. Here they can only be viewed.') }}</p>
            @break
        @default
            <p class="notice">{{ __('This invitation is not valid.') }}</p>
    @endswitch

    <p class="mt-6 text-sm"><a class="underline" href="/">{{ __('Back to the app') }}</a></p>
</x-guest-layout>
