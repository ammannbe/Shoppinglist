{!! $title !!}

{!! $bodyText !!}
@if ($actionUrl)

{!! $actionText !!}: {!! $actionUrl !!}
@endif

--
{!! __('You get this email because of your settings in :app.', ['app' => config('app.name')]) !!}
{!! __('Unsubscribe from news by email') !!}: {!! $unsubscribeUrl !!}
