<x-guest-layout :title="__('Confirm Password')">
    <p class="mb-6 text-sm">{{ __('Please confirm your password before continuing.') }}</p>

    <form method="POST" action="{{ route('password.confirm.store') }}">
        @csrf
        <x-input :label="__('Password')" name="password" type="password" required autofocus autocomplete="current-password" />
        <x-button>{{ __('Confirm') }}</x-button>
    </form>

    <button type="button" data-passkey data-passkey-confirm hidden class="button-secondary mt-3 flex w-full items-center justify-center gap-2">
        <x-passkey-icon />
        {{ __('Confirm with a passkey') }}
    </button>

    <p class="mt-6 text-sm">
        <a class="underline" href="{{ route('account') }}">{{ __('Back') }}</a>
    </p>
</x-guest-layout>
