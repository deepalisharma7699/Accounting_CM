{{--
| The customer's copy of one invoice — M20.
|
| The only page in the application anybody at all may open, and the only one
| that renders a workshop's own records without a session. What holds it shut is
| in routes/web.php and PublicInvoiceController; what is here is the page.
|
| ## Why this is not layouts/app
|
| That layout loads `app.js`, whose first act on a page it does not recognise is
| to redeem the refresh cookie and redirect to /login. A customer with no
| account would be bounced to a sign-in form for the crime of opening the link
| they were sent. It also mounts the topbar, the confirmation modal and the
| toast host — an application's chrome around somebody else's document.
|
| So the page is written out here, and its script is the small entry in
| `resources/js/invoice.js`.
|
| ## Variables
|
|   $invoice  the array from InvoiceDocumentService, embedded below.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $invoice['document']['doc_no'] ?? $invoice['document']['heading'] }} · {{ $invoice['workshop']['name'] }}</title>

    {{--
        Kept out of search engines, twice — here and as an `X-Robots-Tag` header
        on the response, so it still holds for a crawler that only read the
        headers. The link is unguessable, but a customer who pastes it somewhere
        public should not find their name, address and GSTIN indexed, and the
        workshop is the one who would be asked why.
    --}}
    <meta name="robots" content="noindex, nofollow, noarchive">

    {{-- No og:description and no og:image. A link pasted into a chat should
         preview as a document from a workshop, not unfurl the customer's
         address and what they were charged into a group thread. --}}
    <meta property="og:title" content="{{ $invoice['document']['heading'] }} · {{ $invoice['workshop']['name'] }}">
    <meta property="og:type" content="website">

    @vite(['resources/css/app.css', 'resources/js/invoice.js'])
</head>

<body class="min-h-full bg-background antialiased">

    {{-- The page's own frame is screen furniture: on paper it would centre a
         52rem sheet inside the width of an A4 page and pad it again on top of
         the page margin, so the customer's copy would come out narrower than
         the workshop's copy of the same invoice. `.invoice-sheet` drops its own
         frame in the print rules for the same reason; this drops the one around
         it. --}}
    <main class="mx-auto max-w-[52rem] px-4 py-6 sm:py-10 print:m-0 print:max-w-none print:p-0">

        {{-- The actions, above the document rather than below it: on a phone the
             sheet is several screens long, and a Print button under it is one
             nobody scrolls to. Hidden on paper — see the print rules. --}}
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 print:hidden">
            <p class="text-[0.8125rem] text-muted-foreground">
                Shared by {{ $invoice['workshop']['name'] }}
            </p>

            <div class="flex flex-wrap gap-2">
                {{-- Revealed by resources/js/invoice.js only where the platform
                     actually has a share sheet. On a desktop browser the copy
                     button beside it is the honest answer and is already here. --}}
                <button type="button" class="btn btn-secondary btn-sm" data-invoice-share hidden>
                    <x-icon name="share-2" :size="15" />
                    Share
                </button>

                <button type="button" class="btn btn-secondary btn-sm" data-invoice-copy>
                    <x-icon name="link" :size="15" />
                    Copy link
                </button>

                <button type="button" class="btn btn-primary btn-sm" data-invoice-print>
                    <x-icon name="printer" :size="15" />
                    Print
                </button>
            </div>
        </div>

        {{-- The same partial the workshop's own print copy uses. --}}
        @include('partials.invoice-document')

    </main>

    {{-- Toasts, for the one thing on this page that can fail: copying the link.
         Mounted here rather than imported from the application's layout, because
         nothing else in that layout belongs on this page. --}}
    <div id="toast-host" class="toast-host" aria-live="polite"></div>

    {{--
        The document itself, embedded.

        One request, and the invoice is either in the page or the page did not
        load — there is no second fetch to fail halfway on a shop's wifi. It is
        the identical array `GET /transactions/{id}/invoice` returns to the
        workshop's own print copy, and `components/invoice-document.js` paints
        both from it.

        `@json` escapes for a script context, so a customer whose name contains
        `</script>` gets an invoice rather than a broken page.
    --}}
    <script type="application/json" id="invoice-payload">@json($invoice)</script>

</body>
</html>
