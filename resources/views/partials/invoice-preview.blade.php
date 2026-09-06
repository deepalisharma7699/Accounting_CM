{{--
| The customer's copy, on screen — the surface a posted sale lands on.
|
| ## Why it is in the layout and not in the Sales fragment
|
| It holds the one invoice sheet while somebody is looking at it, and the shell
| caches a module's root **detached** when another module opens. Declared inside
| `modules/sales.blade.php` this drawer would leave the document with it, taking
| the application's only `[data-invoice-document]` out of the page — and the
| next thing anybody printed, from anywhere, would come out blank. Mounted here
| it is a body child for the whole life of the session, exactly like
| `#invoice-print` beside it and for the same reason.
|
| ## There is one sheet, and it is borrowed
|
| This drawer renders **no invoice markup of its own.** `pages/sales.js` moves
| the single node out of `#invoice-print` into `[data-invoice-preview-sheet]`
| while the preview is open, and puts it back before anything prints — the
| `adoptForm()` pattern, for the reason `workspace.js` uses it: a second copy of
| a document is a second document.
|
| That is not tidiness. The print rule in app.css keeps whichever child of
| `body` *contains* the invoice and hides every other one, so a second
| `[data-invoice-document]` anywhere under `<main>` would make `<main>` a thing
| worth keeping — and every print from then on would carry the whole application
| around the invoice. One node means the rule stays true by construction rather
| than by everybody remembering it.
|
| The title, the subtitle and the footer are written by `pages/sales.js`: what
| can still be done to a document depends on what it is, and the grants differ
| between printing it and publishing it.
--}}
<div id="invoice-preview" class="drawer-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="invoice-preview-title">
    <div class="drawer-panel max-w-[900px]">

        <header class="flex items-start justify-between gap-3 border-b border-muted px-6 py-4">
            <div class="flex min-w-0 items-center gap-2.5">
                <span class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-emerald-50
                             text-emerald-600">
                    <x-icon name="check-circle" :size="16" />
                </span>
                <div class="min-w-0">
                    <h3 id="invoice-preview-title"
                        class="truncate text-[15.5px] font-bold leading-tight text-foreground"
                        data-invoice-preview-title></h3>
                    <p class="truncate text-xs text-muted-foreground" data-invoice-preview-subtitle></p>
                </div>
            </div>

            <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                <x-icon name="x" :size="16" />
            </button>
        </header>

        {{-- A tinted well, so the sheet reads as a piece of paper on a desk
             rather than as more of the drawer. --}}
        <div class="flex-1 overflow-y-auto bg-muted px-4 py-5 sm:px-6">
            <p class="hidden py-8 text-center text-sm text-muted-foreground" data-invoice-preview-status></p>

            {{-- The borrowed sheet lands here. Empty in the markup, always. --}}
            <div data-invoice-preview-sheet></div>
        </div>

        <footer class="flex flex-wrap items-center gap-2 border-t border-muted px-6 py-4"
                data-invoice-preview-actions></footer>

    </div>
</div>
