<x-guest-layout :title="__('Forgot your password?')">
    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <x-input :label="__('Email')" name="email" type="email" required autofocus autocomplete="username" />
        <x-button>{{ __('Email Password Reset Link') }}</x-button>
    </form>

    <p class="mt-6 text-sm">
        <a class="underline" href="{{ route('login') }}">{{ __('Log in') }}</a>
    </p>
</x-guest-layout>
