{{-- Link to create an account at the OIDC provider (AUTH_OIDC_SIGNUP_URL). --}}
@if (config('login.oidc.signup_url'))
    <p {{ $attributes->merge(['class' => 'text-center text-sm text-stone-600']) }}>
        {{ __('No :provider account yet?', ['provider' => App\Auth\OidcLabel::get()]) }}
        <a class="underline" href="{{ config('login.oidc.signup_url') }}">{{ __('Create one') }}</a>
    </p>
@endif
