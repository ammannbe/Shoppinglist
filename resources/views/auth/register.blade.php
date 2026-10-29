<x-guest-layout :title="__('Register')">
    <form method="POST" action="{{ route('register') }}">
        @csrf
        <x-input :label="__('Email')" name="email" type="email" required autofocus autocomplete="username" />
        <x-input :label="__('Password')" name="password" type="password" required autocomplete="new-password" />
        <x-input :label="__('Confirm Password')" name="password_confirmation" type="password" required autocomplete="new-password" />
        <x-button>{{ __('Register') }}</x-button>
    </form>

    <p class="mt-6 text-sm">
        <a class="underline" href="{{ route('login') }}">{{ __('Already registered?') }}</a>
    </p>
</x-guest-layout>
