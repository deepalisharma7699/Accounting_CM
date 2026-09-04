import auth from '../auth-client';
import {
    creditPanel, overviewPanel, peoplePanel, salesPanel, stockPanel,
} from '../components/insight-panels';
import { STATEMENTS } from '../components/report-statements';
import { can } from '../permissions';
import { $, $$, downloadCsv, esc, toast } from '../ui';
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

    /* --- inside a panel --------------------------------------------------- */

    /*
    | One delegated listener on the host rather than bindings per render.
    |
    | Every panel is replaced wholesale on each paint, so anything bound to a
    | button inside one would be lost the moment the tab changed — and rebinding
    | after each render is how a listener ends up attached twice.
    */
    panelHost().addEventListener('click', (event) => {
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
