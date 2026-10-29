<x-guest-layout :title="__('Log in')">
    <form method="POST" action="{{ route('login') }}">
        @csrf
        {{-- Stay logged in, the app has to sync after long offline periods. --}}
        <input type="hidden" name="remember" value="1">
        <x-input :label="__('Email')" name="email" type="email" required autofocus autocomplete="username webauthn" />
        <x-input :label="__('Password')" name="password" type="password" required autocomplete="current-password" />
        <x-button>{{ __('Log in') }}</x-button>
    </form>

    <button type="button" data-passkey data-passkey-login hidden class="button-secondary mt-3 flex w-full items-center justify-center gap-2">
        <x-passkey-icon />
        {{ __('Log in with a passkey') }}
    </button>

    <p class="mt-6 flex justify-between text-sm">
        <a class="underline" href="{{ route('password.request') }}">{{ __('Forgot your password?') }}</a>
        <a class="underline" href="{{ route('register') }}">{{ __('Register') }}</a>
    </p>
    <p class="mt-4 text-center text-sm">
        <a class="underline" href="/">{{ __('Use without account') }}</a>
    </p>
</x-guest-layout>
