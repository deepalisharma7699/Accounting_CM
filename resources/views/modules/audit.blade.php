{{--
    The trail — M13. Who changed what, and when, across the whole workshop.

    **Read-mostly, so it opens on its list.** §2A.10, and the plainest case of it
    after Stock: nothing is created here and nothing can be. Entries arrive
    through model events on the Auditable trait, and the model refuses an UPDATE
    and a DELETE — there is no POST, PATCH or DELETE anywhere in the API group,
    so there is no verb that could put a claim on the trail or take one off it.
    `mountWorkspace(..., { canCreate: false })` is what says so: the module lands
    straight on the table and the workspace paints no "Show list" switch.

    **There is no detail modal, and there must not be one.** An entry *is* its
    detail — the changed fields are shown inline on the row that describes them.
    A modal would put one click between somebody and the only thing they came to
    read.

    **The heading is the workspace's**, not this file's. The subtitle it paints
    says what the screen is; the note below says what it deliberately leaves out,
    which is a different question and the one this screen provokes most.
--}}
<div class="mx-auto max-w-[1280px]">

    {{-- Level 1, and the only surface this module has. --}}
    <div data-ws-list>

    <p class="mb-6 max-w-[70ch] text-[0.9375rem] text-muted-foreground">
        This covers the records underneath the figures — your accounts, parties, catalogue, people
        and settings — because those are the ones that can change quietly. A posted transaction
        cannot be edited or deleted at all, so it needs no entry here.
    </p>

<div class="surface mb-4 flex flex-wrap items-center gap-3 p-3">
    <div class="relative min-w-56 flex-1">
        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">
            <x-icon name="search" :size="17" />
        </span>
        <input type="search" id="filter-search" class="field-input pl-9"
               placeholder="Search by record or person" aria-label="Search the history">
    </div>

    {{-- Options come from GET /audit-logs/meta rather than being written here:
         the list of things that can be changed grows with every module, and a
         copy in the browser would silently stop offering the newest one. --}}
    <select id="filter-resource" class="field-input w-auto min-w-44" aria-label="Kind of record">
        <option value="">Everything</option>
    </select>

    <select id="filter-action" class="field-input w-auto min-w-40" aria-label="What happened">
        <option value="">Any change</option>
    </select>

    {{-- Built from the trail, not from the user list. Somebody who has left the
         workshop still has a history, and a filter built from current users
         could not select them. --}}
    <select id="filter-actor" class="field-input w-auto min-w-44" aria-label="Who made the change">
        <option value="">Anyone</option>
    </select>

    <label class="flex items-center gap-2 text-[0.8125rem] text-muted-foreground">
        From
        <input type="date" id="filter-from" class="field-input w-auto" aria-label="From date">
    </label>

    <label class="flex items-center gap-2 text-[0.8125rem] text-muted-foreground">
        To
        <input type="date" id="filter-to" class="field-input w-auto" aria-label="To date">
    </label>

    <button type="button" id="clear-filters" class="btn btn-ghost btn-sm">Clear</button>
</div>

<div class="surface overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full min-w-[860px] border-collapse">
            <thead>
                <tr class="border-b border-border bg-secondary/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <th class="px-4 py-3 font-semibold">When</th>
                    <th class="px-4 py-3 font-semibold">Record</th>
                    <th class="px-4 py-3 font-semibold">Change</th>
                    <th class="px-4 py-3 font-semibold">Who</th>
                </tr>
            </thead>
            <tbody id="audit-rows"></tbody>
        </table>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
        <p id="audit-summary" class="text-[0.8125rem] text-muted-foreground"></p>

        <div class="flex gap-2">
            <button type="button" id="page-prev" class="btn btn-secondary btn-sm" disabled>Previous</button>
            <button type="button" id="page-next" class="btn btn-secondary btn-sm" disabled>Next</button>
        </div>
    </div>
</div>

    </div>
</div>
