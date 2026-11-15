{{-- Announcement of the AccountManager (App\Mail\AnnouncementMail); body_html is sanitized there. Same file in
     Shoppinglist and Chorelist. --}}
<x-mail::message>
# {{ $title }}

@if ($imageUrl)
<img src="{{ $imageUrl }}" alt="" style="max-width: 100%; height: auto; border-radius: 6px;">

@endif
{!! $bodyHtml !!}

@if ($actionUrl)
<x-mail::button :url="$actionUrl">
{{ $actionText }}
</x-mail::button>
@endif

<x-slot:subcopy>
{{ __('You get this email because of your settings in :app.', ['app' => config('app.name')]) }} [{{ __('Unsubscribe from news by email') }}]({{ $unsubscribeUrl }})
</x-slot:subcopy>
</x-mail::message>
