<x-guest-layout :title="__('Account')">
    <p class="mb-6 text-sm text-stone-600">{{ __('Logged in as :email', ['email' => auth()->user()->email]) }}</p>

    <section class="mb-10">
        <h2 class="mb-4 text-lg font-semibold">{{ __('Change password') }}</h2>

        @if ($errors->updatePassword->any())
            <ul class="mb-4 rounded-lg bg-red-100 px-4 py-3 text-sm text-red-800">
                @foreach ($errors->updatePassword->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        <form method="POST" action="{{ route('user-password.update') }}">
            @csrf
            @method('PUT')
            <input type="text" name="email" value="{{ auth()->user()->email }}" autocomplete="username" hidden>
            <x-input :label="__('Current Password')" name="current_password" type="password" required autocomplete="current-password" />
            <x-input :label="__('New Password')" name="password" type="password" required autocomplete="new-password" />
            <x-input :label="__('Confirm Password')" name="password_confirmation" type="password" required autocomplete="new-password" />
            <x-button>{{ __('Change password') }}</x-button>
        </form>
    </section>

    <section class="mb-10">
        <h2 class="mb-2 text-lg font-semibold">{{ __('Passkeys') }}</h2>
        <p class="mb-4 text-sm text-stone-600">{{ __('Log in with Face ID, Touch ID, Windows Hello or your device PIN instead of a password.') }}</p>
        <a href="{{ route('account.passkeys') }}" class="button-secondary flex w-full items-center justify-center gap-2">
            <x-passkey-icon />
            {{ trans_choice('{0} Set up passkey|{1} Manage passkey (:count)|[2,*] Manage passkeys (:count)', $passkeyCount, ['count' => $passkeyCount]) }}
        </a>
    </section>

    <p class="text-sm"><a class="underline" href="/#/settings">{{ __('Back to the app') }}</a></p>
</x-guest-layout>
