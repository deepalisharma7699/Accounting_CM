
{{--
    Insights — M23. What the numbers mean, as opposed to what they are.

    **Read-mostly, so it opens on its list** (§2A.10). There is no level-1 create
    form and therefore no "Show list" switch: nothing is created here, and
    `mountWorkspace(..., { canCreate: false })` is what says so. Stock is the
    worked example this follows; the frame — the heading, the Escape handling,
    the URL sync — is the shared one and not this module's to reinvent.

    **One card, two kinds of question.** The first six tabs are *insight*: is
    anything wrong, and where should I look. The last four are M12's
    *statements*: the day book, the P&L, the GST summary and the parked drafts,
    each answering "what is the figure" for somebody who already knows which
    figure they want. They are one module because they are the same act at two
    zoom levels, and because a second card called Analytics would leave somebody
    guessing which of two screens has sales-by-month (§5.1).

    Nothing here re-implements a statement. The four statement tabs still fetch
    `GET /reports/*`, which is exactly what they fetched before — a second URL
    for one answer is a second thing to keep in step, and the second one always
    drifts.

    **One period, every tab.** Somebody who has just seen a gross margin they did
    not expect wants the day book *without* re-choosing the window they were
    looking at. The period survives every tab switch; nothing else does.

    **The markup here is a frame, not a report.** Every figure arrives from the
    guarded /api/v1 endpoints once the page module runs — this shell is public
    HTML, so a workshop's numbers were never allowed into it.
--}}
<div class="mx-auto max-w-[1280px]">

    {{-- Level 1, and the only surface this module has. --}}
    <div data-ws-list>

        {{-- The period, and which question is being asked of it.

             Presets come from GET /insights/meta rather than being written here,
             because "this financial year" depends on the workshop's own
             year-start setting — a copy in the client would be right until
             somebody changed it. --}}
        <div class="surface mb-4 flex flex-wrap items-center gap-3 p-3">
            <label class="flex items-center gap-2 text-[0.8125rem] text-muted-foreground">
                <x-icon name="clock" :size="14" />
                <select id="filter-period" class="field-input h-9 w-auto min-w-44" aria-label="Period"></select>
            </label>

            <label class="flex items-center gap-2 text-[0.8125rem] text-muted-foreground" data-custom-dates>
                From
                <input type="date" id="filter-from" class="field-input h-9 w-auto" aria-label="From date">
            </label>

            <label class="flex items-center gap-2 text-[0.8125rem] text-muted-foreground" data-custom-dates>
                To
                <input type="date" id="filter-to" class="field-input h-9 w-auto" aria-label="To date">
            </label>

            <span class="flex-1"></span>

            {{-- What the screen is actually covering, stated rather than left to
                 be inferred from a dropdown the reader may not have looked at.
                 It also names the window every delta is measured against, which
                 is otherwise invisible. --}}
            <p id="period-label" class="text-[0.8125rem] text-muted-foreground"></p>

            {{-- Exports whatever the open tab is showing, over the whole period
                 rather than the rows on screen. Gated on nothing extra: anybody
                 who can read the panel can save it. --}}
            <button type="button" id="export-csv" class="btn btn-secondary btn-sm h-9">
                <x-icon name="download" :size="14" />
                Export
            </button>
        </div>

        {{-- Ten tabs in two groups, separated by a rule rather than by a second
             strip: they are one row of choices about one period, and stacking
             them would imply the period belonged to only one group.

             Scrollable rather than wrapped — a strip that reflows to two lines
             moves every tab under the pointer when the window is resized. --}}
        <div class="tab-strip mb-5" id="insight-tabs" role="tablist" aria-label="Insights and statements">
            <button type="button" class="tab" data-tab="overview" role="tab" aria-selected="true">
                <x-icon name="gauge" :size="15" />
                Overview
            </button>
            <button type="button" class="tab" data-tab="sales" role="tab" aria-selected="false">
                <x-icon name="receipt" :size="15" />
                Sales
            </button>
            <button type="button" class="tab" data-tab="purchase" role="tab" aria-selected="false">
                <x-icon name="shopping-cart" :size="15" />
                Purchase
            </button>
            <button type="button" class="tab" data-tab="stock" role="tab" aria-selected="false">
                <x-icon name="layers" :size="15" />
                Stock
            </button>
            <button type="button" class="tab" data-tab="credit" role="tab" aria-selected="false">
                <x-icon name="credit-card" :size="15" />
                Money owed
            </button>

            {{-- Hidden unless the session holds READ:STAFF. Presentation only —
                 the endpoint behind it requires the grant as well, and it is a
                 privacy line rather than a convenience: what each person earns
                 is not something the clerk at the counter needs. --}}
            <button type="button" class="tab hidden" data-tab="people" role="tab" aria-selected="false"
                    data-requires-permission="READ:STAFF">
                <x-icon name="id-card" :size="15" />
                People
            </button>

            <span class="mx-1 h-5 w-px shrink-0 bg-border" aria-hidden="true"></span>

            <button type="button" class="tab" data-tab="day-book" role="tab" aria-selected="false">
                Day book
            </button>
            <button type="button" class="tab" data-tab="profit-and-loss" role="tab" aria-selected="false">
                Profit &amp; loss
            </button>
            <button type="button" class="tab" data-tab="gst" role="tab" aria-selected="false">
                GST
            </button>
            <button type="button" class="tab" data-tab="drafts" role="tab" aria-selected="false">
                Parked drafts
            </button>
        </div>

        {{-- Said once, at the top, for a workshop that has posted nothing.
             Every panel below would otherwise draw correctly around no data,
             which reads as a broken screen rather than an empty one. --}}
        <div id="insight-empty" class="surface mb-4 hidden items-center gap-3 p-4">
            <span class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-blue-50 text-blue-600">
                <x-icon name="info" :size="16" />
            </span>
            <p class="text-[0.8125rem] text-muted-foreground">
                <span class="font-semibold text-foreground">Nothing has been posted yet.</span>
                These figures fill in as bills, purchases and expenses are entered — none of it
                is stored anywhere, so every panel is worked out from the documents as you ask for it.
            </p>
        </div>

        {{-- Where every panel renders. One host rather than ten, because exactly
             one tab is ever on screen and ten hidden subtrees would be ten
             copies of the accessibility tree for a reader to walk past. --}}
        <div id="insight-panel" role="tabpanel"></div>

    </div>{{-- /data-ws-list --}}
</div>
