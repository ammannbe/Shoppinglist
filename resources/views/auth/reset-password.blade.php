<x-guest-layout :title="__('Reset Password')">
    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <x-input :label="__('Email')" name="email" type="email" :value="$request->email" required autocomplete="username" />
        <x-input :label="__('Password')" name="password" type="password" required autofocus autocomplete="new-password" />
        <x-input :label="__('Confirm Password')" name="password_confirmation" type="password" required autocomplete="new-password" />
        <x-button>{{ __('Reset Password') }}</x-button>
    </form>
</x-guest-layout>
