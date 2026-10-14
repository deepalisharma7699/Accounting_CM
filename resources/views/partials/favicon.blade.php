{{--
    The tab icon, for the shopfront and the application alike: the stator mark
    from the site header on its copper tile. The SVG is what current browsers
    use; favicon.ico is for the ones that ask for /favicon.ico by name, and the
    touch icon is what a phone puts on its home screen.
--}}
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
