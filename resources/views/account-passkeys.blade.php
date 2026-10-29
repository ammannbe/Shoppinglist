<x-guest-layout :title="__('Passkeys')">
    <h2 class="mb-2 text-lg font-semibold">{{ __('Passkeys') }}</h2>
    <p class="mb-6 text-sm text-stone-600">{{ __('Log in with Face ID, Touch ID, Windows Hello or your device PIN instead of a password.') }}</p>

    @if ($passkeys->isNotEmpty())
        <ul class="mb-6 divide-y divide-stone-200 rounded-lg border border-stone-200 bg-white">
            @foreach ($passkeys as $passkey)
                <li class="flex items-center gap-3 px-4 py-3">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium">{{ $passkey->name }}</span>
                        <span class="block text-xs text-stone-500">
                            {{ $passkey->last_used_at
                                ? __('Last used :date', ['date' => $passkey->last_used_at->locale(app()->getLocale())->isoFormat('LL')])
                                : __('Added :date', ['date' => $passkey->created_at->locale(app()->getLocale())->isoFormat('LL')]) }}
                        </span>
                    </span>
                    <form method="POST" action="{{ route('passkey.destroy', $passkey) }}" onsubmit="return confirm(@js(__('Remove this passkey?')))">
                        @csrf
                        @method('DELETE')
                        <button class="text-sm text-red-700 underline">{{ __('Remove') }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif

    <p data-passkey-unsupported class="notice mb-4">{{ __('This browser does not support passkeys.') }}</p>

    <form data-passkey data-passkey-register hidden>
        <x-input :label="__('Name (optional)')" name="name" :placeholder="__('e.g. iPhone')" maxlength="255" />
        <x-button>{{ __('Add passkey') }}</x-button>
    </form>

    <p class="mt-6 text-sm"><a class="underline" href="{{ route('account') }}">{{ __('Back') }}</a></p>
</x-guest-layout>
