<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Dashboard') · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{-- data-page selects which module resources/js/app.js boots. --}}
<body class="h-full" data-page="@yield('page', 'dashboard')">

    {{-- The chrome, mounted once. Nothing below ever replaces it. --}}
    @include('partials.topbar')

    <main class="host">
        @yield('content')
    </main>

    {{--
        Level 3, mounted once for the whole application.

        It used to be included by every screen that needed it, which put a second
        `#confirm-modal` in the document the moment two of those screens were
        open at the same time — and with modules now mounted into one shell, they
        always are. `confirmAction()` in ui.js resolves one id, so there is one
        node.
    --}}
    @include('partials.confirm-modal')

    {{--
        The workshop's own copy of an invoice, waiting to be printed.

        A direct child of `body`, and it has to be: the print rule in app.css
        keeps whichever child of `body` contains the invoice and hides the rest,
        which is what makes the document the only thing on the paper without
        naming every piece of chrome that must not be. Nested one level deeper
        it would be hidden along with whatever it was nested inside.

        Empty and hidden until Print is pressed, when `components/invoice-
        document.js` paints it from `GET /transactions/{id}/invoice` and the
        browser's print dialog opens over it. Printing therefore never leaves
        the page (§1.1) and opens no second window for a pop-up blocker to
        swallow.

        The same partial the customer's page at /i/{token} includes. Neither
        copy may fork it — see the partial.
    --}}
    <div id="invoice-print" role="document" aria-hidden="true">
        @include('partials.invoice-document')
    </div>

    <div id="toast-host" class="toast-host" aria-live="polite"></div>

</body>
</html>
