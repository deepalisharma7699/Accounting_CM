import auth from '../auth-client';
import { onChange } from '../data-bus';
import {
    creditPanel, overviewPanel, peoplePanel, salesPanel, stockPanel,
} from '../components/insight-panels';
import { STATEMENTS } from '../components/report-statements';
import { workInvoiceRows } from '../components/work-invoices';
import { can } from '../permissions';
import { openModule } from '../shell';
import {
    $, $$, downloadCsv, esc, formatDate, formatMoney, hideModal, showModal, toast,
} from '../ui';
import { mountWorkspace } from '../workspace';

/**
 * Insights — M23. The module controller.
 *
 * It owns three things and nothing else: which tab is open, which period every
 * tab is being asked about, and what has already been fetched. The panels
 * themselves are pure renderers in `components/insight-panels.js` and
 * `components/report-statements.js`, so nothing about how a chart looks is
 * decided here.
 *
 * **Read-mostly (§2A.10)**, so it mounts with `canCreate: false`, lands on its
 * list and paints no switch control. Stock is the worked example; the frame is
 * the shared one.
 *
 * ## The cache is the design
 *
 * A tab is fetched on the first click and held from then on (§2A.7, §7.2), so
 * moving between Overview and the day book costs nothing after the first visit
 * and a workshop that only ever opens the ageing never pays for a dead-stock
 * scan. The cache is keyed by tab **and period**, because the same tab at a
 * different window is a different answer — and it is cleared wholesale when the
 * period changes rather than accumulating a key per window somebody dragged
 * through.
 *
 * ## One period across every tab
 *
 * Somebody who has just seen a gross margin they did not expect wants the day
 * book *without* re-choosing the window they were looking at. Only the parked
 * drafts ignore it, and deliberately: a draft is outstanding work rather than an
 * event.
 *
 * ## Everything is scoped to the module root
 *
 * Not one `document.querySelector` in this file. The shell keeps a module's node
 * detached when another module is open, and the workspace detaches whichever
 * surface it is not showing — a lookup through `document` finds nothing in
 * either case, throws on the first `.classList`, and takes the rest of the
 * handler with it. That is the failure CLAUDE.md records, and scoping is the fix.
 */

/* -------------------------------------------------------------------------
 | What each tab is
 | ---------------------------------------------------------------------- */

/**
 * The panels, in the order the strip lays them out.
 *
 * `endpoint` is what to fetch and `render` is what to do with it. A statement's
 * entry points at `/reports/*` rather than at `/insights/*`, which is the whole
 * of how the two halves of this module coexist: the statements were built in M12
 * and are not re-implemented here.
 */
const TABS = {
    overview: {
        endpoint: () => '/insights/overview',
        render: (payload) => overviewPanel(payload.data),
    },
    sales: {
        endpoint: () => '/insights/sales',
        render: (payload) => salesPanel(payload.data, { direction: 'sale' }),
    },
    purchase: {
        endpoint: () => '/insights/purchase',
        render: (payload) => salesPanel(payload.data, { direction: 'purchase' }),
    },
    stock: {
        endpoint: () => '/insights/stock',
        render: (payload) => stockPanel(payload.data),
    },
    credit: {
        endpoint: () => '/insights/credit',
        render: (payload) => creditPanel(payload.data),
    },
    people: {
        endpoint: () => '/insights/people',
        render: (payload) => peoplePanel(payload.data),
        // Presentation only; the endpoint requires the grant as well. See the
        // route note — this is a privacy line, not a convenience.
        requires: ['READ', 'STAFF'],
    },
    ...Object.fromEntries(Object.entries(STATEMENTS).map(([key, statement]) => [key, {
        endpoint: () => `/reports/${key}`,
        render: (payload) => statement.render(payload.data, payload.meta ?? {}),
        paged: statement.paged,
        periodless: statement.periodless,
    }])),
};

const DEFAULT_TAB = 'overview';

/* -------------------------------------------------------------------------
 | State
 | ---------------------------------------------------------------------- */

const state = {
    tab: DEFAULT_TAB,
    period: 'this_financial_year',
    from: '',
    to: '',
    /** Page number per paged statement — the insight panels are not paged. */
    pages: {},
    /** `${tab}` -> the payload last fetched for the current period. */
    cache: new Map(),
    /** The window every cached entry belongs to, so a change invalidates all. */
    cacheKey: '',
    /** The person drawer: who is open, and the invoices fetched for them so far. */
    person: { id: null, page: 0, invoices: [], total: 0 },
};

/** Held at mount and never looked up again — see the file note. */
let root = null;

/**
 * Which fetch is the current one.
 *
 * A tab click while another tab is still arriving is ordinary impatience, not a
 * mistake, so it is allowed — but the slower response must not be allowed to
 * paint over the faster one it lost the race to. Every request takes a ticket
 * and only the newest may write to the screen.
 */
let ticket = 0;

function periodKey() {
    return state.period === 'custom' ? `custom:${state.from}:${state.to}` : state.period;
}

/* -------------------------------------------------------------------------
 | Fetching
 | ---------------------------------------------------------------------- */

function query(tab) {
    const params = new URLSearchParams();
    const config = TABS[tab];

    if (!config.periodless) {
        params.set('period', state.period);

        if (state.period === 'custom') {
            if (state.from) params.set('from', state.from);
            if (state.to) params.set('to', state.to);
        }
    }

    if (config.paged) {
        params.set('per_page', 25);
        params.set('page', state.pages[tab] ?? 1);
    }

    return params;
}

/**
 * Show a tab, fetching it only if it is not already held.
 *
 * A paged statement bypasses the cache when its page has moved, because page 2
 * is genuinely different data — that is the one case where re-fetching the same
 * tab at the same period is correct.
 */
async function show(tab, { refetch = false } = {}) {
    state.tab = tab;
    paintTabs();

    const fresh = state.cacheKey === periodKey();

    if (!fresh) {
        state.cache.clear();
        state.cacheKey = periodKey();
    }

    if (!refetch && state.cache.has(tab)) {
        const held = state.cache.get(tab);

        paint(held);
        paintPeriodLabel(held.meta?.period);

        return;
    }

    paintLoading();

    const mine = ++ticket;

    try {
        const payload = await auth.call(`${TABS[tab].endpoint()}?${query(tab)}`);

        // Held either way — a response that lost the race is still a correct
        // answer for its tab, and caching it means the tab is instant when the
        // reader comes back to it.
        state.cache.set(tab, payload);

        if (mine !== ticket) return;

        paint(payload);
        paintPeriodLabel(payload.meta?.period);
    } catch (error) {
        if (mine !== ticket) return;

        paintError(error);
    }
}

/** Drop everything held and redraw the open tab. */
function reload() {
    state.cache.clear();
    state.cacheKey = periodKey();
    state.pages = {};
    show(state.tab);
}

/* -------------------------------------------------------------------------
 | Painting
 | ---------------------------------------------------------------------- */

function panelHost() {
    return $('#insight-panel', root);
}

function paint(payload) {
    try {
        panelHost().innerHTML = TABS[state.tab].render(payload);
    } catch (error) {
        // A renderer that throws must not leave the panel showing the previous
        // tab's content under this tab's heading, which is worse than an error.
        panelHost().innerHTML = message('This panel could not be drawn.', 'error');
        toast(error.message ?? 'Something went wrong drawing this panel.', 'error');
    }
}

/**
 * The skeleton, sized roughly like what is coming.
 *
 * Four tiles and a block, because every panel in this module opens with a row of
 * figures over something wide. A spinner in the middle of an empty area would
 * make the page jump when the content arrived.
 */
function paintLoading() {
    panelHost().innerHTML = `
        <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            ${Array.from({ length: 4 }, () => `
                <div class="stat-tile flex-col items-start gap-2">
                    <span class="skel w-20"></span>
                    <span class="skel w-28"></span>
                </div>`).join('')}
        </div>
        <div class="surface p-4">
            ${Array.from({ length: 5 }, () => '<span class="skel mb-3 w-full"></span>').join('')}
        </div>`;
}

function message(text, tone = 'muted') {
    const classes = tone === 'error'
        ? 'border-rose-200 bg-rose-50 text-rose-900'
        : 'border-border text-muted-foreground';

    return `<div class="surface ${classes} px-4 py-10 text-center text-[0.8125rem]">${esc(text)}</div>`;
}

function paintError(error) {
    /*
    | A platform super-admin holds every grant and owns no books. Their request
    | is well formed; there is simply nothing to report on, and an error state
    | would send them looking for a fault that is not there.
    */
    if (error.code === 'NO_WORKSPACE') {
        panelHost().innerHTML = message(
            'Your account administers the platform rather than a single workshop, so it has no books to report on.',
        );

        return;
    }

    panelHost().innerHTML = message(error.message ?? 'This panel could not be loaded.', 'error');
}

function paintTabs() {
    $$('#insight-tabs [data-tab]', root).forEach((tab) => {
        tab.setAttribute('aria-selected', tab.dataset.tab === state.tab ? 'true' : 'false');
    });

    // The drafts worklist ignores the period, so the controls that set one are
    // disabled rather than left looking as though they apply.
    const periodless = Boolean(TABS[state.tab].periodless);

    $('#filter-period', root).disabled = periodless;
    $$('[data-custom-dates]', root).forEach((el) => {
        el.classList.toggle('hidden', periodless || state.period !== 'custom');
    });
}

/**
 * What the screen is covering, and what its deltas are measured against.
 *
 * The comparison window is named here rather than left implicit, because "up
 * 18%" is meaningless without it — and the window before 1–31 March is 29
 * January to 28 February, not February, which is exactly the sort of thing a
 * reader should not have to work out.
 */
function paintPeriodLabel(period) {
    const label = $('#period-label', root);

    if (TABS[state.tab].periodless) {
        label.textContent = 'Everything not yet posted, whenever it was started.';

        return;
    }

    const payload = state.cache.get('overview');
    const against = state.tab === 'overview' ? payload?.data?.compared_with : null;

    label.textContent = period
        ? `Covering ${period.label.toLowerCase()}${against ? `, against ${against.label.toLowerCase()}` : ''}.`
        : '';
}

/* -------------------------------------------------------------------------
 | One person — the People tab's drawer
 | ---------------------------------------------------------------------- */

const PERSON_PAGE = 25;

/**
 * Open a row of "Person by person".
 *
 * The header is the row itself, out of the payload already held, so it paints
 * at once and agrees with the table to the figure. Only the invoices behind the
 * count are fetched — from the Staff card's own `/staff/{id}/work`, over the
 * window the panel was drawn for, so the list and the count are one question.
 */
function openPerson(id) {
    const held = state.cache.get('people');
    const row = (held?.data?.people ?? []).find((person) => person.employee_id === id);

    if (!row) return;

    state.person = { id, page: 0, invoices: [], total: 0, period: held.meta?.period ?? null };

    const days = row.attendance ?? null;
    const present = days
        ? String(days.present ?? 0) + (days.half_day ? ` + ${days.half_day} half` : '')
        : '—';

    $('#person-drawer-title', root).textContent = row.name;
    $('#person-drawer-subtitle', root).textContent = [
        row.designation ?? 'No designation',
        row.is_active ? null : 'Left',
        held.meta?.period?.label,
    ].filter(Boolean).join(' · ');

    $('#person-drawer-tiles', root).innerHTML = [
        ['Days present', present],
        ['Wages', formatMoney(row.cost)],
        ['Advanced', formatMoney(row.advance_given)],
        ['Advance still out', formatMoney(row.advance_outstanding)],
    ].map(([label, value]) => `
        <div class="rounded-[10px] bg-background px-3 py-2.5">
            <p class="mb-0.5 text-[11px] text-muted-foreground">${esc(label)}</p>
            <p class="truncate text-[15px] font-bold text-foreground">${esc(value)}</p>
        </div>`).join('');

    $('#person-drawer-body', root).innerHTML = personDetails(row)
        + '<div id="person-invoices"><span class="skel mb-3 w-full"></span><span class="skel w-2/3"></span></div>';

    showModal($('#person-drawer', root));
    loadPersonInvoices();
}

const heading = (text) => `<h4 class="mb-2 text-[0.6875rem] font-semibold uppercase tracking-wider text-muted-foreground">${esc(text)}</h4>`;
const none = '<span class="text-muted-foreground">—</span>';

/** A `<dl>` of label/value pairs; a value is already escaped markup. */
function pairs(rows) {
    return `<dl class="dl mb-5">${rows.map(([label, value]) => `<dt>${esc(label)}</dt><dd>${value}</dd>`).join('')}</dl>`;
}

/**
 * Everything the row carries about one person, in the order somebody asks it:
 * who they are, whether they turned up, what they cost and what is out with
 * them, and what work they are named on.
 *
 * Every figure is the row's own — the table and the drawer are one payload, so
 * they cannot disagree.
 */
function personDetails(row) {
    const days = row.attendance ?? null;
    const register = [
        ['Present', days?.present], ['Half day', days?.half_day], ['Absent', days?.absent],
        ['Paid leave', days?.paid_leave], ['Off', days?.off], ['Unmarked', days?.unmarked],
    ];
    const trades = (row.work_trades ?? []).map((trade) => `${trade.designation} ${trade.jobs}`).join(' · ');
    const rate = Number(row.pay_rate) > 0
        ? `${esc(formatMoney(row.pay_rate))} ${row.salary_basis === 'daily' ? 'a day' : 'a month'}`
        : none;

    return heading('Who')
        + pairs([
            ['Designation', row.designation ? esc(row.designation) : none],
            ['Paid', row.salary_basis_label ? `${esc(row.salary_basis_label)} · ${rate}` : rate],
            ['Joined', row.joined_on ? esc(formatDate(row.joined_on)) : none],
            ...(row.left_on ? [['Left', esc(formatDate(row.left_on))]] : []),
            ['Phone', row.phone ? `<a class="hover:underline" href="tel:${esc(row.phone)}">${esc(row.phone)}</a>` : none],
        ])

        + heading('Attendance in this period')
        + (days
            ? `<div class="mb-5 grid grid-cols-3 gap-2 sm:grid-cols-6">
                ${register.map(([label, value]) => `
                    <div class="rounded-[10px] border border-border px-2.5 py-2 text-center">
                        <p class="text-[11px] text-muted-foreground">${esc(label)}</p>
                        <p class="text-[0.9375rem] font-semibold text-foreground">${esc(String(value ?? 0))}</p>
                    </div>`).join('')}
               </div>`
            : '<p class="mb-5 text-[0.8125rem] text-muted-foreground">The register was not available for this period.</p>')

        + heading('Pay and advances')
        + pairs([
            ['Payslips', esc(String(row.payslips ?? 0))],
            ['Wages (gross)', esc(formatMoney(row.cost))],
            ['Recovered from pay', esc(formatMoney(row.advance_recovered))],
            ['Paid out on payslips', esc(formatMoney(row.net_paid))],
            ['Advances given', `${esc(formatMoney(row.advance_given))}${row.advance_given_count
                ? ` <span class="text-muted-foreground">· ${row.advance_given_count} voucher${row.advance_given_count === 1 ? '' : 's'}</span>`
                : ''}`],
            ['Still out today', `<span class="${Number(row.advance_outstanding) > 0 ? 'font-semibold text-amber-700' : ''}">${esc(formatMoney(row.advance_outstanding))}</span>`],
        ])

        + heading('Work')
        + pairs([
            ['Invoices naming them', esc(String(row.work_jobs ?? 0))],
            ['Those invoices', esc(formatMoney(row.work_value))],
            ['Trades', trades ? esc(trades) : none],
        ])

        + heading('Invoices naming them');
}

async function loadPersonInvoices() {
    const person = state.person;
    const id = person.id;
    const params = new URLSearchParams({ per_page: PERSON_PAGE, page: person.page + 1 });

    if (person.period?.from) params.set('from', person.period.from);
    if (person.period?.to) params.set('to', person.period.to);

    const more = $('#person-more', root);

    if (more) {
        more.disabled = true;
        more.textContent = 'Loading…';
    }

    try {
        const payload = await auth.call(`/staff/${id}/work?${params}`);

        // The reader may have opened somebody else while this was in flight.
        if (state.person.id !== id) return;

        person.page += 1;
        person.invoices.push(...(payload.data ?? []));
        person.total = payload.meta?.pagination?.total ?? person.invoices.length;

        paintPersonInvoices(payload.meta?.pagination?.has_more);
    } catch (error) {
        if (state.person.id !== id) return;

        $('#person-invoices', root).innerHTML = `<p class="text-[0.8125rem] text-rose-600">${esc(error.message ?? 'The invoices could not be loaded.')}</p>`;
    }
}

function paintPersonInvoices(hasMore) {
    const { invoices, total } = state.person;
    const host = $('#person-invoices', root);

    if (!invoices.length) {
        host.innerHTML = '<p class="rounded-[10px] border border-dashed border-border px-4 py-6 text-center text-[0.8125rem] text-muted-foreground">No invoice in this period names them.</p>';

        return;
    }

    host.innerHTML = workInvoiceRows(invoices, { link: true })
        + `<div class="mt-3 flex items-center justify-between gap-3">
               <p class="text-[0.75rem] text-muted-foreground">Showing ${invoices.length} of ${total}, newest first.</p>
               ${hasMore ? '<button type="button" id="person-more" class="btn btn-secondary btn-sm">Show more</button>' : ''}
           </div>`;
}

/* -------------------------------------------------------------------------
 | Export
 | ---------------------------------------------------------------------- */

/**
 * Save whatever the open tab is showing.
 *
 * Reads the rendered table rather than the payload, and that is deliberate: what
 * a reader wants saved is what they are looking at, columns and order included,
 * and a second serialiser per panel would be nine more things to keep in step
 * with nine renderers. A panel with no table exports its figures instead.
 */
function exportOpenTab() {
    const tables = $$('#insight-panel table', root);

    if (!tables.length) {
        toast('There is no table on this panel to export.', 'error');

        return;
    }

    const rows = [];

    tables.forEach((table, index) => {
        if (index > 0) rows.push([]);

        table.querySelectorAll('tr').forEach((tr) => {
            const cells = Array.from(tr.children).map((cell) => cell.textContent.trim().replace(/\s+/g, ' '));

            if (cells.some((value) => value !== '')) rows.push(cells);
        });
    });

    downloadCsv(`${state.tab}-${periodKey().replace(/[^a-z0-9]+/gi, '-')}.csv`, rows);
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

async function loadPeriods() {
    const select = $('#filter-period', root);

    try {
        const { data } = await auth.call('/insights/meta');

        select.innerHTML = data.periods
            .map((preset) => `<option value="${esc(preset.value)}">${esc(preset.label)}</option>`)
            .join('');

        select.value = state.period;

        // Said once at the top rather than eight times inside empty panels.
        $('#insight-empty', root).classList.toggle('hidden', Boolean(data.has_data));
    } catch {
        // Without the presets the picker falls back to all time, which is still
        // a correct answer — and every panel below still draws.
        select.innerHTML = '<option value="all">Everything so far</option>';
        state.period = 'all';
    }
}

export default async function initInsights() {
    root = $('[data-module-root="insights"]') ?? document;

    mountWorkspace(root, {
        key: 'insights',
        title: 'Insights',
        // Read-mostly under §2A.10: no create form, so no switch control and no
        // form subtitle is ever shown.
        formSubtitle: '',
        listSubtitle: () => 'Nothing here is stored. Every figure is worked out from the documents as you ask for it.',
        createLabel: '',
        canCreate: false,
    });

    await loadPeriods();
    await show(DEFAULT_TAB);

    /* --- held is not the same as stale (§3.7) ------------------------------ */

    /*
    | Every panel here is worked out from what the other modules write, and the
    | cache above holds each one for the life of the tab — so without this, a
    | day's attendance marked in Staff, an advance paid or a sale posted left
    | People, Sales and the rest reporting the figures from before, until a full
    | reload. The People tab read zero days for a register that was full.
    |
    | Every resource, because there is no panel that reads none of them. The
    | write only marks; the refetch waits until somebody is looking (§7.2) —
    | the open tab on the way back in, every other tab on its next click.
    */
    let stale = false;

    onChange(['stock', 'items', 'transactions', 'parties', 'ledger', 'staff'], () => {
        stale = true;
    });

    root.addEventListener('module:shown', () => {
        if (!stale) return;

        stale = false;
        state.cache.clear();
        show(state.tab);
    });

    /* --- the tab strip ---------------------------------------------------- */

    $('#insight-tabs', root).addEventListener('click', (event) => {
        const tab = event.target.closest('[data-tab]');

        if (!tab) return;

        show(tab.dataset.tab);
    });

    /* --- the period ------------------------------------------------------- */

    $('#filter-period', root).addEventListener('change', (event) => {
        state.period = event.target.value;
        paintTabs();
        reload();
    });

    ['from', 'to'].forEach((field) => {
        $(`#filter-${field}`, root).addEventListener('change', (event) => {
            state[field] = event.target.value;
            state.period = 'custom';
            $('#filter-period', root).value = 'custom';
            paintTabs();
            reload();
        });
    });

    $('#export-csv', root).addEventListener('click', exportOpenTab);

    bindPersonDrawer();

    /* --- inside a panel --------------------------------------------------- */

    /*
    | One delegated listener on the host rather than bindings per render.
    |
    | Every panel is replaced wholesale on each paint, so anything bound to a
    | button inside one would be lost the moment the tab changed — and rebinding
    | after each render is how a listener ends up attached twice.
    */
    panelHost().addEventListener('click', (event) => {
        const person = event.target.closest('[data-person]');

        if (person) {
            openPerson(Number(person.dataset.person));

            return;
        }

        // A row of the exception feed opens the tab it is about. That is the
        // whole point of the feed: a finding somebody cannot act on from the
        // screen it appears on is a finding they will not act on.
        const attention = event.target.closest('[data-attention]');

        if (attention) {
            const tab = attention.dataset.attention;

            if (TABS[tab] && permitted(tab)) show(tab);

            return;
        }

        const page = event.target.closest('[data-page]');

        if (!page || page.disabled) return;

        const current = state.pages[state.tab] ?? 1;

        state.pages[state.tab] = page.dataset.page === 'next' ? current + 1 : Math.max(1, current - 1);
        show(state.tab, { refetch: true });
    });
}

function bindPersonDrawer() {
    $('#person-drawer', root).addEventListener('click', (event) => {
        if (event.target.closest('#person-more')) {
            loadPersonInvoices();

            return;
        }

        // An invoice opens where invoices live — the Sales drawer, in the mounted
        // shell. This drawer is closed first so it is not left on the modal
        // stack for Escape to find from inside another module.
        const doc = event.target.closest('[data-sale-doc]');

        if (!doc) return;

        hideModal($('#person-drawer', root));
        openModule('sales', { search: `doc=${doc.dataset.saleDoc}` });
    });
}

/**
 * May this session open that tab?
 *
 * Only matters for the exception feed, which can point at a tab the reader
 * cannot see. Presentation either way — the endpoint behind each tab is guarded
 * server-side regardless (§6.1, §6.2).
 */
function permitted(tab) {
    const requires = TABS[tab].requires;

    return !requires || can(requires[0], requires[1]);
}
