import auth from '../auth-client';
import {
    absolute, hasBalance, isAdvance, isOwing, positionFor,
} from '../components/party-position';
import { initQuickParty, openQuickParty, quickPartyForm } from '../components/quick-party';
import { can } from '../permissions';
import {
    $, $$, confirmAction, debounce, esc, formatDate, formatMoney,
    hideModal, isZeroAmount, showModal, tableMessage, toast,
} from '../ui';
import { adoptForm, mountWorkspace } from '../workspace';

/**
 * The Customers and Vendors screens, which are one screen twice.
 *
 * Three things drive the design.
 *
 * **Two screens, one table.** Both read `parties`, filtered on role
 * *membership* rather than equality. The shop that buys a rewound motor and
 * sells you scrap copper is one record holding both roles, with one combined
 * ledger, and it appears on both lists — marked "Also a vendor" so nobody
 * mistakes it for a duplicate. The alternative, one record per screen, splits a
 * single balance into two halves that are never netted or even looked at
 * together. That is the mistake the parties table exists to prevent, and
 * splitting the *screen* must not reintroduce it: the statement a row opens is
 * always the combined one, both sides of it, whichever list you came from.
 *
 * **Each screen leads with its own side of the position, and hides neither.**
 * The customer list shows the receivable, the vendor list the payable. Where a
 * counterparty has both, the other side is stated on the row too rather than
 * netted into it — "they owe you ₹40,000 and you owe them ₹38,000" is two facts
 * settled separately, and collapsing them into "₹2,000" is true and useless.
 *
 * **The list is loaded whole and filtered here.** Same reason as the catalogue:
 * the four figures above the table have to agree with the rows under them, and
 * counting "6 with outstanding" server-side while filtering a page client-side
 * is how a tile comes to disagree with its own list.
 *
 * ## The §2A flow
 *
 * The module opens on its create form and the list sits behind one switch
 * control beside the heading, fetched the first time it is asked for. The form
 * is not written twice: it is `components/quick-party.js`, moved into the
 * level-1 slot for a create and into its drawer for an edit — one set of fields,
 * one set of validation rules, wherever somebody is standing (§4.4, §5.1).
 *
 * ## Two modules, one implementation
 *
 * Customers and Vendors are separate modules with separate cards, separate
 * grants to open and separate lists — and they are built from this one file
 * because they are the same screen with different wording and the opposite side
 * of the position. Everything stateful lives inside `initCounterpartyPage`, so
 * each of them closes over its own parties, filters, surfaces and workspace; the
 * shell keeps both mounted once opened, and nothing they hold is shared.
 *
 * What is *not* duplicated is the record. A counterparty who buys and sells is
 * one row in `parties` holding both roles, on both lists, with one combined
 * ledger — see the note above.
 */

const PAGE_SIZE = 25;

// The parties endpoint caps per_page at 200. The cap below is a guard, not a
// page size — a book of counterparties past 5,000 is a different screen, and
// quietly loading it would turn a fast page into a slow one with no
// explanation. Hitting it sets `truncated`, which the summary says out loud.
const FETCH_SIZE = 200;
const MAX_PAGES = 25;

const LEDGER_PAGE_SIZE = 50;
const RECENT_DAYS = 30;

/**
 * Everything that differs between the two screens.
 *
 * Deliberately data rather than branching: every `if (customer)` in the body of
 * this module would be a place the two screens could drift apart.
 */
export const CUSTOMER = {
    role: 'customer',
    otherRole: 'vendor',
    noun: 'customer',
    nounPlural: 'customers',
    otherRoleLabel: 'Also a vendor',

    // What the shell calls this module, and what the workspace paints above it:
    // the heading, the line under it in each mode, and the control that swaps
    // them (§2A.3). `addLabel` is the same words the level-1 form card carries
    // in the markup, because they are the same act.
    key: 'customers',
    title: 'Customers',
    addLabel: 'Add customer',
    formSubtitle: 'Write a customer to the books, or show who is already on them.',
    listBlurb: 'Who buys, and what they owe.',

    // What this screen calls the name — on the column and on the record form,
    // which is shared with the bill counter and so has no word of its own.
    nameLabel: 'Customer Name',
    namePlaceholder: 'e.g. Ravi Kumar Motors',

    // Which side of the position this screen leads with, and which side of the
    // lifetime totals answers "how much has gone through".
    side: 'receivable',
    otherSide: 'payable',
    lifetimeKey: 'billed',
    settledKey: 'received',

    // Which date the "last dealt with" column reports, and which transaction
    // types the drawer's two history tabs list.
    activityKey: 'last_sale_at',
    historyTypes: ['sale'],
    paymentTypes: ['receipt'],

    outstandingLabel: 'Outstanding',
    dueLabel: 'With Outstanding',
    dueStatus: 'Outstanding',
    // The position gone the other way: they have paid more than they have been
    // billed, so the workshop is holding their money.
    advanceStatus: 'In credit',
    advanceNote: 'paid ahead',
    historyTitle: 'Sales history',
    historyEmpty: 'Nothing has been sold to them yet.',
    paymentsEmpty: 'They have not paid anything yet.',
    documentColumn: 'Invoice',
    lifetimeLabel: 'Total billed',
    settledLabel: 'Received',
    sinceLabel: 'Customer since',
    dateLabel: 'Last sale',
    createLabel: 'Create sale',
    createHref: '/bills/new?kind=sale',
};

export const VENDOR = {
    role: 'vendor',
    otherRole: 'customer',
    noun: 'vendor',
    nounPlural: 'vendors',
    otherRoleLabel: 'Also a customer',

    key: 'vendors',
    title: 'Vendors',
    addLabel: 'Add vendor',
    formSubtitle: 'Write a supplier to the books, or show who is already on them.',
    listBlurb: 'Who supplies, and what is owed.',

    nameLabel: 'Vendor Name',
    namePlaceholder: 'e.g. National Copper Wire',

    side: 'payable',
    otherSide: 'receivable',
    lifetimeKey: 'purchased',
    settledKey: 'paid',

    activityKey: 'last_purchase_at',
    // An expense counts as a purchase for the same reason the API groups them:
    // money spent with a supplier is a dealing with them, whichever voucher it
    // was written on.
    historyTypes: ['purchase', 'expense'],
    paymentTypes: ['payment'],

    outstandingLabel: 'Outstanding Payable',
    dueLabel: 'Payment Due',
    dueStatus: 'Payment Due',
    // The workshop has paid more than it has been billed, so the supplier is
    // holding the workshop's money.
    advanceStatus: 'Advance paid',
    advanceNote: 'paid ahead',
    historyTitle: 'Purchase history',
    historyEmpty: 'Nothing has been bought from them yet.',
    paymentsEmpty: 'They have not been paid anything yet.',
    documentColumn: 'Bill',
    lifetimeLabel: 'Total purchased',
    settledLabel: 'Paid',
    sinceLabel: 'Vendor since',
    dateLabel: 'Last purchase',
    createLabel: 'Create purchase bill',
    createHref: '/bills/new?kind=purchase',
};

/* -------------------------------------------------------------------------
 | One screen, built twice
 |
 | Everything below is *inside* the factory, and that is the point. Customers and
 | Vendors are two modules over one implementation, and the shell keeps both
 | mounted once each has been opened — so a single module-level `state` would
 | belong to whichever initialised last, and the two lists would read each
 | other's rows, filter each other's filters and paint each other's tables.
 |
 | `pages/customers.js` and `pages/vendors.js` call this once apiece, at import
 | time, so each ends up with a closure of its own: its own parties, its own
 | filters, its own surfaces and its own workspace. Nothing is shared but the
 | code.
 |
 | @param {typeof CUSTOMER} config
 | ---------------------------------------------------------------------- */

export function initCounterpartyPage(config) {

    /* -------------------------------------------------------------------------
     | State
     | ---------------------------------------------------------------------- */

    const state = {
        config,

        parties: [],
        truncated: false,

        search: '',
        isActive: '1',
        hasGstin: '',
        bothRoles: '',
        pill: 'all',

        sort: { column: 'name', direction: 'asc' },
        page: 1,
        lastPage: 1,

        openParty: null,
        drawerTab: 'overview',

        ledger: { partyId: null, name: '', page: 1, lastPage: 1 },
    };

    /* -------------------------------------------------------------------------
     | The surfaces
     |
     | §2A: the module is a create form with the list behind one switch control, and
     | exactly one of the two is in the DOM at a time. The other is held *detached*
     | by the workspace — so a document-scoped lookup for the table, a filter or a
     | figure finds nothing whenever the form is showing, including during the
     | repaint that follows a create.
     |
     | Everything inside the list is therefore reached through the held node, which
     | works either way: a detached element still has its own subtree.
     | ---------------------------------------------------------------------- */

    /** The workspace controller, once mounted. */
    let workspace = null;

    /** The list surface. */
    let listRoot = null;

    /** Where the create form is mounted on level 1. */
    let formSlot = null;

    const inList = (selector) => $(selector, listRoot);
    const allInList = (selector) => $$(selector, listRoot);

    /* -------------------------------------------------------------------------
     | Fetching
     | ---------------------------------------------------------------------- */

    /** Walk the paginated endpoint to the end, or to MAX_PAGES. */
    async function loadParties() {
        const rows = [];

        for (let page = 1; page <= MAX_PAGES; page += 1) {
            const query = new URLSearchParams({
                role: state.config.role,
                // Both cost a query apiece over the whole page, and both are
                // columns on this screen — so both are asked for, once.
                with_position: '1',
                with_activity: '1',
                per_page: FETCH_SIZE,
                page,
            });

            // is_active is deliberately not sent: the archived filter is applied
            // here, so switching it never costs another round trip.
            const payload = await auth.call(`/parties?${query}`);

            rows.push(...(payload.data ?? []));

            if (!payload.meta?.pagination?.has_more) {
                state.parties = rows;
                state.truncated = false;

                return;
            }
        }

        state.parties = rows;
        state.truncated = true;
    }

    /* -------------------------------------------------------------------------
     | Deriving a row
     | ---------------------------------------------------------------------- */

    /*
    | What an amount *means* is `components/party-position.js`, not this file.
    |
    | The predicates below used to live here, and the party picker on every bill
    | form now needs the same answers at the moment somebody is chosen. Three
    | lines is exactly the size of thing that gets retyped rather than imported,
    | and the one that would drift is the advance — a negative shown in the amber
    | that means "chase this" sends somebody after money the workshop is holding
    | (§4.4).
    */

    /** What this screen's side of the position comes to. */
    function owed(party) {
        return positionFor(party, state.config.side);
    }

    /** The other side, which only a counterparty holding both roles ever has. */
    function owedOther(party) {
        return positionFor(party, state.config.otherSide);
    }

    function isBoth(party) {
        return (party.roles ?? []).includes(state.config.otherRole);
    }

    function addedRecently(party) {
        const created = Date.parse(party.created_at ?? '');

        return Number.isFinite(created)
            && created >= Date.now() - RECENT_DAYS * 24 * 60 * 60 * 1000;
    }

    /**
     * The status of one counterparty.
     *
     * Archived wins over everything, because an archived party is not somebody
     * anyone is about to chase — and "Outstanding" against a name that no longer
     * appears in a picker would send somebody looking for a screen they cannot
     * reach it from.
     */
    function statusOf(party) {
        if (!party.is_active) return 'archived';
        if (isAdvance(owed(party))) return 'advance';

        return isOwing(owed(party)) ? 'due' : 'settled';
    }

    function statusBadge(party) {
        const status = statusOf(party);

        const badge = {
            settled: { label: 'Active', chip: 'border-emerald-100 bg-emerald-50 text-emerald-700', dot: 'bg-emerald-500' },
            due: { label: state.config.dueStatus, chip: 'border-amber-100 bg-amber-50 text-amber-700', dot: 'bg-amber-500' },
            // Blue, not amber: there is nothing here to chase.
            advance: { label: state.config.advanceStatus, chip: 'border-blue-100 bg-blue-50 text-blue-700', dot: 'bg-blue-500' },
            archived: { label: 'Archived', chip: 'border-border bg-muted text-muted-foreground', dot: 'bg-muted-foreground' },
        }[status];

        return `
            <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5
                         text-[11.5px] font-semibold ${badge.chip}">
                <span class="size-1.5 rounded-full ${badge.dot}"></span>
                ${badge.label}
            </span>`;
    }

    /** Two letters off the name, as the design's avatar. */
    function initials(name) {
        return String(name ?? '')
            .split(' ')
            .filter(Boolean)
            .map((word) => word[0])
            .slice(0, 2)
            .join('')
            .toUpperCase() || '?';
    }

    function money(amount) {
        return amount === null || amount === undefined ? '—' : `₹${formatMoney(amount)}`;
    }

    /* -------------------------------------------------------------------------
     | Filtering and sorting
     | ---------------------------------------------------------------------- */

    function matchesSearch(party, needle) {
        if (!needle) return true;

        return [party.name, party.phone, party.email, party.gstin]
            .some((value) => String(value ?? '').toLowerCase().includes(needle));
    }

    function matchesPill(party) {
        if (state.pill === 'all') return true;
        if (state.pill === 'recent') return addedRecently(party);

        const status = statusOf(party);

        // "Settled" answers for an advance too: there is nothing to chase either
        // way, and hiding a credit balance behind a filter nobody clicks is how it
        // goes unnoticed until the customer asks for it back. It keeps its own
        // badge in the list, so the two are still told apart on sight.
        if (state.pill === 'settled') return status === 'settled' || status === 'advance';

        // Reads the status rather than the balance, so an archived party with money
        // outstanding is not counted as somebody to chase.
        return status === state.pill;
    }

    const SORTERS = {
        name: (party) => party.name?.toLowerCase() ?? '',
        // Largest first is what somebody sorting by a debt wants, so the direction
        // is applied to a negated number rather than the sort being special-cased.
        outstanding: (party) => Number(owed(party) ?? 0),
        activity: (party) => party.activity?.[state.config.activityKey] ?? '',
        // By urgency rather than alphabetically: the point of sorting by status is
        // to bring what needs attention to the top.
        status: (party) => ({ due: 0, advance: 1, settled: 2, archived: 3 })[statusOf(party)] ?? 4,
    };

    function visibleRows() {
        const needle = state.search.toLowerCase();

        const rows = state.parties.filter((party) => {
            if (!matchesSearch(party, needle)) return false;
            if (state.isActive !== '' && party.is_active !== (state.isActive === '1')) return false;
            if (state.hasGstin !== '' && Boolean(party.gstin) !== (state.hasGstin === '1')) return false;
            if (state.bothRoles !== '' && isBoth(party) !== (state.bothRoles === '1')) return false;

            return matchesPill(party);
        });

        const pick = SORTERS[state.sort.column] ?? SORTERS.name;
        const factor = state.sort.direction === 'desc' ? -1 : 1;

        return rows.sort((a, b) => {
            const left = pick(a);
            const right = pick(b);

            if (left < right) return -1 * factor;
            if (left > right) return 1 * factor;

            // A stable tiebreak, so two settled rows do not swap places every time
            // the list is redrawn.
            return String(a.name).localeCompare(String(b.name));
        });
    }

    /* -------------------------------------------------------------------------
     | Rendering the list
     | ---------------------------------------------------------------------- */

    const COLUMNS = 7;

    function render() {
        const rows = visibleRows();

        state.lastPage = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
        state.page = Math.min(state.page, state.lastPage);

        const start = (state.page - 1) * PAGE_SIZE;
        const pageRows = rows.slice(start, start + PAGE_SIZE);

        inList('#parties-body').innerHTML = pageRows.length
            ? pageRows.map(renderRow).join('')
            : tableMessage(COLUMNS, emptyMessage());

        renderStats();
        renderSummary(rows.length);
        renderPager();
        renderSortIndicators();

        const filtered = Boolean(state.search) || state.pill !== 'all'
            || state.isActive !== '1' || state.hasGstin !== '' || state.bothRoles !== '';

        inList('#clear-filters').classList.toggle('hidden', !filtered);
        inList('#clear-filters').classList.toggle('flex', filtered);
    }

    function emptyMessage() {
        const { nounPlural, noun } = state.config;

        return state.parties.length
            ? `No ${nounPlural} match your search or filter.`
            : `No ${nounPlural} yet. Add the first ${noun} to get started.`;
    }

    function renderRow(party) {
        const balance = owed(party);
        const other = owedOther(party);
        const date = party.activity?.[state.config.activityKey] ?? null;

        const flags = [
            isBoth(party)
                ? `<span class="badge bg-accent text-accent-foreground">${state.config.otherRoleLabel}</span>`
                : '',
            party.is_active ? '' : '<span class="badge bg-muted text-muted-foreground">Archived</span>',
        ].filter(Boolean).join(' ');

        return `
            <tr class="group cursor-pointer transition hover:bg-background ${party.is_active ? '' : 'opacity-60'}"
                data-row="${party.id}" tabindex="0" role="link" aria-label="Open ${esc(party.name)}">
                <td class="px-4 py-3">
                    <div class="flex items-center gap-3">
                        <span class="grid size-8 shrink-0 place-items-center rounded-full bg-primary
                                     text-[11px] font-bold text-primary-foreground">${esc(initials(party.name))}</span>
                        <span class="min-w-0">
                            <span class="block truncate text-[13px] font-semibold text-secondary-foreground
                                         transition group-hover:text-primary">${esc(party.name)}</span>
                            ${flags
                                ? `<span class="mt-0.5 flex flex-wrap gap-1">${flags}</span>`
                                : (party.email
                                    ? `<span class="block truncate text-[11.5px] text-muted-foreground">${esc(party.email)}</span>`
                                    : '')}
                        </span>
                    </div>
                </td>

                <td class="px-4 py-3">
                    ${party.phone
                        ? `<span class="flex items-center gap-1.5 text-[13px] text-secondary-foreground">
                               ${iconPhone}${esc(party.phone)}
                           </span>`
                        : '<span class="text-[13px] text-muted-foreground">—</span>'}
                </td>

                <td class="px-4 py-3">
                    ${party.gstin
                        ? `<code class="rounded bg-muted px-2 py-0.5 font-mono text-[11.5px] text-muted-foreground">${esc(party.gstin)}</code>`
                        : '<span class="text-[13px] text-muted-foreground">—</span>'}
                </td>

                <td class="px-4 py-3">
                    ${amountCell(balance)}
                    ${hasBalance(other)
                        ? `<span class="mt-0.5 block text-[11px] text-muted-foreground">
                               ${otherSideNote(other)}
                           </span>`
                        : ''}
                </td>

                <td class="px-4 py-3">
                    ${date
                        ? `<span class="flex items-center gap-1.5 text-[12.5px] whitespace-nowrap text-muted-foreground">
                               ${iconClock}${esc(formatDate(date))}
                           </span>`
                        : '<span class="text-[12.5px] text-muted-foreground">Never</span>'}
                </td>

                <td class="px-4 py-3">${statusBadge(party)}</td>

                <td class="px-4 py-3">
                    <div class="flex justify-end">
                        <button type="button" class="btn btn-ghost btn-icon" data-menu="${party.id}"
                                aria-haspopup="true" aria-expanded="false"
                                aria-label="Actions for ${esc(party.name)}">${iconMore}</button>
                    </div>
                </td>
            </tr>`;
    }

    /**
     * This screen's side of the position, in the colour that says what to do about
     * it: amber to chase, blue for money held on their behalf, green for settled.
     */
    function amountCell(amount) {
        if (isAdvance(amount)) {
            return `
                <span class="text-[13px] font-semibold text-blue-600">${esc(money(absolute(amount)))}</span>
                <span class="mt-0.5 block text-[11px] text-blue-500">${esc(state.config.advanceNote)}</span>`;
        }

        return `
            <span class="text-[13px] font-semibold ${isOwing(amount) ? 'text-amber-600' : 'text-emerald-600'}">
                ${isOwing(amount) ? esc(money(amount)) : 'Nil'}
            </span>`;
    }

    /**
     * The other side of a dual-role counterparty's position, stated rather than
     * netted — the two are settled separately and on different terms.
     */
    function otherSideNote(amount) {
        const workshopOwes = state.config.otherSide === 'payable';

        // An advance flips who is holding whose money, so it flips the sentence too.
        if (isAdvance(amount)) {
            return `${workshopOwes ? 'You have overpaid' : 'They have overpaid'} ${esc(money(absolute(amount)))}`;
        }

        return `${workshopOwes ? 'You owe' : 'They owe'} ${esc(money(amount))}`;
    }

    /**
     * The four figures, counted over the whole list rather than the page.
     *
     * "6 with outstanding" has to still say 6 after somebody clicks it, so these
     * ignore the pill — but they respect the archived filter, because an archived
     * party is not somebody anyone is going to chase.
     */
    function renderStats() {
        const scope = state.parties.filter((party) =>
            state.isActive === '' || party.is_active === (state.isActive === '1'));

        const counts = { settled: 0, due: 0, advance: 0, archived: 0 };

        scope.forEach((party) => { counts[statusOf(party)] += 1; });

        inList('#stat-total').textContent = scope.length.toLocaleString('en-IN');
        // Advances are counted with "settled" for the same reason the filter
        // includes them: neither is somebody to chase.
        inList('#stat-settled').textContent = (counts.settled + counts.advance).toLocaleString('en-IN');
        inList('#stat-due').textContent = counts.due.toLocaleString('en-IN');
        inList('#stat-recent').textContent = scope.filter(addedRecently).length.toLocaleString('en-IN');

        allInList('[data-stat-filter]').forEach((tile) => {
            const on = state.pill === tile.dataset.statFilter;

            tile.classList.toggle('stat-tile-on', on);
            tile.querySelector('[data-stat-chevron]')?.classList.toggle('text-primary', on);
            tile.querySelector('[data-stat-chevron]')?.classList.toggle('text-border', !on);
        });
    }

    function renderSummary(matched) {
        const total = state.parties.length;
        const { nounPlural } = state.config;

        const parts = [`Showing ${matched.toLocaleString('en-IN')} of ${total.toLocaleString('en-IN')} ${nounPlural}`];

        if (matched !== total) parts.push('· Filtered');
        if (state.truncated) parts.push(`· first ${total.toLocaleString('en-IN')} loaded`);

        inList('#parties-summary').textContent = parts.join(' ');
    }

    function renderPager() {
        const host = inList('#parties-pager');

        if (state.lastPage <= 1) {
            host.innerHTML = '';

            return;
        }

        // A window around the current page, so a hundred pages do not become a
        // hundred buttons.
        const pages = new Set([1, state.lastPage, state.page]);

        for (let offset = 1; offset <= 2; offset += 1) {
            if (state.page - offset > 1) pages.add(state.page - offset);
            if (state.page + offset < state.lastPage) pages.add(state.page + offset);
        }

        const ordered = [...pages].sort((a, b) => a - b);

        host.innerHTML = ordered.map((page, index) => {
            const gap = index > 0 && page - ordered[index - 1] > 1
                ? '<span class="px-1 text-xs text-muted-foreground">…</span>'
                : '';

            const active = page === state.page;

            return `${gap}<button type="button" data-page="${page}"
                        class="size-7 rounded-[6px] text-xs font-medium transition
                               ${active ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted'}"
                        ${active ? 'aria-current="page"' : ''}>${page}</button>`;
        }).join('');
    }

    function renderSortIndicators() {
        allInList('#parties-head [data-sort]').forEach((th) => {
            const on = th.dataset.sort === state.sort.column;

            th.setAttribute('aria-sort', on
                ? (state.sort.direction === 'asc' ? 'ascending' : 'descending')
                : 'none');

            th.querySelector('[data-sort-arrow]')?.remove();

            const arrow = document.createElement('span');
            arrow.dataset.sortArrow = '';
            arrow.className = `ml-1 inline-block align-middle ${on ? 'text-primary' : 'text-border'}`;
            arrow.innerHTML = on && state.sort.direction === 'desc' ? iconArrowDown : iconArrowUp;

            th.append(arrow);
        });
    }

    /* -------------------------------------------------------------------------
     | Icons
     | ---------------------------------------------------------------------- */

    const svg = (paths, size = 16) =>
        `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor"
              stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="shrink-0">${paths}</svg>`;

    const iconPhone = svg('<path d="M13.83 19.83a16 16 0 0 1-9.66-9.66 2 2 0 0 1 .44-2.1l1.27-1.27a1 1 0 0 1 1.55.15l1.7 2.55a1 1 0 0 1-.12 1.26l-.7.7a12.5 12.5 0 0 0 4.1 4.1l.7-.7a1 1 0 0 1 1.26-.12l2.55 1.7a1 1 0 0 1 .15 1.55l-1.27 1.27a2 2 0 0 1-2.1.44"/>', 12);
    const iconClock = svg('<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>', 12);
    const iconMore = svg('<circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/>', 15);
    const iconArrowUp = svg('<path d="m5 12 7-7 7 7"/>', 11);
    const iconArrowDown = svg('<path d="m19 12-7 7-7-7"/>', 11);
    const iconEye = svg('<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>', 13);
    const iconPencil = svg('<path d="M21.17 6.83a2.83 2.83 0 0 0-4-4L3.5 16.5 2 22l5.5-1.5z"/><path d="m15 5 4 4"/>', 13);
    const iconStatement = svg('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/>', 13);
    const iconPlus = svg('<path d="M5 12h14"/><path d="M12 5v14"/>', 13);
    const iconArchive = svg('<rect x="2" y="3" width="20" height="5" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>', 13);
    const iconRestore = svg('<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M12 8v4l3 2"/>', 13);
    const iconTrash = svg('<path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>', 13);

    /* -------------------------------------------------------------------------
     | The row menu
     | ---------------------------------------------------------------------- */

    function closeMenus() {
        $$('[data-row-menu]').forEach((menu) => menu.remove());
        allInList('[data-menu]').forEach((button) => button.setAttribute('aria-expanded', 'false'));
    }

    /**
     * Open the row menu as a fixed layer on the body rather than inside the row.
     *
     * The table scrolls sideways on a narrow screen, and a container that scrolls on
     * one axis clips the other — an absolutely positioned menu inside it would be
     * cut off exactly where the last row's menu opens.
     */
    function openMenu(button, partyId) {
        const party = findParty(partyId);
        if (!party) return;

        closeMenus();

        const entries = [{ label: 'View details', icon: iconEye, action: 'open' }];

        if (can('READ', 'LEDGER')) {
            entries.push({ label: 'Statement', icon: iconStatement, action: 'statement' });
        }

        if (party.is_active && can('WRITE', 'TRANSACTIONS')) {
            entries.push({ label: state.config.createLabel, icon: iconPlus, action: 'create' });
        }

        if (can('UPDATE', 'PARTIES')) {
            entries.push({ label: `Edit ${state.config.noun}`, icon: iconPencil, action: 'edit' });
            entries.push(party.is_active
                ? { label: `Archive ${state.config.noun}`, icon: iconArchive, action: 'archive' }
                : { label: `Restore ${state.config.noun}`, icon: iconRestore, action: 'restore' });
        }

        if (can('DELETE', 'PARTIES')) {
            entries.push({ label: `Delete ${state.config.noun}`, icon: iconTrash, action: 'delete', danger: true });
        }

        const menu = document.createElement('div');
        menu.className = 'row-menu';
        menu.dataset.rowMenu = '';
        menu.setAttribute('role', 'menu');

        menu.innerHTML = entries.map((entry) => `
            <button type="button" role="menuitem" class="row-menu-item"
                    data-action="${entry.action}" data-id="${party.id}"
                    ${entry.danger ? 'data-danger' : ''}>
                ${entry.icon}
                ${esc(entry.label)}
            </button>`).join('');

        // Measured off-screen first: the height decides whether it opens down or up,
        // and asking for it before the browser has laid it out returns zero.
        menu.style.position = 'fixed';
        menu.style.visibility = 'hidden';
        document.body.append(menu);

        const rect = button.getBoundingClientRect();
        const height = menu.offsetHeight;
        const below = window.innerHeight - rect.bottom;

        menu.style.top = below < height + 8
            ? `${Math.max(8, rect.top - height - 4)}px`
            : `${rect.bottom + 4}px`;

        menu.style.left = `${Math.max(8, rect.right - menu.offsetWidth)}px`;
        menu.style.right = 'auto';
        menu.style.visibility = '';

        button.setAttribute('aria-expanded', 'true');
    }

    function findParty(id) {
        return state.parties.find((row) => String(row.id) === String(id)) ?? null;
    }

    /* -------------------------------------------------------------------------
     | The drawer
     | ---------------------------------------------------------------------- */

    function openDrawer(partyId, { tab = 'overview' } = {}) {
        // Read from the loaded list rather than refetched: it already holds the
        // party, its position and its dates, and a spinner for something already in
        // memory is a spinner for nothing.
        const party = findParty(partyId);
        if (!party) return;

        state.openParty = party;
        state.drawerTab = tab;

        const balance = owed(party);

        $('#drawer-initials').textContent = initials(party.name);
        $('#drawer-title').textContent = party.name;
        $('#drawer-subtitle').textContent = [
            `${state.config.sinceLabel} ${party.created_at ? formatDate(party.created_at) : 'unknown'}`,
            isBoth(party) ? state.config.otherRoleLabel.toLowerCase() : '',
        ].filter(Boolean).join(' · ');

        $('#drawer-status').innerHTML = statusBadge(party);

        // Amber to chase, blue for money held on their behalf, plain when settled —
        // and the label changes with it, because an advance under the heading
        // "Outstanding" is the reading that sends somebody chasing their own debt.
        const tile = $('#drawer-outstanding-tile');
        const amount = $('#drawer-outstanding');

        if (isAdvance(balance)) {
            tile.className = 'rounded-[10px] border border-blue-100 bg-blue-50 px-3 py-2.5';
            $('#drawer-outstanding-label').textContent = state.config.advanceStatus;
            amount.textContent = money(absolute(balance));
            amount.className = 'text-[17px] font-bold text-blue-700';
        } else {
            tile.className = isOwing(balance)
                ? 'rounded-[10px] border border-amber-100 bg-amber-50 px-3 py-2.5'
                : 'rounded-[10px] bg-background px-3 py-2.5';

            $('#drawer-outstanding-label').textContent = state.config.outstandingLabel;
            amount.textContent = money(balance ?? '0.00');
            amount.className = `text-[17px] font-bold ${isOwing(balance) ? 'text-amber-700' : 'text-emerald-600'}`;
        }

        $('#drawer-lifetime').textContent = money(party.lifetime?.[state.config.lifetimeKey] ?? null);

        $$('#drawer-tabs .tab').forEach((button) =>
            button.setAttribute('aria-selected', String(button.dataset.tab === state.drawerTab)));

        $('#drawer-edit').classList.toggle('hidden', !can('UPDATE', 'PARTIES'));
        $('#drawer-statement').classList.toggle('hidden', !can('READ', 'LEDGER'));

        showModal('#party-drawer');
        renderDrawerBody();
    }

    function renderDrawerBody() {
        const party = state.openParty;
        if (!party) return;

        const body = $('#drawer-body');

        if (state.drawerTab === 'overview') {
            body.innerHTML = drawerOverview(party);

            return;
        }

        body.innerHTML = '<p class="py-8 text-center text-sm text-muted-foreground">Loading…</p>';

        if (state.drawerTab === 'history') loadDocuments(party, state.config.historyTypes, 'history');
        if (state.drawerTab === 'payments') loadDocuments(party, state.config.paymentTypes, 'payments');
        if (state.drawerTab === 'activity') loadActivity(party);
    }

    function drawerOverview(party) {
        const { config } = state;
        const activity = party.activity ?? {};
        const lifetime = party.lifetime ?? {};

        const date = (value) => (value ? esc(formatDate(value)) : '<span class="text-muted-foreground">Never</span>');

        const details = [
            [`${cap(config.noun)} name`, esc(party.name)],
            ['Phone', party.phone
                ? `<a href="tel:${esc(party.phone)}" class="text-primary hover:underline">${esc(party.phone)}</a>`
                : '<span class="text-muted-foreground">Not provided</span>'],
            ['Email', party.email
                ? `<a href="mailto:${esc(party.email)}" class="text-primary hover:underline">${esc(party.email)}</a>`
                : '<span class="text-muted-foreground">Not provided</span>'],
            ['GSTIN', party.gstin
                ? `<code class="rounded bg-muted px-2 py-0.5 font-mono text-xs">${esc(party.gstin)}</code>`
                : '<span class="text-muted-foreground">Not provided</span>'],
            ['State code', party.state_code
                ? esc(party.state_code)
                : '<span class="text-muted-foreground">—</span>'],
            ['Address', party.address
                ? `<span class="inline-block max-w-[240px] text-right leading-snug">${esc(party.address)}</span>`
                : '<span class="text-muted-foreground">Not provided</span>'],
            ['Role', esc((party.role_labels ?? []).join(' & ') || '—')],
            [config.sinceLabel, party.created_at ? esc(formatDate(party.created_at)) : '—'],
            [config.dateLabel, date(activity[config.activityKey])],
            ['Last payment', date(activity.last_payment_at)],
            ['Transactions', String(activity.transaction_count ?? 0)],
        ];

        // The counterparty who is both. Stated here rather than netted into the
        // headline figure, because the two positions are settled separately.
        const dual = hasBalance(owedOther(party))
            ? `
                <div class="mt-5 flex items-start gap-2.5 rounded-[12px] border border-blue-100 bg-blue-50 px-4 py-3">
                    <span class="mt-0.5 shrink-0 text-blue-500">${iconStatement}</span>
                    <p class="text-[12.5px] text-blue-800">
                        They are also a ${esc(config.otherRole)}. ${otherSideNote(owedOther(party))}
                        on the other side — the statement shows both, because the two are settled separately.
                    </p>
                </div>`
            : '';

        const notes = party.notes
            ? `
                <div class="mt-5">
                    <h4 class="section-label mb-3">Notes</h4>
                    <p class="rounded-[12px] border border-border px-4 py-3 text-[13px] text-secondary-foreground">
                        ${esc(party.notes)}
                    </p>
                </div>`
            : '';

        return `
            <div class="mb-5 grid grid-cols-2 gap-2">
                <div class="rounded-[12px] bg-background px-4 py-3">
                    <p class="text-[11px] text-muted-foreground">${esc(config.lifetimeLabel)}</p>
                    <p class="mt-0.5 text-base font-bold text-foreground">${esc(money(lifetime[config.lifetimeKey] ?? null))}</p>
                </div>
                <div class="rounded-[12px] bg-background px-4 py-3">
                    <p class="text-[11px] text-muted-foreground">${esc(config.settledLabel)}</p>
                    <p class="mt-0.5 text-base font-bold text-foreground">${esc(money(lifetime[config.settledKey] ?? null))}</p>
                </div>
            </div>

            <h4 class="section-label mb-3">${esc(cap(config.noun))} details</h4>
            <div class="divide-y divide-muted overflow-hidden rounded-[12px] border border-border">
                ${details.map(([label, value]) => `
                    <div class="flex items-start justify-between gap-3 px-4 py-2.5">
                        <span class="shrink-0 text-[12.5px] text-muted-foreground">${esc(label)}</span>
                        <span class="text-right text-[13px] text-secondary-foreground">${value}</span>
                    </div>`).join('')}
            </div>
            ${dual}
            ${notes}`;
    }

    /**
     * The documents behind one counterparty: their bills, or the settlements
     * against them.
     *
     * Read from the transactions endpoint by party and type, which is the same
     * drill-down the statement offers — not a second, independently computed
     * summary that could disagree with it.
     */
    async function loadDocuments(party, types, tab) {
        const body = $('#drawer-body');

        const query = new URLSearchParams({ party_id: party.id, per_page: 25, sort: 'date', direction: 'desc' });
        types.forEach((type) => query.append('types[]', type));

        try {
            const payload = await auth.call(`/transactions?${query}`);

            // Guard against a tab switch that happened while this was in flight.
            if (state.drawerTab !== tab || state.openParty?.id !== party.id) return;

            const rows = payload.data ?? [];
            const empty = tab === 'history' ? state.config.historyEmpty : state.config.paymentsEmpty;

            if (!rows.length) {
                body.innerHTML = `<p class="py-8 text-center text-sm text-muted-foreground">${esc(empty)}</p>`;

                return;
            }

            body.innerHTML = tab === 'history' ? documentTable(rows) : paymentList(rows);
        } catch (error) {
            body.innerHTML = `<p class="py-8 text-center text-sm text-rose-600">${esc(error.message)}</p>`;
        }
    }

    const DOCUMENT_CHIP = {
        posted: 'bg-emerald-50 text-emerald-700',
        draft: 'bg-amber-50 text-amber-700',
        reversed: 'bg-rose-50 text-rose-600',
    };

    function documentTable(rows) {
        return `
            <h4 class="section-label mb-3">${esc(state.config.historyTitle)}</h4>
            <div class="overflow-hidden rounded-[12px] border border-border">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="border-b border-border bg-background text-left">
                            ${[state.config.documentColumn, 'Date', 'Amount', 'Status'].map((label) =>
                                `<th class="px-3 py-2.5 text-[11px] font-semibold whitespace-nowrap text-muted-foreground">${esc(label)}</th>`
                            ).join('')}
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-muted">
                        ${rows.map((row) => `
                            <tr class="transition hover:bg-background">
                                <td class="px-3 py-3 text-[13px] font-semibold text-primary">#${esc(String(row.id))}</td>
                                <td class="px-3 py-3 text-[12.5px] whitespace-nowrap text-muted-foreground">
                                    ${esc(formatDate(row.date))}
                                </td>
                                <td class="px-3 py-3 text-[13px] font-semibold whitespace-nowrap text-foreground">
                                    ${esc(money(row.total))}
                                </td>
                                <td class="px-3 py-3">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11.5px] font-semibold
                                                 ${DOCUMENT_CHIP[row.status] ?? 'bg-muted text-muted-foreground'}">
                                        ${esc(row.status_label ?? row.status)}
                                    </span>
                                </td>
                            </tr>`).join('')}
                    </tbody>
                </table>
            </div>

            <p class="mt-3 text-[11.5px] text-muted-foreground">
                The 25 most recent. The statement has every entry, both sides.
            </p>`;
    }

    function paymentList(rows) {
        return `
            <h4 class="section-label mb-3">Payment history</h4>
            <div class="space-y-2">
                ${rows.map((row) => `
                    <div class="flex items-center gap-3 rounded-[12px] border border-border px-4 py-3
                                transition hover:bg-background">
                        <span class="grid size-8 shrink-0 place-items-center rounded-full bg-emerald-50 text-emerald-600">
                            ${iconStatement}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[13.5px] font-semibold text-foreground">${esc(money(row.total))}</p>
                            <p class="text-xs text-muted-foreground">
                                ${esc(formatDate(row.date))} · ${esc(row.type_label ?? '')} #${esc(String(row.id))}
                            </p>
                        </div>
                        <span class="shrink-0 text-[11.5px] text-muted-foreground">${esc(row.status_label ?? '')}</span>
                    </div>`).join('')}
            </div>`;
    }

    /** Who changed this record, and when. M13's answer, not a second copy of it. */
    async function loadActivity(party) {
        const body = $('#drawer-body');

        try {
            const payload = await auth.call(`/audit-logs?resource=party&resource_id=${party.id}&per_page=25`);

            if (state.drawerTab !== 'activity' || state.openParty?.id !== party.id) return;

            const rows = payload.data ?? [];

            body.innerHTML = rows.length ? `
                <h4 class="section-label mb-3">Activity timeline</h4>
                <div class="relative">
                    <span class="absolute bottom-2 left-3.5 top-2 w-px bg-border"></span>
                    <div class="space-y-4">
                        ${rows.map((row) => `
                            <div class="flex gap-4">
                                <span class="z-10 grid size-7 shrink-0 place-items-center rounded-full
                                             bg-accent text-primary">${iconPencil}</span>
                                <div>
                                    <p class="text-[13.5px] font-medium text-secondary-foreground">
                                        ${esc(row.action_label ?? row.action ?? 'Changed')}
                                    </p>
                                    <p class="text-xs text-muted-foreground">${esc(row.actor?.name ?? 'System')}</p>
                                    <p class="mt-0.5 text-[11.5px] text-muted-foreground">${esc(formatDate(row.at))}</p>
                                </div>
                            </div>`).join('')}
                    </div>
                </div>` : '<p class="py-8 text-center text-sm text-muted-foreground">No recorded activity yet.</p>';
        } catch (error) {
            body.innerHTML = `<p class="py-8 text-center text-sm text-rose-600">${esc(error.message)}</p>`;
        }
    }

    /* -------------------------------------------------------------------------
     | Create / edit
     | ---------------------------------------------------------------------- */

    /**
     * The record form — `components/quick-party.js`, shared with the bill counter.
     *
     * `full`, because this is the screen that owns the whole record: the roles, the
     * email and the notes, not just what a bill needs. What is left here is the part
     * that is genuinely this screen's — which word it uses for the name column, and
     * what a save means to a list that is already on screen behind it.
     */
    function openForm(party = null) {
        const { noun, nameLabel, namePlaceholder, role } = state.config;

        openQuickParty({
            role,
            party,
            full: true,
            noun,
            nameLabel,
            namePlaceholder,

            /*
            | The level-1 slot this module keeps the form in.
            |
            | Passed on an edit as well as on a create, and for two reasons. It is
            | where a create is *mounted* — writing a counterparty down is what this
            | module opens on, so that is level 1 — and it is where the node is
            | *found*: an edit is opened from the list, and the workspace has the
            | form's whole surface detached by then. An edit still opens in the
            | drawer, which is what level 2 is for.
            */
            slot: formSlot,

            onSaved: async (saved, { editing, merged }) => {
                /*
                | §2A.8 — the new row is flagged rather than shown. Somebody entering
                | the workshop's suppliers writes several in a row and never sees the
                | list in between, so the flash happens whenever they do look. A
                | merge counts: the record existed, but it is new to this list.
                */
                if (!editing || merged) workspace?.flagNew(saved.id);

                /*
                | §2A.7 — refetched only where a list is actually held. A module
                | opened to write one supplier down must not be made to fetch five
                | hundred by saving, and the count on the switch control is repainted
                | either way.
                */
                if (workspace?.hasList()) await refresh({ keepPage: true });
                else workspace?.refresh();
            },
        });
    }

    function cap(word) {
        return word[0].toUpperCase() + word.slice(1);
    }

    /* -------------------------------------------------------------------------
     | Archive / delete
     | ---------------------------------------------------------------------- */

    async function setActive(id, isActive) {
        const party = findParty(id);
        if (!party) return;

        const confirmed = isActive || await confirmAction({
            title: `Archive this ${state.config.noun}`,
            body: `${party.name} will stop appearing when you choose a party on a transaction. Everything already `
                + 'posted stays exactly as it is, and their statement stays readable. You can restore them at any time.',
            confirmLabel: `Archive ${state.config.noun}`,
        });

        if (!confirmed) return;

        try {
            await auth.call(`/parties/${id}`, { method: 'PATCH', body: { is_active: isActive } });

            toast(isActive ? `${cap(state.config.noun)} restored.` : `${cap(state.config.noun)} archived.`);
            await refresh({ keepPage: true });
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    async function destroy(id) {
        const party = findParty(id);
        if (!party) return;

        const confirmed = await confirmAction({
            title: `Delete this ${state.config.noun}`,
            body: `${party.name} will be removed completely. This is only possible while they have no transactions `
                + 'at all — once they appear in the books, archive them instead so their entries keep their name.',
            confirmLabel: `Delete ${state.config.noun}`,
        });

        if (!confirmed) return;

        try {
            await auth.call(`/parties/${id}`, { method: 'DELETE' });

            toast(`${cap(state.config.noun)} deleted.`);
            hideModal('#party-drawer');
            await refresh();
        } catch (error) {
            // PARTY_IN_USE explains itself and names the alternative, so it is
            // shown as-is rather than replaced with something generic.
            toast(error.message, 'error');
        }
    }

    /* -------------------------------------------------------------------------
     | The statement
     | ---------------------------------------------------------------------- */

    /**
     * The combined ledger — both control accounts, whichever screen opened it.
     *
     * Not filtered to this screen's side. Scoping a statement to the role of the
     * list it was opened from would hide half of what a dual-role counterparty owes,
     * and the half it hid would be the half nobody then chased.
     */
    function openLedger(partyId) {
        const party = findParty(partyId);
        if (!party) return;

        state.ledger = { partyId, name: party.name, page: 1, lastPage: 1 };

        $('#party-ledger-title').textContent = party.name;
        $('#party-ledger-subtitle').textContent = isBoth(party)
            ? 'Every entry that moved their position, both sides of it, oldest first.'
            : 'Every entry that moved their position, oldest first.';

        $('#party-ledger-position').innerHTML = '';
        $('#party-ledger-rows').innerHTML = tableMessage(5, 'Loading statement…');

        showModal('#party-ledger-modal');
        loadLedger();
    }

    async function loadLedger() {
        const { partyId, page } = state.ledger;

        try {
            const payload = await auth.call(
                `/parties/${partyId}/ledger?per_page=${LEDGER_PAGE_SIZE}&page=${page}`
            );

            renderPosition(payload.meta);
            renderLedger(payload.data, payload.meta);
        } catch (error) {
            $('#party-ledger-rows').innerHTML = tableMessage(5, error.message, 'error');
            $('#party-ledger-summary').textContent = '';
        }
    }

    /**
     * Receivable and payable side by side, plus the net.
     *
     * The net is signed towards the workshop — positive means they owe you — which
     * is stated in words rather than left to a minus sign, because a minus sign in
     * front of a balance is exactly the thing people read the wrong way round.
     */
    function renderPosition(meta) {
        const { receivable, payable, net } = meta.outstanding;
        const owesUs = !net.startsWith('-');

        $('#party-ledger-position').innerHTML = `
            <div class="flex flex-wrap items-center gap-x-8 gap-y-3">
                <div>
                    <p class="text-xs uppercase tracking-wide text-muted-foreground">Receivable</p>
                    <p class="mt-0.5 font-mono text-lg font-semibold">${esc(formatMoney(receivable))}</p>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wide text-muted-foreground">Payable</p>
                    <p class="mt-0.5 font-mono text-lg font-semibold">${esc(formatMoney(payable))}</p>
                </div>
                <div class="border-l border-border pl-8">
                    <p class="text-xs uppercase tracking-wide text-muted-foreground">
                        ${isZeroAmount(net) ? 'Settled' : (owesUs ? 'They owe you' : 'You owe them')}
                    </p>
                    <p class="mt-0.5 font-mono text-lg font-semibold ${isZeroAmount(net) ? '' : (owesUs ? 'text-emerald-700' : 'text-rose-700')}">
                        ${esc(formatMoney(net.replace(/^-/, '')))}
                    </p>
                </div>
            </div>`;
    }

    function renderLedger(entries, meta) {
        const body = $('#party-ledger-rows');

        if (!entries.length) {
            body.innerHTML = tableMessage(5, 'Nothing has been posted against this party yet.');
            $('#party-ledger-summary').textContent = '';
            setLedgerPager(1, 1);

            return;
        }

        const opening = `
            <tr class="border-b border-border bg-secondary/30 text-[0.8125rem]">
                <td class="px-6 py-2 text-muted-foreground" colspan="4">Brought forward</td>
                <td class="px-6 py-2 text-right font-mono font-semibold">${esc(formatMoney(meta.opening_balance))}</td>
            </tr>`;

        body.innerHTML = opening + entries.map((entry) => `
            <tr class="border-b border-border">
                <td class="px-6 py-2.5 text-[0.8125rem] text-muted-foreground">${esc(formatDate(entry.date))}</td>
                <td class="px-4 py-2.5 text-[0.8125rem]">
                    <span class="font-medium">${esc(entry.account?.name ?? '')}</span>
                    ${entry.memo ? `<span class="ml-2 text-muted-foreground">${esc(entry.memo)}</span>` : ''}
                </td>
                <td class="px-4 py-2.5 text-right font-mono text-[0.8125rem]">
                    ${isZeroAmount(entry.debit) ? '' : esc(formatMoney(entry.debit))}
                </td>
                <td class="px-4 py-2.5 text-right font-mono text-[0.8125rem]">
                    ${isZeroAmount(entry.credit) ? '' : esc(formatMoney(entry.credit))}
                </td>
                <td class="px-6 py-2.5 text-right font-mono text-[0.8125rem] font-semibold">
                    ${esc(formatMoney(entry.running_balance))}
                </td>
            </tr>`).join('');

        const pagination = meta.pagination ?? {};
        state.ledger.lastPage = pagination.last_page ?? 1;

        $('#party-ledger-summary').textContent =
            `${pagination.total ?? entries.length} entries · closing ${formatMoney(meta.closing_balance)}`;

        setLedgerPager(pagination.current_page ?? 1, state.ledger.lastPage);
    }

    function setLedgerPager(current, last) {
        $('#ledger-prev').disabled = current <= 1;
        $('#ledger-next').disabled = current >= last;
    }

    /* -------------------------------------------------------------------------
     | Toolbar
     | ---------------------------------------------------------------------- */

    function sortOptions() {
        return [
            { column: 'name', label: 'Name' },
            { column: 'outstanding', label: state.config.side === 'payable' ? 'Payable' : 'Receivable' },
            { column: 'activity', label: state.config.dateLabel },
            { column: 'status', label: 'Status' },
        ];
    }

    function renderSortPanel() {
        inList('#sort-panel').innerHTML = sortOptions()
            .flatMap((option) => ['asc', 'desc'].map((direction) => {
                const on = state.sort.column === option.column && state.sort.direction === direction;

                return `
                    <button type="button" role="menuitem" class="row-menu-item ${on ? 'text-primary' : ''}"
                            data-sort-option="${option.column}" data-sort-direction="${direction}">
                        ${direction === 'asc' ? iconArrowUp : iconArrowDown}
                        ${esc(option.label)} ${direction === 'asc' ? '(A–Z)' : '(Z–A)'}
                    </button>`;
            }))
            .join('');
    }

    function applySort(column, direction = null) {
        if (direction) {
            state.sort = { column, direction };
        } else if (state.sort.column === column) {
            state.sort.direction = state.sort.direction === 'asc' ? 'desc' : 'asc';
        } else {
            state.sort = { column, direction: 'asc' };
        }

        state.page = 1;
        render();
        renderSortPanel();
    }

    function renderFilterCount() {
        const active = [
            state.isActive !== '1',
            state.hasGstin !== '',
            state.bothRoles !== '',
        ].filter(Boolean).length;

        const badge = inList('#filter-count');

        badge.textContent = active;
        badge.classList.toggle('hidden', active === 0);
    }

    function setPill(pill) {
        // Clicking the applied filter clears it, which is what a toggle means and
        // what the tiles look like they do.
        state.pill = state.pill === pill ? 'all' : pill;
        state.page = 1;

        allInList('#filter-pills [data-pill]').forEach((button) =>
            button.setAttribute('aria-pressed', String(button.dataset.pill === state.pill)));

        render();
    }

    function clearFilters() {
        Object.assign(state, {
            search: '', isActive: '1', hasGstin: '', bothRoles: '', pill: 'all', page: 1,
        });

        inList('#filter-search').value = '';
        inList('#filter-status').value = '1';
        inList('#filter-gstin').value = '';
        inList('#filter-both').value = '';

        allInList('#filter-pills [data-pill]').forEach((button) =>
            button.setAttribute('aria-pressed', String(button.dataset.pill === 'all')));

        renderFilterCount();
        render();
    }

    /* -------------------------------------------------------------------------
     | Boot
     | ---------------------------------------------------------------------- */

    /**
     * The list, fetched the first time it is asked for and held from then on
     * (§2A.7).
     *
     * Somebody who opened the module to write one supplier down never pays for the
     * five hundred already on the books.
     */
    async function loadList() {
        inList('#parties-body').innerHTML = tableMessage(COLUMNS, `Loading ${state.config.nounPlural}…`);

        try {
            await loadParties();
            render();
        } catch (error) {
            // A platform super-admin holds every permission but belongs to no
            // workshop, so they can reach this module and there is nothing to show
            // them. Not their mistake — say so plainly.
            inList('#parties-body').innerHTML = error.code === 'NO_WORKSPACE'
                ? tableMessage(COLUMNS, 'Your account administers the platform rather than a single workshop, '
                    + `so it has no ${state.config.nounPlural} of its own.`)
                : tableMessage(COLUMNS, error.message, 'error');

            inList('#parties-summary').textContent = '';
        }
    }

    async function refresh({ keepPage = false } = {}) {
        if (!keepPage) state.page = 1;

        await loadParties();

        // An open drawer points at an object that has just been replaced, so it is
        // repainted from the new row. Checked against the DOM rather than against
        // `state.openParty`, which outlives a close: somebody who shut the drawer
        // and then edited a different row should not have it spring back open.
        const wasOpen = state.openParty !== null
            && !$('#party-drawer').classList.contains('hidden');

        const replacement = state.openParty === null ? null : findParty(state.openParty.id);

        state.openParty = replacement;

        if (wasOpen) {
            if (replacement) openDrawer(replacement.id, { tab: state.drawerTab });
            else hideModal('#party-drawer');
        }

        render();

        // The count rides on the Show control (§2A.4), so a list that just grew or
        // shrank has to repaint the heading as well as the table.
        workspace?.refresh();
    }

    function runAction(action, id) {
        if (action === 'open') openDrawer(id);
        if (action === 'statement') openLedger(id);
        if (action === 'create') window.location.assign(`${state.config.createHref}&party=${id}`);
        if (action === 'edit') openForm(findParty(id));
        if (action === 'archive') setActive(id, false);
        if (action === 'restore') setActive(id, true);
        if (action === 'delete') destroy(id);
    }

    return async function init() {
        /*
        | Both surfaces are still in the document here — mounting the workspace
        | at the end of this function is what detaches whichever one is not in
        | use — so they are held by reference now, while they can still be found.
        */
        const root = $('[data-ws-list]').closest('[data-module-root]');

        listRoot = $('[data-ws-list]', root);
        formSlot = $('[data-party-form-slot]', root);

        renderSortPanel();
        renderFilterCount();

        /* Toolbar ------------------------------------------------------- */

        inList('#filter-search').addEventListener('input', debounce((event) => {
            state.search = event.target.value.trim();
            state.page = 1;
            render();
        }, 200));

        inList('#filter-toggle').addEventListener('click', (event) => {
            event.stopPropagation();

            const open = inList('#filter-panel').classList.toggle('hidden');

            inList('#filter-toggle').setAttribute('aria-expanded', String(!open));
            inList('#sort-panel').classList.add('hidden');
        });

        inList('#sort-toggle').addEventListener('click', (event) => {
            event.stopPropagation();

            const open = inList('#sort-panel').classList.toggle('hidden');

            inList('#sort-toggle').setAttribute('aria-expanded', String(!open));
            inList('#filter-panel').classList.add('hidden');
        });

        inList('#sort-panel').addEventListener('click', (event) => {
            const option = event.target.closest('[data-sort-option]');
            if (!option) return;

            applySort(option.dataset.sortOption, option.dataset.sortDirection);
            inList('#sort-panel').classList.add('hidden');
            inList('#sort-toggle').setAttribute('aria-expanded', 'false');
        });

        [['filter-status', 'isActive'], ['filter-gstin', 'hasGstin'], ['filter-both', 'bothRoles']]
            .forEach(([id, key]) => {
                inList(`#${id}`).addEventListener('change', (event) => {
                    state[key] = event.target.value;
                    state.page = 1;

                    renderFilterCount();
                    render();
                });
            });

        inList('#filter-pills').addEventListener('click', (event) => {
            const pill = event.target.closest('[data-pill]');

            if (pill) setPill(pill.dataset.pill);
        });

        allInList('[data-stat-filter]').forEach((tile) =>
            tile.addEventListener('click', () => setPill(tile.dataset.statFilter)));

        inList('#clear-filters').addEventListener('click', clearFilters);

        inList('#parties-head').addEventListener('click', (event) => {
            const th = event.target.closest('[data-sort]');

            if (th) applySort(th.dataset.sort);
        });

        inList('#parties-pager').addEventListener('click', (event) => {
            const button = event.target.closest('[data-page]');
            if (!button) return;

            state.page = Number(button.dataset.page);
            render();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        /* The table ----------------------------------------------------- */

        inList('#parties-body').addEventListener('click', (event) => {
            const menuButton = event.target.closest('[data-menu]');

            if (menuButton) {
                event.stopPropagation();

                const wasOpen = menuButton.getAttribute('aria-expanded') === 'true';

                closeMenus();
                if (!wasOpen) openMenu(menuButton, menuButton.dataset.menu);

                return;
            }

            const row = event.target.closest('[data-row]');

            if (row) openDrawer(row.dataset.row);
        });

        // A row is a link, so it answers to the keyboard like one.
        inList('#parties-body').addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' && event.key !== ' ') return;

            const row = event.target.closest('[data-row]');

            if (row) {
                event.preventDefault();
                openDrawer(row.dataset.row);
            }
        });

        // The menu itself is a fixed layer on the body, not a child of the row,
        // so its clicks are caught here rather than by the table's handler.
        document.addEventListener('click', (event) => {
            const action = event.target.closest('[data-row-menu] [data-action]');
            if (!action) return;

            event.stopPropagation();

            const { action: name, id } = action.dataset;

            closeMenus();
            runAction(name, id);
        });

        // A fixed layer does not travel with the row it belongs to.
        window.addEventListener('scroll', closeMenus, { passive: true, capture: true });
        window.addEventListener('resize', closeMenus, { passive: true });

        /* The drawer ---------------------------------------------------- */

        $('#drawer-tabs').addEventListener('click', (event) => {
            const tab = event.target.closest('[data-tab]');
            if (!tab) return;

            state.drawerTab = tab.dataset.tab;

            $$('#drawer-tabs .tab').forEach((other) =>
                other.setAttribute('aria-selected', String(other.dataset.tab === state.drawerTab)));

            renderDrawerBody();
        });

        $('#drawer-edit').addEventListener('click', () => {
            if (state.openParty) openForm(state.openParty);
        });

        $('#drawer-statement').addEventListener('click', () => {
            if (state.openParty) openLedger(state.openParty.id);
        });

        /* Forms and the statement --------------------------------------- */

        // The record form wires itself — it is the same drawer the bill counter
        // opens, and it lives in components/quick-party.js.
        initQuickParty();

        $('#ledger-prev').addEventListener('click', () => {
            if (state.ledger.page > 1) { state.ledger.page -= 1; loadLedger(); }
        });

        $('#ledger-next').addEventListener('click', () => {
            if (state.ledger.page < state.ledger.lastPage) { state.ledger.page += 1; loadLedger(); }
        });

        // One listener for every "click away to dismiss": the row menus and both
        // toolbar popovers.
        document.addEventListener('click', () => {
            closeMenus();
            inList('#filter-panel').classList.add('hidden');
            inList('#sort-panel').classList.add('hidden');
            inList('#filter-toggle').setAttribute('aria-expanded', 'false');
            inList('#sort-toggle').setAttribute('aria-expanded', 'false');
        });

        inList('#filter-panel').addEventListener('click', (event) => event.stopPropagation());

        /* The workspace ------------------------------------------------- */

        /*
        | Mounted last, and that matters: everything above binds to nodes while
        | they are still in the document, and mounting is what detaches whichever
        | of the two surfaces is not in use. The listeners survive it, because
        | they belong to the elements rather than to the document.
        |
        | A caller who may read the books but not write to them has no create
        | form to land on, so `canCreate` puts them on the list and paints no
        | switch control to a surface they cannot use. The grant is enforced on
        | every endpoint behind this module regardless (§6.1).
        */
        const canWrite = can('WRITE', 'PARTIES');

        // Filled in before the workspace mounts, because mounting is what shows
        // it: the module lands on this form (§2A.1).
        if (canWrite) openForm();

        workspace = mountWorkspace(root, {
            key: config.key,
            title: config.title,
            formSubtitle: config.formSubtitle,
            listSubtitle: (count) => (count === null
                ? config.listBlurb
                : `${count} ${count === 1 ? config.noun : config.nounPlural} on the books. `
                    + 'Click a row to open one.'),
            createLabel: config.addLabel,
            count: () => (state.parties.length ? state.parties.length : null),
            canCreate: canWrite,
            onShowList: loadList,

            /*
            | Bring the form home.
            |
            | It may have been left in the edit drawer — closed with Cancel, with
            | Escape, or by a save — and level 1 is where a *create* lives. A
            | form still holding somebody's id is that record's edit form, so it
            | is reopened blank; one holding nothing is re-attached exactly as it
            | was typed. A half-typed new supplier survives a look at the list
            | (§2A.6), somebody else's record does not.
            */
            onShowForm: () => {
                // Asked for by identity rather than looked up: it may be sitting
                // in the edit drawer, or in a level-1 slot that is off screen.
                const form = quickPartyForm();

                if (!form || form.elements.id.value) openForm();
                else adoptForm(form, formSlot, { chrome: 'inline' });

                $('#quick-party-name', formSlot)?.focus();
            },
        });
    };
}
