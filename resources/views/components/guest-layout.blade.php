@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' – ' : '' }}{{ config('app.name') }}</title>
    <meta name="theme-color" content="#047857">
    @include('partials.favicon')
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-dvh bg-stone-50 text-stone-900 antialiased">
    <main class="mx-auto flex min-h-dvh max-w-sm flex-col justify-center px-6 py-12">
        <a href="/" class="mb-8 flex flex-col items-center gap-3">
            <img src="/web-app-manifest-192x192.png" alt="" class="size-16 rounded-2xl">
            <span class="text-2xl font-semibold">{{ config('app.name') }}</span>
        </a>

        @if (session('status'))
            <p class="mb-4 rounded-lg bg-green-100 px-4 py-3 text-sm text-green-800">
                {{ session('status') === 'verification-link-sent'
                    ? __('A new verification link has been sent to the email address you provided during registration.')
                    : session('status') }}
            </p>
        @endif

        @if ($errors->any())
            <ul class="mb-4 rounded-lg bg-red-100 px-4 py-3 text-sm text-red-800">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        {{ $slot }}

        <form method="POST" action="{{ route('locale.update') }}" class="mt-10 flex justify-center gap-4 text-sm text-stone-500">
            @csrf
            @method('PUT')
            @foreach (config('app.locales') as $code => $label)
                <button name="locale" value="{{ $code }}" @class(['font-semibold text-stone-900' => app()->getLocale() === $code, 'underline' => app()->getLocale() !== $code])>
                    {{ $label }}
                </button>
            @endforeach
        </form>
    </main>
</body>
</html>
