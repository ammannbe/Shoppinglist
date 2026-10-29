{{--
    Description and link previews (Open Graph, X/Twitter). Fixed German texts: the app shell
    is cached by the service worker for every language, and link previews are crawled
    without a user. Image: public/og-image.jpg (1200×630).
--}}
@php($description = 'Einkaufslisten mit Familie und WG teilen, Artikel schnell erfassen und auf allen Geräten synchron halten – funktioniert auch ohne Internet.')
<meta name="description" content="{{ $description }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ config('app.name') }}">
<meta property="og:title" content="{{ config('app.name') }} – gemeinsame Einkaufslisten, auch offline">
<meta property="og:description" content="{{ $description }}">
<meta property="og:url" content="{{ url('/') }}">
<meta property="og:locale" content="de_CH">
<meta property="og:image" content="{{ url('/og-image.jpg') }}">
<meta property="og:image:type" content="image/jpeg">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="{{ config('app.name') }}: eine geteilte Einkaufsliste mit Milch, Mehl und Eiern">
<meta name="twitter:card" content="summary_large_image">
