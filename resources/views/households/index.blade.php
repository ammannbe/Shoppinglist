<x-guest-layout :title="__('Households')">
    <h2 class="mb-2 text-lg font-semibold">{{ __('Households') }}</h2>
    <p class="mb-6 text-sm text-stone-600">{{ __('Share lists with a household instead of single people: all members see them, new members too, and whoever leaves loses access.') }}</p>

    @unless (App\Models\Household::managedLocally())
        <p class="notice mb-6">
            {{ __('Households are managed in “:name”. Here they can only be viewed.', ['name' => App\Households\AccountManagerLabel::get()]) }}
            @if ($accountManagerUrl)
                <a class="underline" href="{{ $accountManagerUrl }}">{{ __('Manage households in “:name”', ['name' => App\Households\AccountManagerLabel::get()]) }}</a>
            @endif
        </p>
    @endunless

    @if ($households->isNotEmpty())
        <div class="mb-8 space-y-3">
            @foreach ($households as $household)
                @include('households.household', ['open' => $households->count() === 1 || $open === $household->uuid])
            @endforeach
        </div>
    @else
        <p class="mb-8 text-sm text-stone-500">{{ __('You are not a member of a household yet.') }}</p>
    @endif

    @can('create', App\Models\Household::class)
        <section class="mb-10">
            <h2 class="mb-4 text-lg font-semibold">{{ __('New household') }}</h2>
            <form method="POST" action="{{ route('households.store') }}">
                @csrf
                <x-input :label="__('Name')" name="name" required maxlength="255" :placeholder="__('e.g. Family Muster')" />
                <x-button>{{ __('Create household') }}</x-button>
            </form>
        </section>
    @endcan

    <p class="text-sm"><a class="underline" href="/#/settings">{{ __('Back to the app') }}</a></p>

    {{-- Links and redirects point to #household-<uuid>: open that household. --}}
    <script>
        const openHousehold = () => {
            const household = location.hash.startsWith('#household-') && document.getElementById(location.hash.slice(1));
            if (household instanceof HTMLDetailsElement) household.open = true;
        };
        openHousehold();
        addEventListener('hashchange', openHousehold);
    </script>
</x-guest-layout>
