<x-guest-layout :title="__('Verify Email Address')">
    <p class="mb-6 text-sm">
        {{ __('Before continuing, please verify your email address by clicking the link we just emailed to you.') }}
    </p>

    <form method="POST" action="{{ route('verification.send') }}" class="mb-4">
        @csrf
        <x-button>{{ __('Resend Verification Email') }}</x-button>
    </form>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="w-full text-sm underline">{{ __('Log Out') }}</button>
    </form>
</x-guest-layout>
