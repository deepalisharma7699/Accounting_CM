{{--
    The shopfront's shell.
    ======================================================================

    Wraps both public pages — the home page and a service page. Everything a
    visitor sees before signing in is inside this, and nothing behind the
    sign-in is: the layout deliberately does not extend layouts/app.blade.php,
    because that one boots the module shell, the permission gating and the
    topbar, none of which belong on a page opened by somebody with no account.

    Content comes from App\Support\Site, never from config or the language
    files directly, so the English and Hindi pages are one site rendered twice
    rather than two sites that drift apart.
--}}
@php
    use App\Support\Site;

    $locale = Site::locale();
    $alternates = Site::alternates($route['name'], $route['params']);
    $current = collect($alternates)->firstWhere('current', true);
    $services = $services ?? Site::services();
@endphp
<!DOCTYPE html>
<html lang="{{ config('shop.locales.'.$locale.'.html') }}" class="h-full scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0a0c10">

    <title>{{ $meta['title'] }}</title>
    <meta name="description" content="{{ $meta['description'] }}">

    {{--
        One canonical per language, and each page pointing at its translation.

        Without these the two languages are two URLs carrying the same shop,
        which is the definition of duplicate content. With them they are one
        page published twice, and a search in Devanagari can be answered with
        the Hindi one.
    --}}
    <link rel="canonical" href="{{ $current['url'] }}">
    @foreach ($alternates as $alternate)
        <link rel="alternate" hreflang="{{ $alternate['html'] }}" href="{{ $alternate['url'] }}">
    @endforeach
    <link rel="alternate" hreflang="x-default" href="{{ Site::url($route['name'], $route['params'], 'en') }}">

    {{-- What a link to this page looks like when it is pasted into WhatsApp,
         which is how most of this shop's customers will ever receive it. --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ Site::shop('name') }}">
    <meta property="og:locale" content="{{ str_replace('-', '_', $current['html']) }}">
    <meta property="og:title" content="{{ $meta['title'] }}">
    <meta property="og:description" content="{{ $meta['description'] }}">
    <meta property="og:url" content="{{ $current['url'] }}">
    <meta name="twitter:card" content="summary">

    {{--
        The two typefaces, self-hosted.

        `@vite` does not emit these on its own — the fonts the build downloads
        are a second manifest, and `Vite::fonts()` is what turns it into preload
        links and the @font-face block. Without this line the page renders in
        whatever the device has, which on the Hindi half means Nirmala UI on
        Windows, something else on Android, and tofu on a machine with neither.

        Both families are named so the preloads cover both, and every face
        carries a `unicode-range`: a browser fetches the Devanagari files only
        when it has Devanagari to draw, so the English page pays nothing for
        them. Self-hosted rather than pulled from a CDN, which is the same
        reason there is no embedded map on the contact section — no request
        leaves this origin when the page loads.
    --}}
    {{ Vite::fonts(['inter', 'noto-sans-devanagari']) }}

    {{-- The shopfront's own stylesheet, not the application's. The two share
         only the tokens and the primitives in resources/css/shared.css. --}}
    @vite(['resources/css/site.css', 'resources/js/app.js'])

    {{--
        Structured data.

        The LocalBusiness record is what puts the address, the opening hours
        and "Open now" under the shop's name in a search result — worth more to
        a business whose customers search from a field on a phone than any
        section on the page. Nothing unverified goes into it; see
        Site::localBusinessSchema() for what is deliberately left out.
    --}}
    @foreach ($schemas as $schema)
        <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @endforeach

    {{-- Without JavaScript nothing arrives, so nothing may be hidden waiting
         to. This is the whole no-JS story for the site: every section is
         present, styled and readable, minus the motion. --}}
    <noscript>
        <style>[data-reveal] { opacity: 1 !important; transform: none !important; }</style>
    </noscript>
</head>

{{-- data-page selects which module resources/js/app.js boots. --}}
<body class="min-h-full bg-paper text-ink-900 antialiased" data-page="site">

@include('site.partials.header')

<main id="top" class="s-sections">
    @yield('content')
</main>

@include('site.partials.footer')
@include('site.partials.action-bar')
@include('site.partials.login-modal')

</body>
</html>
