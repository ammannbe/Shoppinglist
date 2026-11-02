@php
    $oidc = config('login.oidc.enabled');
    // Without the local login only the OIDC button is shown; "?local=1" shows the form for emergency logins.
    $local = config('login.local.enabled') || ! $oidc || request()->boolean('local');
@endphp
<x-guest-layout :title="__('Log in')">
    @if ($oidc)
        <a href="{{ route('oidc.redirect') }}" class="{{ $local ? 'button-secondary' : 'button' }} mb-6 flex w-full items-center justify-center gap-2">
            {{ __('Log in with :provider', ['provider' => App\Auth\OidcLabel::get()]) }}
        </a>
        <x-oidc-signup class="-mt-3 mb-6" />

        @if ($local)
            <p class="mb-6 flex items-center gap-3 text-sm text-stone-500">
                <span class="h-px flex-1 bg-stone-200"></span>
                {{ __('or') }}
                <span class="h-px flex-1 bg-stone-200"></span>
            </p>
        @endif
    @endif

    @if ($local)
        <form method="POST" action="{{ route('login') }}">
            @csrf
            {{-- Stay logged in, the app has to sync after long offline periods. --}}
            <input type="hidden" name="remember" value="1">
            <x-input :label="__('Email')" name="email" type="email" required autofocus autocomplete="username webauthn" />
            <x-input :label="__('Password')" name="password" type="password" required autocomplete="current-password" />
            <x-button>{{ __('Log in') }}</x-button>
        </form>

        @if (config('login.local.enabled'))
            <button type="button" data-passkey data-passkey-login hidden class="button-secondary mt-3 flex w-full items-center justify-center gap-2">
                <x-passkey-icon />
                {{ __('Log in with a passkey') }}
            </button>
        @endif

        <p class="mt-6 flex justify-between text-sm">
            <a class="underline" href="{{ route('password.request') }}">{{ __('Forgot your password?') }}</a>
            @if (Route::has('register'))
                <a class="underline" href="{{ route('register') }}">{{ __('Register') }}</a>
            @endif
        </p>
    @endif
    <p class="mt-4 text-center text-sm">
        <a class="underline" href="/">{{ __('Use without account') }}</a>
    </p>
</x-guest-layout>
