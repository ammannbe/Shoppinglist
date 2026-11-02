<x-guest-layout :title="__('Register')">
    {{-- An account at the OIDC provider also works here: the first login creates the account (AUTH_OIDC_REGISTRATION). --}}
    @if (config('login.oidc.enabled') && config('login.oidc.registration'))
        <a href="{{ route('oidc.redirect') }}" class="button-secondary mb-3 flex w-full items-center justify-center gap-2">
            {{ __('Register with :provider', ['provider' => App\Auth\OidcLabel::get()]) }}
        </a>
        <x-oidc-signup class="mb-6" />

        <p class="mb-6 flex items-center gap-3 text-sm text-stone-500">
            <span class="h-px flex-1 bg-stone-200"></span>
            {{ __('or') }}
            <span class="h-px flex-1 bg-stone-200"></span>
        </p>
    @endif

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
