{{--
    Opening balances — what the workshop already had on the day the books
    opened. M11.

    ## The §2A flow

    The module opens on the **declaration** — the paste-or-upload box is the
    create surface — and every import ever run sits behind one switch control
    beside the heading (`resources/js/workspace.js`). The position the workshop
    stands at travels with the form rather than with the list, because it is what
    somebody about to declare their whole financial history needs in front of
    them: an owner who knows their business is worth six lakh and sees two here
    has found their own mistake before anybody had to point at it.

    ## Two buttons, and never one

    Checking a file writes nothing; posting it commits a workshop's whole
    financial history. The post button stays disabled until the preview has run
    against the text *currently* in the box, so an edit made after a preview
    cannot be committed on the strength of the preview it invalidated. That is
    not UX politeness — it is the whole safety property of this module, and it
    survives the conversion unchanged.

    ## The column guide is not written here

    `#column-guide` is empty in the markup and filled from
    GET /opening-balances/meta. A copy of the parser's vocabulary in this
    template is a copy that drifts, and the drift shows up as instructions that
    produce a refused file.
--}}
<div class="mx-auto max-w-[1280px]">

    {{-- Level 1, form mode — where the module lands (§2A.1). --}}
    <div data-ws-form>

    {{-- Where the workshop stands. The owner's stake is the figure to lead on:
         it is what Opening Balance Equity holds once everything is declared. --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <div class="surface px-4 py-3">
            <span class="block text-xs uppercase tracking-wide text-muted-foreground">Owner's stake at go-live</span>
            <span class="mt-1 block font-mono text-xl font-semibold text-foreground" id="stat-stake">—</span>
            <span class="mt-0.5 block text-xs text-muted-foreground">Assets declared, less what was owed</span>
        </div>
        <div class="surface px-4 py-3">
            <span class="block text-xs uppercase tracking-wide text-muted-foreground">Books open on</span>
            <span class="mt-1 block text-xl font-semibold text-foreground" id="stat-books-start">—</span>
            <span class="mt-0.5 block text-xs text-muted-foreground">Set on the workshop settings screen</span>
        </div>
        <div class="surface px-4 py-3">
            <span class="block text-xs uppercase tracking-wide text-muted-foreground">Declarations posted</span>
            <span class="mt-1 block text-xl font-semibold text-foreground" id="stat-posted">—</span>
            <span class="mt-0.5 block text-xs text-muted-foreground">Opening transactions in the books</span>
        </div>
    </div>

    {{-- The trial balance, stated plainly. It always reconciles — every opening
         line is posted against Opening Balance Equity — and saying so is the
         point: an owner about to declare their whole financial history needs to
         know that getting a figure wrong cannot break the books, only misstate
         them. --}}
    <div id="reconciliation" class="mb-4 hidden"></div>

    {{-- `form-card` rather than `p-4`, because the padding was never the point:
         it is the two lines that make this a `form-surface`, so the grid inside
         counts its columns against this panel's 1246px instead of falling back
         to the two the media query gives a surface it cannot measure. The
         padding is the same figure either way (`--pad-surface`). --}}
    <div class="surface form-card mb-4" id="declare-panel" data-requires-permission="UPDATE:WORKSPACE">
        <h3 class="text-[0.9375rem] font-semibold text-foreground">Declare what you had</h3>

        <p class="mt-1 text-[0.8125rem] text-muted-foreground">
            Save your existing stock list, customer balances and supplier balances as a CSV and paste it
            below — or type the rows in by hand. Nothing is posted until you have seen exactly what will be.
        </p>

        <form id="opening-form" class="mt-4 space-y-4" novalidate>
            <div class="form-grid">
                <label class="block">
                    <span class="field-label">As at</span>
                    <input type="date" name="date" id="opening-date" class="field-input">
                    <span class="mt-1 block text-xs text-muted-foreground">
                        Defaults to the day the books open. Nothing can be dated before it.
                    </span>
                </label>

                <label class="block">
                    <span class="field-label">File name <span class="text-muted-foreground">(optional)</span></span>
                    <input type="text" name="filename" id="opening-filename" class="field-input"
                           placeholder="opening-balances.csv" maxlength="255">
                    <span class="mt-1 block text-xs text-muted-foreground">
                        Kept on the record, so you can tell which file a figure came from later.
                    </span>
                </label>
            </div>

            <label class="block">
                <span class="field-label">Rows</span>
                <textarea name="csv" id="opening-csv" rows="10" spellcheck="false"
                          class="field-input font-mono text-[0.8125rem]"
                          placeholder="kind,name,variant,type,quantity,unit_cost,amount,account"></textarea>
            </label>

            {{-- The column guide is filled from GET /opening-balances/meta rather
                 than written here, so the instructions cannot drift from the rules
                 the parser and the resolver actually apply. --}}
            <div id="column-guide" class="rounded-[10px] border border-border bg-secondary/30 p-3 text-[0.8125rem]"></div>

            <div class="flex flex-wrap items-center gap-2">
                <button type="button" id="load-sample" class="btn btn-ghost btn-sm">Show me an example</button>

                <span class="flex-1"></span>

                {{-- Two buttons and never one. Previewing writes nothing; importing
                     commits a workshop's whole financial history, and that must
                     never be something that happened by omission. --}}
                <button type="submit" id="preview-opening" class="btn btn-secondary">Check it</button>
                <button type="button" id="import-opening" class="btn btn-primary" disabled>Post these balances</button>
            </div>
        </form>
    </div>

    {{-- The preview. The same object the import commits, rendered — not a second
         reading of the file that could disagree with what lands. --}}
    <div id="preview-panel" class="surface hidden overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-3">
            <h3 class="text-[0.9375rem] font-semibold text-foreground">What will be posted</h3>
            <p id="preview-summary" class="text-[0.8125rem] text-muted-foreground"></p>
        </div>

        <div id="preview-totals" class="grid gap-px bg-border sm:grid-cols-2 lg:grid-cols-5"></div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[860px] border-collapse">
                <thead>
                    <tr class="border-b border-border bg-secondary/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <th class="px-4 py-3 font-semibold">Row</th>
                        <th class="px-4 py-3 font-semibold">Declares</th>
                        <th class="px-4 py-3 font-semibold">In the file</th>
                        <th class="px-4 py-3 font-semibold">Resolved to</th>
                        <th class="px-4 py-3 text-right font-semibold">Quantity</th>
                        <th class="px-4 py-3 text-right font-semibold">Amount</th>
                        <th class="px-4 py-3 font-semibold">Outcome</th>
                    </tr>
                </thead>
                <tbody id="preview-rows"></tbody>
            </table>
        </div>
    </div>

    </div>{{-- /data-ws-form --}}

    {{-- Level 1, list mode — every import ever run. A receipt for a decision,
         not a position: the trial balance on the form is what says whether the
         position is right. --}}
    <div data-ws-list>
        <div class="surface overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] border-collapse">
                    <thead>
                        <tr class="border-b border-border bg-secondary/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <th class="px-4 py-3 font-semibold">When</th>
                            <th class="px-4 py-3 font-semibold">File</th>
                            <th class="px-4 py-3 text-right font-semibold">Posted</th>
                            <th class="px-4 py-3 text-right font-semibold">Already declared</th>
                            <th class="px-4 py-3 text-right font-semibold">Declared value</th>
                            <th class="px-4 py-3 font-semibold">By</th>
                        </tr>
                    </thead>
                    <tbody id="history-rows"></tbody>
                </table>
            </div>
        </div>
    </div>{{-- /data-ws-list --}}
</div>
