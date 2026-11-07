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
        <ul class="mb-8 divide-y divide-stone-200 overflow-hidden rounded-lg border border-stone-200 bg-white">
            @foreach ($households as $household)
                <li>
                    <a href="{{ route('households.show', $household) }}" class="flex items-center gap-3 px-4 py-3 hover:bg-stone-50">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{ $household->name }}</span>
                            <span class="block text-xs text-stone-500">
                                {{ trans_choice('{1} :count member|[2,*] :count members', $household->members_count, ['count' => $household->members_count]) }}
                                @if ($household->pivot->role === 'admin') · {{ __('Admin') }} @endif
                            </span>
                            @if (! App\Models\Household::managedLocally() && $household->account_manager_household_uuid === null)
                                <span class="block text-xs text-stone-500">
                                    {{ __('Only in this app, not managed in “:name”.', ['name' => App\Households\AccountManagerLabel::get()]) }}
                                    @if ($household->account_manager_import_status === 'pending')
                                        {{ __('Proposed for “:name”, waiting for confirmation.', ['name' => App\Households\AccountManagerLabel::get()]) }}
                                    @elseif ($household->account_manager_import_status === 'rejected')
                                        {{ __('Not taken over into “:name”.', ['name' => App\Households\AccountManagerLabel::get()]) }}
                                    @endif
                                </span>
                            @endif
                        </span>
                        <span aria-hidden="true">›</span>
                    </a>
                </li>
            @endforeach
        </ul>
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
</x-guest-layout>
