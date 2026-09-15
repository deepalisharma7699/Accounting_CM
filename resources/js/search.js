/**
 * The topbar's search box — the one way into a record from anywhere.
 *
 * ## Why there is a global one at all
 *
 * Every module already has a search box over its own list, and each of them
 * answers a question you have to be standing in that module to ask. The box in
 * the chrome answers the question somebody at the counter actually has: a
 * customer is on the telephone quoting a number, and which module it belongs to
 * — an invoice, a job ticket, a receipt, a name in the book — is the part they
 * do not know. Until now the box was markup with a ⌘K shortcut behind it and no
 * handler at all, which is worse than not offering one: it invites the question
 * and then swallows it.
 *
 * ## It opens the record where the record lives (§1.4)
 *
 * This is a way *in*, never a fifth list. Picking a result opens that module in
 * the mounted shell with a deep link — `#customers?party=12`, `#sales?doc=88` —
 * and the module opens its own drawer over its own rows. Nothing is rendered
 * here that a module already renders, so a change to how an invoice reads is
 * made once (§4.4, §5.1).
 *
 * ## Five sources, and no new endpoint
 *
 * The four server-side sources are the modules' own index endpoints with their
 * own `search` parameter, asked for six rows apiece. A `GET /search` would have
 * been one round trip instead of four, and it would also have been a second
 * place that decides what a workshop may read — the whole point of asking these
 * four is that each already refuses what this session may not see, server side
 * (§6.1, §6.2). The grant is checked here too, but only so a source nobody may
 * read is never *asked for*; the refusal is the server's either way.
 *
 * Three things keep the fan-out honest. It is **debounced** and asks for nothing
 * under two characters. It passes **`quiet: true`** — the one use of that flag
 * outside the pickers and the price preview, and for the same stated reason: it
 * runs on a debounce as somebody types, and it reports itself in the panel,
 * which is where the eye already is (§3.8). And a **sequence number** discards
 * every response but the current term's, so a slow answer to "ra" never lands
 * under "ramesh".
 *
 * ## The groups are fixed slots
 *
 * Four requests settle in whatever order the network decides. If the panel were
 * built from the responses as they arrived, the list would reshuffle under the
 * pointer and Enter would open whatever had just been pushed under it. So the
 * order below is the order on screen, always: a group that has not answered yet
 * is simply absent from its slot, and fills it when it answers.
 *
 * ## The one thing it will not do
 *
 * Offer a result it cannot open. `canOpenModule()` is the shell's own gate, so a
 * document whose module is switched off, or whose card this session may not see,
 * is dropped rather than shown and then refused on the click.
 */

import auth from './auth-client';
import { can } from './permissions';
import { canOpenModule, openModule } from './shell';
import {
    $, $$, debounce, esc, formatDate, formatMoney,
} from './ui';

/** Nothing is asked of the server below this, and modules match from one. */
const MIN_SERVER_TERM = 2;

/** Rows per source. Enough to recognise the one you meant, short enough to scan. */
const PER_SOURCE = 6;

/**
 * Which module owns a document, and therefore where a result opens.
 *
 * The list is also the filter sent to the endpoint (`types[]`), so a type with
 * no home here — a stock adjustment, an opening declaration, a payroll run — is
 * never fetched rather than fetched and then dropped. Each of those is reached
 * from the module that writes it, and none of them is a thing anybody searches
 * for by number.
 */
const DOCUMENT_MODULE = {
    sale: 'sales',
    sales_return: 'sales',
    purchase: 'purchase',
    purchase_return: 'purchase',
    expense: 'bills',
    receipt: 'journal',
    payment: 'journal',
    journal: 'journal',
};

/** Transactions lands on a tab, so a document says which one it is on. */
const JOURNAL_TABS = ['receipt', 'payment', 'journal'];

function documentLink(doc) {
    const tab = JOURNAL_TABS.includes(doc.type) ? `tab=${doc.type}&` : '';

    return `${tab}doc=${doc.id}`;
}

/**
 * Which of the two counterparty screens holds this record.
 *
 * A party who buys *and* sells is one row with one combined ledger and appears
 * on both lists, so either would be right; Customers is chosen because it is
 * the list that record is most often looked up from. The drawer it opens is the
 * same drawer, showing both sides of the position, whichever way in was taken.
 */
function partyModule(party) {
    return party.is_customer ? 'customers' : 'vendors';
}

function partyRole(party) {
    if (party.is_customer && party.is_vendor) return 'Customer and vendor';

    return party.is_vendor ? 'Vendor' : 'Customer';
}

/* -------------------------------------------------------------------------
 | The sources
 |
 | Data rather than branching, for the reason `pages/counterparty.js` gives
 | about its two screens: every `if (source === …)` in the body below would be a
 | place two sources could drift apart. A sixth source is an entry here.
 | ---------------------------------------------------------------------- */

const SOURCES = [
    {
        key: 'modules',
        heading: 'Go to',

        /*
        | Read off the grid, exactly as the shell reads its breadcrumb labels: a
        | copy of config/modules.php written into JavaScript would be a second
        | registry to keep in step, and the first symptom of the drift would be
        | this panel calling a module something its card did not.
        |
        | Hidden cards are skipped, which is the permission pass already done —
        | and `canOpenModule()` below is the same answer asked of the shell, so
        | the two cannot disagree.
        */
        local: (term) => $$('[data-module-card]')
            .filter((card) => !card.classList.contains('hidden'))
            .map((card) => ({
                key: $('[data-open]', card)?.dataset.open,
                label: $('.card-title', card)?.textContent.trim() ?? '',
                description: $('.card-desc', card)?.textContent.trim() ?? '',
            }))
            .filter(({ key, label }) => key && (
                label.toLowerCase().includes(term) || key.includes(term)
            ))
            .map(({ key, label, description }) => ({
                id: `module:${key}`,
                title: label,
                sub: description,
                module: key,
                params: '',
            })),
    },

    {
        key: 'parties',
        heading: 'Customers and vendors',
        grant: ['READ', 'PARTIES'],
        failure: 'Customers and vendors could not be searched.',

        fetch: async (term) => {
            const query = new URLSearchParams({
                per_page: PER_SOURCE,
                search: term,
            });

            const { data } = await auth.call(`/parties?${query}`, { quiet: true });

            return (data ?? []).map((party) => ({
                id: `party:${party.id}`,
                title: party.name,
                sub: [party.phone, party.gstin].filter(Boolean).join(' · '),
                meta: partyRole(party),
                module: partyModule(party),
                params: `party=${party.id}`,
            }));
        },
    },

    {
        key: 'documents',
        heading: 'Bills and vouchers',
        grant: ['READ', 'TRANSACTIONS'],
        failure: 'Documents could not be searched.',

        fetch: async (term) => {
            const query = new URLSearchParams({
                per_page: PER_SOURCE,
                search: term,
            });

            Object.keys(DOCUMENT_MODULE).forEach((type) => query.append('types[]', type));

            const { data } = await auth.call(`/transactions?${query}`, { quiet: true });

            return (data ?? []).map((doc) => ({
                id: `doc:${doc.id}`,
                // A draft has earned no number, so it is named by what it is —
                // `doc_no` is null there rather than blank, and "Sale" over a
                // customer's name is still enough to recognise.
                title: `${doc.type_label} ${doc.doc_no ?? `#${doc.id}`}`,
                sub: [doc.party?.name, formatDate(doc.date)].filter(Boolean).join(' · '),
                meta: `₹${formatMoney(doc.total)}`,
                module: DOCUMENT_MODULE[doc.type],
                params: documentLink(doc),
            }));
        },
    },

    {
        key: 'jobs',
        heading: 'Jobs',
        grant: ['READ', 'WORKSHOP_JOBS'],
        failure: 'Jobs could not be searched.',

        fetch: async (term) => {
            const query = new URLSearchParams({
                per_page: PER_SOURCE,
                search: term,
            });

            const { data } = await auth.call(`/workshop-jobs?${query}`, { quiet: true });

            return (data ?? []).map((job) => ({
                id: `job:${job.id}`,
                // `equipment` falls back to the job number when nothing about
                // the thing was recorded, so it is only appended where it says
                // something the number has not already said.
                title: [
                    job.job_no ?? `#${job.id}`,
                    job.equipment === job.job_no ? null : job.equipment,
                ].filter(Boolean).join(' · '),
                sub: [job.party?.name, job.serial_no].filter(Boolean).join(' · '),
                meta: job.status_label,
                module: 'jobs',
                params: `job=${job.id}`,
            }));
        },
    },

    {
        key: 'items',
        heading: 'Items',
        grant: ['READ', 'ITEMS'],
        failure: 'The catalogue could not be searched.',

        fetch: async (term) => {
            const query = new URLSearchParams({
                per_page: PER_SOURCE,
                search: term,
            });

            const { data } = await auth.call(`/items?${query}`, { quiet: true });

            return (data ?? []).map((item) => ({
                id: `item:${item.id}`,
                title: item.name,
                sub: [item.code, item.brand, item.category_label].filter(Boolean).join(' · '),
                module: 'items',
                params: `item=${item.id}`,
            }));
        },
    },
];

/* -------------------------------------------------------------------------
 | State
 | ---------------------------------------------------------------------- */

let input = null;
let panel = null;

const state = {
    term: '',

    /*
    | Which run the responses in flight belong to. Four requests per keystroke
    | and a debounce that lets a slow one outlive its term: without this, the
    | answer to "ra" paints under a box reading "ramesh".
    */
    seq: 0,

    /** One entry per source, in SOURCES order — the fixed slots. */
    groups: [],

    /** Every painted row, flattened, in the order they appear. */
    rows: [],

    /*
    | The highlighted row's id — an identity, not an index, so a group landing
    | above it does not move the highlight onto a different record.
    */
    active: null,

    open: false,
};

/* -------------------------------------------------------------------------
 | Painting
 | ---------------------------------------------------------------------- */

function close() {
    state.open = false;
    state.active = null;
    panel.classList.add('hidden');
    input.setAttribute('aria-expanded', 'false');
    input.removeAttribute('aria-activedescendant');
}

function optionHtml(row, index) {
    return `
        <div id="search-option-${index}"
             class="search-option"
             role="option"
             aria-selected="${row.id === state.active}"
             data-row="${esc(row.id)}">
            <span class="search-option-title">${esc(row.title)}</span>
            ${row.sub ? `<span class="search-option-sub">${esc(row.sub)}</span>` : ''}
            ${row.meta ? `<span class="search-option-meta">${esc(row.meta)}</span>` : ''}
        </div>`;
}

/**
 * Draw whatever has arrived so far.
 *
 * Called after every source settles, so this runs three or four times per term.
 * A group with nothing in it is absent rather than shown empty: five headings
 * over one result reads as a broken screen, and "no invoices matched" is not a
 * fact worth four lines of the panel.
 */
function paint() {
    state.rows = [];

    const waiting = state.groups.some((group) => group.status === 'pending');

    const html = state.groups.map((group) => {
        if (group.status === 'failed') {
            return `
                <div class="search-group">
                    <p class="search-group-head">${esc(group.source.heading)}</p>
                    <p class="search-note" data-tone="error">${esc(group.source.failure)}</p>
                </div>`;
        }

        if (group.status !== 'ready' || !group.rows.length) return '';

        const options = group.rows.map((row) => {
            state.rows.push(row);

            return optionHtml(row, state.rows.length - 1);
        }).join('');

        return `
            <div class="search-group">
                <p class="search-group-head">${esc(group.source.heading)}</p>
                ${options}
            </div>`;
    }).join('');

    /*
    | §3.4 — every state of this is said out loud. "Searching…" while anything is
    | still in flight, the failure in its own slot above, and an empty result
    | that names the term back rather than leaving a blank panel that could as
    | easily be a broken one.
    */
    const empty = `<p class="search-note">No match for &ldquo;${esc(state.term)}&rdquo;.</p>`;
    const footer = waiting
        ? '<p class="search-note">Searching…</p>'
        : (state.rows.length ? '' : empty);

    panel.innerHTML = html + footer;

    // The highlight belongs to a record, so a row that has gone — its group
    // refetched, or the term moved on — hands it to the first row rather than
    // leaving Enter pointing at nothing.
    if (!state.rows.some((row) => row.id === state.active)) {
        state.active = state.rows.length ? state.rows[0].id : null;
    }

    paintActive();

    state.open = true;
    panel.classList.remove('hidden');
    input.setAttribute('aria-expanded', 'true');
}

/** Move the highlight without rebuilding the panel underneath the pointer. */
function paintActive() {
    let activeOption = null;

    $$('[data-row]', panel).forEach((option) => {
        const selected = option.dataset.row === state.active;

        option.setAttribute('aria-selected', String(selected));

        if (selected) activeOption = option;
    });

    if (activeOption) input.setAttribute('aria-activedescendant', activeOption.id);
    else input.removeAttribute('aria-activedescendant');

    activeOption?.scrollIntoView({ block: 'nearest' });
}

/* -------------------------------------------------------------------------
 | Running a search
 | ---------------------------------------------------------------------- */

/** A result nothing can be done with is not a result (see the docblock). */
function openable(row) {
    return Boolean(row.module) && canOpenModule(row.module);
}

/**
 * Whether this session may be *offered* a source at all.
 *
 * Presentation only, as everywhere else: each endpoint refuses on its own
 * (§6.2). It is checked so that a clerk holding no jobs grant does not sit
 * through a 403 on every keystroke.
 */
function available(source) {
    return !source.grant || can(...source.grant);
}

function run(term) {
    const seq = ++state.seq;

    state.term = term;
    state.groups = SOURCES
        .filter(available)
        .map((source) => ({ source, status: 'pending', rows: [] }));

    // Modules are matched here and now: it is a filter over markup already in
    // the document, and making somebody wait for the network to be told where
    // "Stock" is would be the panel's worst moment.
    state.groups
        .filter((group) => group.source.local)
        .forEach((group) => {
            group.rows = group.source.local(term.toLowerCase()).filter(openable);
            group.status = 'ready';
        });

    const fetching = state.groups.filter((group) => group.source.fetch);

    // Two characters. One letter matches most of the book, and four requests
    // for it is a workshop's whole ledger fetched a page at a time.
    if (term.length < MIN_SERVER_TERM) {
        fetching.forEach((group) => {
            group.status = 'ready';
        });

        paint();

        return;
    }

    paint();

    fetching.forEach(async (group) => {
        try {
            const rows = await group.source.fetch(term);

            // The term moved on while this was in flight.
            if (seq !== state.seq) return;

            group.rows = rows.filter(openable);
            group.status = 'ready';
        } catch {
            if (seq !== state.seq) return;

            /*
            | Said in the panel, never toasted. This runs on a debounce as
            | somebody types, and a network that is down would stack a red
            | banner per keystroke over the screen they are working on. The
            | other three sources still answer, and the one that did not says so
            | in its own slot.
            */
            group.status = 'failed';
        }

        paint();
    });
}

const search = debounce((term) => run(term), 250);

/* -------------------------------------------------------------------------
 | Choosing one
 | ---------------------------------------------------------------------- */

function move(step) {
    if (!state.rows.length) return;

    const at = state.rows.findIndex((row) => row.id === state.active);
    const next = (at + step + state.rows.length) % state.rows.length;

    state.active = state.rows[next].id;
    paintActive();
}

function choose(id) {
    const row = state.rows.find((entry) => entry.id === id);

    if (!row) return;

    close();

    /*
    | Cleared, not kept. This box is global chrome and the screen it sits over
    | has just changed to something it did not filter — a term left in it would
    | claim otherwise. Retyping costs one ⌘K, which selects whatever is there.
    */
    input.value = '';
    state.term = '';

    openModule(row.module, { search: row.params });
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

/**
 * Focus the box and select what is in it — what ⌘K does, and the reason it is a
 * function rather than a `.focus()` at the call site: pressing the shortcut a
 * second time should let somebody type over the last term rather than land the
 * caret in the middle of it.
 */
export function focusSearch() {
    if (!input) return;

    input.focus();
    input.select();
}

export function initSearch() {
    const host = $('[data-search-root]');

    // The chrome is shared with screens that carry no search box.
    if (!host) return;

    input = $('[data-search]', host);
    panel = $('[data-search-panel]', host);

    if (!input || !panel) return;

    /*
    | ⌘K on a Mac, Ctrl+K everywhere else, and the hint says which. It read ⌘K
    | to every reader, which on the platform this is used on names a key that is
    | not on the keyboard.
    */
    const hint = $('[data-search-hint]', host);
    const mac = /Mac|iPhone|iPad/i.test(navigator.platform || navigator.userAgent || '');

    if (hint && !mac) hint.textContent = 'Ctrl K';

    input.addEventListener('input', (event) => {
        const term = event.target.value.trim();

        if (!term) {
            // Nothing in flight may paint over an empty box.
            state.seq += 1;
            close();

            return;
        }

        search(term);
    });

    // Coming back to a box that still has something in it puts the results back
    // rather than making somebody type a character to see them again.
    input.addEventListener('focus', () => {
        if (input.value.trim() && !state.open) run(input.value.trim());
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            move(1);

            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            move(-1);

            return;
        }

        if (event.key === 'Enter') {
            event.preventDefault();

            if (state.active) choose(state.active);

            return;
        }

        /*
        | §2A.9, one level per press — and the panel is the innermost thing on
        | screen while it is up. The shell's own Escape handler stands down for
        | it (`shell.js`), so this takes the one step: the panel closes and what
        | was typed is left alone, which is the party picker's judgement about
        | the one thing nobody means by Escape. A second press, with nothing
        | left to close, hands the keyboard back to the shell.
        |
        | `preventDefault` is load-bearing and not tidiness. This is an
        | `<input type="search">`, and Escape in one of those is a *browser*
        | shortcut that empties the field — so without it the press did both
        | jobs at once and threw away the term behind the panel, which is
        | precisely what the paragraph above says must not happen. It is
        | unconditional for the same reason: a key that keeps what was typed on
        | the first press and discards it on the second is not a rule anybody
        | can hold, and the shell has already decided its own step by the time
        | this runs — it listens in the capture phase, this is a bubble.
        */
        if (event.key === 'Escape') {
            event.preventDefault();

            if (state.open) close();
            else input.blur();
        }
    });

    panel.addEventListener('click', (event) => {
        const option = event.target.closest('[data-row]');

        if (option) choose(option.dataset.row);
    });

    // Hover moves the highlight, so the mouse and the keyboard cannot end up
    // pointing at two different rows while Enter is still armed.
    panel.addEventListener('mousemove', (event) => {
        const option = event.target.closest('[data-row]');

        if (option && option.dataset.row !== state.active) {
            state.active = option.dataset.row;
            paintActive();
        }
    });

    // Anywhere outside closes it — the account menu's rule, and for the same
    // reason: a panel left open over a screen somebody has moved on to.
    document.addEventListener('click', (event) => {
        if (state.open && !event.target.closest('[data-search-root]')) close();
    });
}
