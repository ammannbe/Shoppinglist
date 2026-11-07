<x-guest-layout :title="$household->name">
    <h2 class="mb-1 text-lg font-semibold">{{ $household->name }}</h2>
    <p class="mb-6 text-sm text-stone-600">{{ $role === 'admin' ? __('You are an admin of this household.') : __('You are a member of this household.') }}</p>

    @unless (App\Models\Household::managedLocally())
        <p class="notice mb-6">{{ __('Households are managed in your account at the provider. Here they can only be viewed.') }}</p>
    @endunless

    @can('update', $household)
        <section class="mb-8">
            <form method="POST" action="{{ route('households.update', $household) }}">
                @csrf
                @method('PATCH')
                <x-input :label="__('Name')" name="name" :value="$household->name" required maxlength="255" />
                <button type="submit" class="button-secondary w-full">{{ __('Rename') }}</button>
            </form>
        </section>
    @endcan

    <section class="mb-8">
        <h2 class="mb-2 text-lg font-semibold">{{ __('Members') }}</h2>
        <ul class="divide-y divide-stone-200 rounded-lg border border-stone-200 bg-white">
            @foreach ($members as $member)
                @php($isMe = $member->is(auth()->user()))
                <li class="px-4 py-3">
                    <span class="block break-all font-medium">{{ $member->email }}@if ($isMe) <span class="font-normal text-stone-500">({{ __('you') }})</span>@endif</span>
                    <span class="block text-xs text-stone-500">{{ $member->pivot->role === 'admin' ? __('Admin') : __('Member') }}</span>
                    <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm">
                        @if (! $isMe)
                            @can('manageMembers', $household)
                                <form method="POST" action="{{ route('households.members.update', [$household, $member]) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="role" value="{{ $member->pivot->role === 'admin' ? 'member' : 'admin' }}">
                                    <button class="underline">{{ $member->pivot->role === 'admin' ? __('Make member') : __('Make admin') }}</button>
                                </form>
                                <form method="POST" action="{{ route('households.members.destroy', [$household, $member]) }}"
                                    onsubmit="return confirm(@js(__('Remove :email from the household?', ['email' => $member->email])))">
                                    @csrf
                                    @method('DELETE')
                                    <button class="text-red-700 underline">{{ __('Remove') }}</button>
                                </form>
                            @endcan
                        @else
                            @can('manageMembers', $household)
                                @if ($member->pivot->role === 'admin' && $members->where('pivot.role', 'admin')->count() > 1)
                                    <form method="POST" action="{{ route('households.members.update', [$household, $member]) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="role" value="member">
                                        <button class="underline">{{ __('Make member') }}</button>
                                    </form>
                                @endif
                            @endcan
                            @can('leave', $household)
                                <form method="POST" action="{{ route('households.members.destroy', [$household, $member]) }}"
                                    onsubmit="return confirm(@js(__('Leave this household? You lose access to the lists shared with it.')))">
                                    @csrf
                                    @method('DELETE')
                                    <button class="text-red-700 underline">{{ __('Leave household') }}</button>
                                </form>
                            @endcan
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </section>

    @can('manageMembers', $household)
        <section class="mb-8">
            <h2 class="mb-2 text-lg font-semibold">{{ __('Invite') }}</h2>
            @if ($invitations->isNotEmpty())
                <ul class="mb-4 divide-y divide-stone-200 rounded-lg border border-stone-200 bg-white">
                    @foreach ($invitations as $invitation)
                        <li class="flex items-center gap-3 px-4 py-3">
                            <span class="min-w-0 flex-1">
                                <span class="block break-all">{{ $invitation->email }}</span>
                                <span class="block text-xs text-stone-500">
                                    {{ $invitation->role === 'admin' ? __('Admin') : __('Member') }} ·
                                    {{ __('Invited, valid until :date', ['date' => $invitation->expires_at->locale(app()->getLocale())->isoFormat('LL')]) }}
                                </span>
                            </span>
                            <form method="POST" action="{{ route('households.invitations.destroy', [$household, $invitation]) }}">
                                @csrf
                                @method('DELETE')
                                <button class="text-sm text-red-700 underline">{{ __('Withdraw') }}</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
            <form method="POST" action="{{ route('households.invitations.store', $household) }}">
                @csrf
                <x-input :label="__('Invite by email')" name="email" type="email" required autocomplete="email" />
                <label class="mb-4 block">
                    <span class="label">{{ __('Role') }}</span>
                    <select name="role" class="field w-full">
                        <option value="member" @selected(old('role') !== 'admin')>{{ __('Member') }}</option>
                        <option value="admin" @selected(old('role') === 'admin')>{{ __('Admin (invites and removes members)') }}</option>
                    </select>
                </label>
                <x-button>{{ __('Invite') }}</x-button>
            </form>
        </section>
    @endcan

    <section class="mb-8">
        <h2 class="mb-2 text-lg font-semibold">{{ __('Shared lists') }}</h2>
        @if ($lists->isNotEmpty())
            <ul class="mb-2 divide-y divide-stone-200 rounded-lg border border-stone-200 bg-white">
                @foreach ($lists as $list)
                    <li class="px-4 py-3">
                        <a href="/#/list/{{ $list->id }}" class="block truncate font-medium underline">{{ $list->name }}</a>
                        <span class="block truncate text-xs text-stone-500">{{ __('Shared by :email', ['email' => $list->owner->email]) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
        <p class="text-sm text-stone-600">{{ __('Share your lists with the household in the app with "Share".') }}</p>
    </section>

    @can('delete', $household)
        <section class="mb-8">
            <form method="POST" action="{{ route('households.destroy', $household) }}"
                onsubmit="return confirm(@js(__('Delete this household? The lists stay with their owners, only the sharing ends.')))">
                @csrf
                @method('DELETE')
                <button class="text-sm text-red-700 underline">{{ __('Delete household') }}</button>
            </form>
        </section>
    @endcan

    <p class="text-sm"><a class="underline" href="{{ route('households.index') }}">{{ __('Back') }}</a></p>
</x-guest-layout>
