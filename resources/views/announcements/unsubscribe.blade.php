{{-- Signed unsubscribe link of the announcement mails (AnnouncementUnsubscribeController), works without login. --}}
<x-guest-layout :title="__('News by email')">
    @if ($done)
        <p class="mb-6 rounded-lg bg-green-100 px-4 py-3 text-sm text-green-800">
            {{ __('You will no longer get news by email from :app, also no maintenance notices.', ['app' => config('app.name')]) }}
        </p>
        <p class="text-sm text-stone-600">{{ __('You can turn them on again in the settings of the app.') }}</p>
    @else
        <p class="mb-6 text-sm">
            {{ __('Unsubscribe :email from news by email (including maintenance notices)?', ['email' => $user->email]) }}
        </p>
        <form method="POST" action="{{ request()->fullUrl() }}">
            @csrf
            <x-button>{{ __('Unsubscribe') }}</x-button>
        </form>
    @endif
    <a href="/" class="mt-6 block text-center text-sm underline">{{ __('Open :app', ['app' => config('app.name')]) }}</a>
</x-guest-layout>
