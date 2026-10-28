<x-guest-layout :title="__('Log in')">
    <form method="POST" action="{{ route('login') }}">
        @csrf
        {{-- Stay logged in, the app has to sync after long offline periods. --}}
        <input type="hidden" name="remember" value="1">
        <x-input :label="__('Email')" name="email" type="email" required autofocus autocomplete="username" />
        <x-input :label="__('Password')" name="password" type="password" required autocomplete="current-password" />
        <x-button>{{ __('Log in') }}</x-button>
    </form>

    <p class="mt-6 flex justify-between text-sm">
        <a class="underline" href="{{ route('password.request') }}">{{ __('Forgot your password?') }}</a>
        <a class="underline" href="{{ route('register') }}">{{ __('Register') }}</a>
    </p>
    <p class="mt-4 text-center text-sm">
        <a class="underline" href="/">{{ __('Use without account') }}</a>
    </p>
</x-guest-layout>
