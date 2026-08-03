import auth from '../auth-client';
import { can } from '../permissions';
import {
    $, $$, clearFormErrors, confirmAction, debounce, esc, formatDate, formatMoney,
    hideModal, setSubmitting, showFormErrors, showModal, tableMessage, toast,
} from '../ui';

/**
 * The catalogue, and what is left of it on the shelf.
 *
 * Four things drive the design.
 *
 * **Two modules, one screen.** The item families are M7's and the quantities are
 * M8's, behind separate grants — READ:ITEMS and READ:STOCK. They are fetched
 * separately and joined here, and a user who holds the first without the second
 * loses the stock columns *entirely* rather than seeing them blank: an empty
 * cell reads as "none on the shelf" when it means "not yours to see".
 *
 * **A row is a family, and its stock is the sum of its variants.** The shelf
 * holds a 3 HP motor and a 5 HP motor, not "a motor" — but the catalogue is read
 * by family, so the row rolls its variants up and the drawer breaks them back
 * down. Average cost is rolled up as total value over total quantity rather than
 * as the mean of the variants' averages, which would weight a single spare
 * bearing the same as forty of them.
 *
 * **Negative is not a kind of low.** A low position is a purchasing decision; a
 * negative one means a sale was recorded before the purchase that supplied it,
 * which is a data problem with a different fix. Same rule the stock screen
 * follows, for the same reason: a screen that showed them alike would train
 * people to ignore the second.
 *
 * **The attribute fields are built from the server's schema**, not written here.
 * Which fields a variant has depends on its item's type — HP, phase and RPM for a
 * motor; a gauge for copper wire — and a copy of that mapping in JavaScript is a
 * copy that drifts. The drift shows up as a motor saved without its rating, which
 * is not recoverable by anyone looking at it later.
 */

const PAGE_SIZE = 25;

// Both endpoints cap per_page at 200. The page loads the whole catalogue so that
// searching, the stock filters and the four figures above the table all agree
// with each other — counting "3 low" server-side and then filtering a page
// client-side is how a tile comes to disagree with the rows under it.
const FETCH_SIZE = 200;
const MAX_PAGES = 25;

const state = {
    items: [],           // the whole catalogue, variants included
    stock: new Map(),    // item id -> rolled-up position
    truncated: false,    // the catalogue outgrew MAX_PAGES

    // READ:STOCK. The Activity tab's READ:AUDIT gate is declared on the tab
    // itself and applied by the shell, so it needs no mirror here.
    canStock: false,

    search: '',
    type: '',
    isStock: '',
    isActive: '1',
    pill: 'all',
    onlyDrafts: false,

    sort: { column: 'name', direction: 'asc' },
    page: 1,
    lastPage: 1,

    meta: null,          // types, their attribute schemas, and the units
    openItem: null,      // the item whose drawer is open
    drawerTab: 'overview',
};

/* -------------------------------------------------------------------------
 | Meta
 | ---------------------------------------------------------------------- */

/**
 * The vocabulary of the catalogue, once: what each type is described by, which
 * unit it defaults to, whether it can hold stock, and how many drafts are waiting.
 */
async function loadMeta({ refresh = false } = {}) {
    if (state.meta && !refresh) return state.meta;

    try {
        const { data } = await auth.call('/items/meta');
        state.meta = data;
    } catch {
        // A user without READ:ITEMS never reaches this page's data at all, so an
        // empty schema simply means the forms explain themselves when opened.
        state.meta = { types: [], units: [], draft_counts: { items: 0, variants: 0 } };
    }

    return state.meta;
}

function typeMeta(value) {
    return state.meta?.types?.find((type) => type.value === value) ?? null;
}

function renderDraftBanner() {
    const count = state.meta?.draft_counts?.items ?? 0;
    const banner = $('#draft-banner');

    // Hidden when there is nothing in the queue rather than shown reading "0":
    // a permanent empty banner is a banner people stop seeing.
    banner.classList.toggle('hidden', count === 0);
    banner.classList.toggle('flex', count > 0);

    if (count > 0) {
        $('#draft-banner-title').textContent =
            `${count} item${count === 1 ? '' : 's'} need${count === 1 ? 's' : ''} reviewing`;
    }
}

/* -------------------------------------------------------------------------
 | Fetching
 | ---------------------------------------------------------------------- */

/**
 * Walk a paginated endpoint to the end, or to MAX_PAGES.
 *
 * The cap is a guard, not a page size: a catalogue past 5,000 rows is a
 * different screen, and quietly loading it would turn a fast page into a slow
 * one with no explanation. Hitting it sets `truncated`, which the summary line
 * says out loud.
 */
async function fetchAll(path, params) {
    const rows = [];
    let page = 1;

    for (; page <= MAX_PAGES; page += 1) {
        const query = new URLSearchParams({ ...params, per_page: FETCH_SIZE, page });
        const payload = await auth.call(`${path}?${query}`);

        rows.push(...(payload.data ?? []));

        if (!payload.meta?.pagination?.has_more) return { rows, truncated: false };
    }

    return { rows, truncated: true };
}

/** Every item family, variants included, active and archived alike. */
async function loadCatalogue() {
    // is_active is deliberately not sent: the archived filter is applied here so
    // that switching it never costs another round trip.
    const { rows, truncated } = await fetchAll('/items', { with_variants: 1 });

    state.items = rows;
    state.truncated = truncated;
}

/**
 * What is on the shelf, rolled up from variant positions to their families.
 *
 * Quantities and values are summed as numbers rather than kept as the decimal
 * strings the API sends. That is safe *here* and nowhere else on this page:
 * these figures are displayed and compared, never posted back, and the API
 * remains the only thing that computes a position.
 */
async function loadStock() {
    state.stock = new Map();

    if (!state.canStock) return;

    const { rows } = await fetchAll('/stock', {});

    rows.forEach((row) => {
        const key = row.item_id;

        const roll = state.stock.get(key) ?? {
            quantity: 0,
            value: 0,
            variants: 0,
            low: 0,
            negative: 0,
            out: 0,
            positions: [],
        };

        const quantity = Number(row.quantity ?? 0);

        roll.quantity += quantity;
        roll.value += Number(row.value ?? 0);
        roll.variants += 1;

        if (row.is_negative) roll.negative += 1;
        else if (row.is_low) roll.low += 1;
        else if (!row.has_stock) roll.out += 1;

        roll.positions.push(row);
        state.stock.set(key, roll);
    });
}

/* -------------------------------------------------------------------------
 | Deriving a row
 | ---------------------------------------------------------------------- */

/**
 * The status of one family: the worst thing true of any of its variants.
 *
 * Worst-wins rather than an average, because the question the column answers is
 * "is there anything here I need to do something about". A family with forty
 * bearings and no capacitors is not three-quarters fine.
 */
function statusOf(item) {
    // Checked before anything else, so the column is one thing or the other:
    // either it answers about stock for every row, or it answers about the
    // catalogue for every row. A mix of "Not stocked" and "Active" in one column
    // is two questions sharing a heading.
    if (!state.canStock) return null;
    if (!item.tracks_stock) return 'untracked';

    const roll = state.stock.get(item.id);

    if (!roll || roll.variants === 0) return 'out';
    if (roll.negative > 0) return 'negative';
    if (roll.quantity <= 0) return 'out';
    if (roll.low > 0) return 'low';

    return 'in_stock';
}

const STATUS_BADGE = {
    in_stock: { label: 'In Stock', chip: 'border-emerald-100 bg-emerald-50 text-emerald-700', dot: 'bg-emerald-500' },
    low: { label: 'Low Stock', chip: 'border-amber-100 bg-amber-50 text-amber-700', dot: 'bg-amber-500' },
    out: { label: 'Out of Stock', chip: 'border-rose-100 bg-rose-50 text-rose-600', dot: 'bg-rose-500' },
    // Its own badge, never folded into "low". A negative position is a data
    // problem, and the fix is to find the missing purchase.
    negative: { label: 'Negative', chip: 'border-rose-200 bg-rose-100 text-rose-700', dot: 'bg-rose-600' },
    untracked: { label: 'Not stocked', chip: 'border-border bg-muted text-muted-foreground', dot: 'bg-muted-foreground' },
};

function statusBadge(status) {
    const badge = STATUS_BADGE[status];

    if (!badge) return '';

    return `
        <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5
                     text-[11.5px] font-semibold ${badge.chip}">
            <span class="size-1.5 rounded-full ${badge.dot}"></span>
            ${badge.label}
        </span>`;
}

/** Quantities are decimal strings; "8" reads better than "8.000". */
function formatQty(value) {
    if (value === null || value === undefined) return '—';

    const number = Number(value);

    if (!Number.isFinite(number)) return String(value);

    return number.toLocaleString('en-IN', { maximumFractionDigits: 3 });
}

function money(value) {
    return value === null || value === undefined ? '—' : `₹${formatMoney(value)}`;
}

/** Total value over total quantity — not the mean of the variants' averages. */
function averageCost(item) {
    const roll = state.stock.get(item.id);

    if (!roll || roll.quantity <= 0) return null;

    return (roll.value / roll.quantity).toFixed(2);
}

/**
 * What the family sells for.
 *
 * A range where its variants disagree, because one number would have to pick a
 * variant and the row does not say which. Blank where nothing is priced — a
 * workshop that quotes per job prices nothing here, and a 0.00 would be a lie.
 */
function sellPrice(item) {
    const prices = (item.variants ?? [])
        .map((variant) => variant.sell_price)
        .filter((price) => price !== null && price !== undefined)
        .map(Number)
        .filter(Number.isFinite);

    if (!prices.length) return null;

    const low = Math.min(...prices);
    const high = Math.max(...prices);

    return low === high
        ? money(low.toFixed(2))
        : `${money(low.toFixed(2))} – ${money(high.toFixed(2))}`;
}

/* -------------------------------------------------------------------------
 | Filtering and sorting
 | ---------------------------------------------------------------------- */

function matchesSearch(item, needle) {
    if (!needle) return true;

    const haystack = [
        item.name,
        item.code,
        item.hsn_sac,
        item.type_label,
        // Variant labels and SKUs too: a fitter looking for "1440" is after a
        // motor by its speed, and the family name is the one thing nobody
        // remembers.
        ...(item.variants ?? []).flatMap((variant) => [variant.sku, variant.display_label]),
    ];

    return haystack.some((value) => String(value ?? '').toLowerCase().includes(needle));
}

function matchesPill(item) {
    if (state.pill === 'all') return true;

    if (state.pill === 'recent') {
        const created = Date.parse(item.created_at ?? '');
        const cutoff = Date.now() - 30 * 24 * 60 * 60 * 1000;

        return Number.isFinite(created) && created >= cutoff;
    }

    const status = statusOf(item);

    // "Out of Stock" answers for a negative position too: it is not on the
    // shelf either, and hiding it behind a filter nobody clicks is how a data
    // problem goes unnoticed.
    if (state.pill === 'out') return status === 'out' || status === 'negative';

    return status === state.pill;
}

const SORTERS = {
    name: (item) => item.name?.toLowerCase() ?? '',
    type: (item) => item.type_label?.toLowerCase() ?? '',
    code: (item) => item.code?.toLowerCase() ?? '',
    stock: (item) => state.stock.get(item.id)?.quantity ?? -Infinity,
    cost: (item) => Number(averageCost(item) ?? -Infinity),
    price: (item) => {
        const prices = (item.variants ?? [])
            .map((variant) => Number(variant.sell_price))
            .filter(Number.isFinite);

        return prices.length ? Math.min(...prices) : -Infinity;
    },
    // Sorted by urgency rather than alphabetically: the point of sorting by
    // status is to bring what needs attention to the top.
    status: (item) => ({ negative: 0, out: 1, low: 2, in_stock: 3, untracked: 4 })[statusOf(item)] ?? 5,
};

function visibleRows() {
    const needle = state.search.toLowerCase();

    const rows = state.items.filter((item) => {
        if (!matchesSearch(item, needle)) return false;
        if (state.type && item.type !== state.type) return false;
        if (state.isStock !== '' && item.is_stock !== (state.isStock === '1')) return false;
        if (state.isActive !== '' && item.is_active !== (state.isActive === '1')) return false;
        if (state.onlyDrafts && !item.is_draft) return false;

        return matchesPill(item);
    });

    const pick = SORTERS[state.sort.column] ?? SORTERS.name;
    const factor = state.sort.direction === 'desc' ? -1 : 1;

    return rows.sort((a, b) => {
        const left = pick(a);
        const right = pick(b);

        if (left < right) return -1 * factor;
        if (left > right) return 1 * factor;

        // A stable tiebreak, so two rows with the same quantity do not swap
        // places every time the list is redrawn.
        return String(a.name).localeCompare(String(b.name));
    });
}

/* -------------------------------------------------------------------------
 | Rendering
 | ---------------------------------------------------------------------- */

function render() {
    const rows = visibleRows();
    const lastPage = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));

    state.lastPage = lastPage;
    state.page = Math.min(state.page, lastPage);

    const start = (state.page - 1) * PAGE_SIZE;
    const pageRows = rows.slice(start, start + PAGE_SIZE);
    const columns = state.canStock ? 9 : 6;

    $('#items-body').innerHTML = pageRows.length
        ? pageRows.map(renderRow).join('')
        : tableMessage(columns, emptyMessage());

    renderStats();
    renderSummary(rows.length);
    renderPager();
    renderSortIndicators();

    const filtered = Boolean(state.search) || state.pill !== 'all' || state.type
        || state.isStock !== '' || state.isActive !== '1' || state.onlyDrafts;

    $('#clear-filters').classList.toggle('hidden', !filtered);
    $('#clear-filters').classList.toggle('flex', filtered);
}

function emptyMessage() {
    if (state.onlyDrafts) return 'Nothing left to review.';

    return state.items.length
        ? 'No items match your search or filter.'
        : 'Nothing in the catalogue yet.';
}

function renderRow(item) {
    const status = statusOf(item);
    const roll = state.stock.get(item.id);
    const quantity = roll ? roll.quantity : null;

    const flags = [
        item.is_draft ? '<span class="badge bg-amber-100 text-amber-800">Needs review</span>' : '',
        item.is_active ? '' : '<span class="badge bg-muted text-muted-foreground">Archived</span>',
    ].filter(Boolean).join(' ');

    // Only where the item is meant to hold stock. A dash against a service is
    // noise: an hour of work is produced when it is sold.
    const stockCells = state.canStock
        ? `
            <td class="px-4 py-3">
                <div class="flex items-center gap-1.5">
                    <span class="text-[13px] font-bold ${quantityTone(status)}">
                        ${item.tracks_stock ? formatQty(quantity ?? 0) : '—'}
                    </span>
                    ${item.tracks_stock && (status === 'low' || status === 'out' || status === 'negative')
                        ? `<span class="${status === 'low' ? 'text-amber-400' : 'text-rose-400'}">${iconWarn}</span>`
                        : ''}
                </div>
            </td>
            <td class="px-4 py-3 text-[13px] text-muted-foreground">${esc(item.base_uom_label)}</td>
            <td class="px-4 py-3 text-[13px] text-secondary-foreground">${money(averageCost(item))}</td>
            <td class="px-4 py-3 text-[13px] font-semibold text-foreground">${sellPrice(item) ?? '—'}</td>`
        : `<td class="px-4 py-3 text-[13px] text-muted-foreground">${esc(item.base_uom_symbol)}</td>`;

    return `
        <tr class="group cursor-pointer transition hover:bg-background ${item.is_active ? '' : 'opacity-60'}"
            data-row="${item.id}" tabindex="0" role="link"
            aria-label="Open ${esc(item.name)}">
            <td class="px-4 py-3">
                <div class="flex items-center gap-2.5">
                    <span class="grid size-7 shrink-0 place-items-center rounded-[7px] bg-muted text-muted-foreground">
                        ${iconPackage}
                    </span>
                    <span class="min-w-0">
                        <span class="block truncate text-[13px] font-semibold text-secondary-foreground
                                     transition group-hover:text-primary">${esc(item.name)}</span>
                        ${flags ? `<span class="mt-0.5 flex flex-wrap gap-1">${flags}</span>` : ''}
                    </span>
                </div>
            </td>

            <td class="px-4 py-3">
                <span class="rounded-full bg-muted px-2 py-0.5 text-[12.5px] text-muted-foreground">
                    ${esc(item.type_label)}
                </span>
            </td>

            <td class="px-4 py-3">
                ${item.code
                    ? `<code class="rounded bg-muted px-2 py-0.5 font-mono text-xs text-muted-foreground">${esc(item.code)}</code>`
                    : '<span class="text-[13px] text-muted-foreground">—</span>'}
            </td>

            ${stockCells}

            <td class="px-4 py-3">${statusBadge(status ?? (item.is_active ? 'active' : 'archived')) || catalogueBadge(item)}</td>

            <td class="px-4 py-3">
                <div class="relative flex justify-end" data-menu-host>
                    <button type="button" class="btn btn-ghost btn-icon" data-menu="${item.id}"
                            aria-haspopup="true" aria-expanded="false" aria-label="Actions for ${esc(item.name)}">
                        ${iconMore}
                    </button>
                </div>
            </td>
        </tr>`;
}

/** Where there is no stock to colour by, the row still says active or archived. */
function catalogueBadge(item) {
    return item.is_active
        ? '<span class="badge bg-emerald-50 text-emerald-700">Active</span>'
        : '<span class="badge bg-muted text-muted-foreground">Archived</span>';
}

function quantityTone(status) {
    if (status === 'out' || status === 'negative') return 'text-rose-500';
    if (status === 'low') return 'text-amber-600';

    return 'text-foreground';
}

/**
 * The four figures, counted over the whole catalogue rather than the page.
 *
 * "3 low" has to still say 3 after somebody clicks it, so these deliberately
 * ignore the pill filter — but they do respect the archived filter, because an
 * archived item is not something anyone is going to reorder.
 */
function renderStats() {
    const scope = state.items.filter((item) =>
        state.isActive === '' || item.is_active === (state.isActive === '1'));

    $('#stat-total').textContent = scope.length.toLocaleString('en-IN');

    if (!state.canStock) return;

    const counts = { in_stock: 0, low: 0, out: 0, negative: 0 };

    scope.forEach((item) => {
        const status = statusOf(item);

        if (status in counts) counts[status] += 1;
    });

    $('#stat-in-stock').textContent = counts.in_stock.toLocaleString('en-IN');
    $('#stat-low').textContent = counts.low.toLocaleString('en-IN');
    // Negative positions are counted with "out" here for the same reason the
    // filter includes them: neither is on the shelf.
    $('#stat-out').textContent = (counts.out + counts.negative).toLocaleString('en-IN');

    $$('[data-stat-filter]').forEach((tile) => {
        const on = state.pill === tile.dataset.statFilter;

        tile.classList.toggle('stat-tile-on', on);
        tile.querySelector('[data-stat-chevron]')?.classList.toggle('text-primary', on);
        tile.querySelector('[data-stat-chevron]')?.classList.toggle('text-border', !on);
    });
}

function renderSummary(matched) {
    const total = state.items.length;

    const parts = [`Showing ${matched.toLocaleString('en-IN')} of ${total.toLocaleString('en-IN')} items`];

    if (matched !== total) parts.push('· Filtered');
    if (state.truncated) parts.push(`· first ${total.toLocaleString('en-IN')} loaded`);

    $('#items-summary').textContent = parts.join(' ');
}

function renderPager() {
    const host = $('#items-pager');

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
    $$('#items-head [data-sort]').forEach((th) => {
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

const iconPackage = svg('<path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>', 13);
const iconMore = svg('<circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/>', 15);
const iconWarn = svg('<path d="m21.7 18-8-14a2 2 0 0 0-3.4 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.7-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>', 12);
const iconArrowUp = svg('<path d="m5 12 7-7 7 7"/>', 11);
const iconArrowDown = svg('<path d="m19 12-7 7-7-7"/>', 11);
const iconEye = svg('<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>', 13);
const iconPencil = svg('<path d="M21.17 6.83a2.83 2.83 0 0 0-4-4L3.5 16.5 2 22l5.5-1.5z"/><path d="m15 5 4 4"/>', 13);
const iconLayers = svg('<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m6.08 10.37-3.5 1.6a1 1 0 0 0 0 1.81l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9a1 1 0 0 0 0-1.83l-3.5-1.59"/>', 13);
const iconArchive = svg('<rect x="2" y="3" width="20" height="5" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>', 13);
const iconRestore = svg('<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M12 8v4l3 2"/>', 13);
const iconTrash = svg('<path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>', 13);

/* -------------------------------------------------------------------------
 | The row menu
 | ---------------------------------------------------------------------- */

function closeMenus() {
    $$('[data-row-menu]').forEach((menu) => menu.remove());
    $$('[data-menu]').forEach((button) => button.setAttribute('aria-expanded', 'false'));
}

/**
 * Open the row menu as a fixed layer on the body rather than inside the row.
 *
 * The table scrolls sideways on a narrow screen, and a container that scrolls on
 * one axis clips the other — an absolutely positioned menu inside it would be
 * cut off at the bottom edge of the table, which is exactly where the last row's
 * menu opens. Positioned from the button's rect instead, and flipped upwards
 * when there is no room below.
 */
function openMenu(button, itemId) {
    const item = state.items.find((row) => String(row.id) === String(itemId));
    if (!item) return;

    closeMenus();

    const entries = [
        { label: 'View details', icon: iconEye, action: 'open' },
        { label: 'Variants', icon: iconLayers, action: 'variants' },
    ];

    if (can('UPDATE', 'ITEMS')) {
        entries.push({ label: 'Edit item', icon: iconPencil, action: 'edit' });
        entries.push(item.is_active
            ? { label: 'Archive item', icon: iconArchive, action: 'archive' }
            : { label: 'Restore item', icon: iconRestore, action: 'restore' });
    }

    if (can('DELETE', 'ITEMS')) {
        entries.push({ label: 'Delete item', icon: iconTrash, action: 'delete', danger: true });
    }

    const menu = document.createElement('div');
    menu.className = 'row-menu';
    menu.dataset.rowMenu = '';
    menu.setAttribute('role', 'menu');

    menu.innerHTML = entries.map((entry) => `
        <button type="button" role="menuitem" class="row-menu-item"
                data-action="${entry.action}" data-id="${item.id}"
                ${entry.danger ? 'data-danger' : ''}>
            ${entry.icon}
            ${entry.label}
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

/* -------------------------------------------------------------------------
 | The drawer
 | ---------------------------------------------------------------------- */

async function openDrawer(itemId, { tab = 'overview' } = {}) {
    await loadMeta();

    // Read from the loaded catalogue rather than refetched: the list already
    // holds the item and its variants, and a spinner for something already in
    // memory is a spinner for nothing.
    const item = state.items.find((row) => String(row.id) === String(itemId));
    if (!item) return;

    state.openItem = item;
    state.drawerTab = tab;

    const status = statusOf(item);

    $('#drawer-title').textContent = item.name;
    $('#drawer-subtitle').textContent = [
        item.code,
        item.type_label,
        `counted in ${item.base_uom_label.toLowerCase()}`,
    ].filter(Boolean).join(' · ');

    $('#drawer-status').innerHTML = statusBadge(status) || catalogueBadge(item);

    renderDrawerAlert(item, status);

    $$('#drawer-tabs .tab').forEach((tab_) =>
        tab_.setAttribute('aria-selected', String(tab_.dataset.tab === state.drawerTab)));

    $('#drawer-edit').classList.toggle('hidden', !can('UPDATE', 'ITEMS'));
    $('#drawer-add-variant').classList.toggle('hidden', !can('WRITE', 'ITEMS'));

    showModal('#item-drawer');
    renderDrawerBody();
}

function renderDrawerAlert(item, status) {
    const alert = $('#drawer-alert');
    const roll = state.stock.get(item.id);

    let message = '';

    if (status === 'negative') {
        message = 'More has been issued than was ever received. A sale was probably recorded '
            + 'before the purchase that supplied it.';
    } else if (status === 'out' && item.tracks_stock) {
        message = 'Nothing on the shelf.';
    } else if (status === 'low') {
        message = `${roll.low} variant${roll.low === 1 ? ' is' : 's are'} at or below the reorder level.`;
    } else if (item.is_draft) {
        message = 'Auto-created from an import or a capture and not yet checked.';
    }

    alert.classList.toggle('hidden', message === '');
    alert.classList.toggle('flex', message !== '');
    $('#drawer-alert-text').textContent = message;
}

function renderDrawerBody() {
    const item = state.openItem;
    if (!item) return;

    const body = $('#drawer-body');

    if (state.drawerTab === 'overview') body.innerHTML = drawerOverview(item);
    if (state.drawerTab === 'variants') body.innerHTML = drawerVariants(item);

    if (state.drawerTab === 'history') {
        body.innerHTML = '<p class="py-8 text-center text-sm text-muted-foreground">Loading movements…</p>';
        loadHistory(item);
    }

    if (state.drawerTab === 'activity') {
        body.innerHTML = '<p class="py-8 text-center text-sm text-muted-foreground">Loading activity…</p>';
        loadActivity(item);
    }
}

function drawerOverview(item) {
    const roll = state.stock.get(item.id);
    const status = statusOf(item);

    const hero = state.canStock && item.tracks_stock
        ? `
            <div class="mb-5 rounded-[12px] bg-background p-4">
                <div class="mb-3 flex items-baseline justify-between">
                    <div>
                        <p class="section-label mb-0.5">Current stock</p>
                        <p class="text-[32px] font-bold leading-none text-foreground">
                            ${formatQty(roll?.quantity ?? 0)}
                            <span class="ml-1 text-base font-medium text-muted-foreground">${esc(item.base_uom_symbol)}</span>
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-[11px] text-muted-foreground">Stock value</p>
                        <p class="text-base font-bold text-secondary-foreground">${money(roll ? roll.value.toFixed(2) : null)}</p>
                    </div>
                </div>
                ${roll && roll.variants > 0 ? `
                    <p class="text-[11.5px] text-muted-foreground">
                        Across ${roll.variants} variant${roll.variants === 1 ? '' : 's'}${
                            roll.low > 0 ? ` · ${roll.low} at or below reorder level` : ''}${
                            roll.negative > 0 ? ` · ${roll.negative} negative` : ''}
                    </p>` : ''}
            </div>`
        : '';

    const details = [
        ['Item name', esc(item.name)],
        ['Category', esc(item.type_label)],
        ['Code', item.code ? `<code class="rounded bg-muted px-2 py-0.5 font-mono text-xs">${esc(item.code)}</code>` : '—'],
        [`${esc(item.tax_code_label)} code`, item.hsn_sac ? esc(item.hsn_sac) : '—'],
        ['GST rate', `${esc(item.gst_rate)}%`],
        ['Counted in', esc(item.base_uom_label)],
        ['Keeps stock', item.can_hold_stock ? (item.is_stock ? 'Yes' : 'No') : 'Cannot — a service is produced when sold'],
        ['Variants', String(item.variants?.length ?? item.variant_count ?? 0)],
        ...(state.canStock && item.tracks_stock ? [['Average cost', money(averageCost(item))]] : []),
        ['Selling price', sellPrice(item) ?? '—'],
        ['Status', statusBadge(status) || catalogueBadge(item)],
        ['Added', item.created_at ? esc(formatDate(item.created_at)) : '—'],
    ];

    const notes = item.description
        ? `
            <div class="mt-5">
                <h4 class="section-label mb-3">Notes</h4>
                <p class="rounded-[12px] border border-border px-4 py-3 text-[13px] text-secondary-foreground">
                    ${esc(item.description)}
                </p>
            </div>`
        : '';

    return `
        ${hero}
        <h4 class="section-label mb-3">Item details</h4>
        <div class="divide-y divide-muted overflow-hidden rounded-[12px] border border-border">
            ${details.map(([label, value]) => `
                <div class="flex items-center justify-between gap-3 px-4 py-2.5">
                    <span class="text-[12.5px] text-muted-foreground">${label}</span>
                    <span class="text-right text-[13px] text-secondary-foreground">${value}</span>
                </div>`).join('')}
        </div>
        ${notes}`;
}

/**
 * The variants of one family, each with its own position.
 *
 * This is where the roll-up on the row is broken back down, and it is the reason
 * the row can afford to be a summary: "12 in stock" is only useful if one click
 * says which twelve.
 */
function drawerVariants(item) {
    const variants = item.variants ?? [];
    const mayUpdate = can('UPDATE', 'ITEMS');
    const mayDelete = can('DELETE', 'ITEMS');

    if (!variants.length) {
        return `
            <p class="py-8 text-center text-sm text-muted-foreground">
                No variants yet. ${item.type === 'service'
                    ? 'A service usually needs just one — add it so bills can reference it.'
                    : 'Add the ratings you actually buy and sell.'}
            </p>`;
    }

    const positions = new Map(
        (state.stock.get(item.id)?.positions ?? []).map((row) => [String(row.variant_id), row])
    );

    return `
        <div class="space-y-2">
            ${variants.map((variant) => {
                const position = positions.get(String(variant.id));

                const actions = [
                    mayUpdate ? `<button type="button" class="btn btn-ghost btn-icon" data-edit-variant="${variant.id}"
                                         title="Edit variant" aria-label="Edit variant">${iconPencil}</button>` : '',
                    mayDelete ? `<button type="button" class="btn btn-ghost btn-icon" data-delete-variant="${variant.id}"
                                         title="Delete variant" aria-label="Delete variant">${iconTrash}</button>` : '',
                ].filter(Boolean).join('');

                const quantity = state.canStock && position
                    ? `
                        <div class="text-right">
                            <p class="text-[13px] font-bold ${
                                position.is_negative || !position.has_stock ? 'text-rose-500'
                                    : position.is_low ? 'text-amber-600' : 'text-foreground'}">
                                ${formatQty(position.quantity)}
                                <span class="text-[11px] font-medium text-muted-foreground">${esc(item.base_uom_symbol)}</span>
                            </p>
                            <p class="text-[11.5px] text-muted-foreground">
                                ${position.reorder_level !== null && position.reorder_level !== undefined
                                    ? `reorder at ${formatQty(position.reorder_level)}`
                                    : 'no reorder level'}
                            </p>
                        </div>`
                    : '';

                return `
                    <div class="flex items-center gap-3 rounded-[12px] border border-border px-4 py-3
                                ${variant.is_active ? '' : 'opacity-60'}">
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-2 text-[13.5px] font-semibold text-secondary-foreground">
                                ${esc(variant.display_label)}
                                ${variant.is_draft ? '<span class="badge bg-amber-100 text-amber-800">Needs review</span>' : ''}
                                ${variant.is_active ? '' : '<span class="badge bg-muted text-muted-foreground">Archived</span>'}
                            </p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                ${variant.sku ? `<span class="font-mono">${esc(variant.sku)}</span> · ` : ''}
                                ${variant.sell_price === null ? 'no price' : money(variant.sell_price)}
                            </p>
                        </div>
                        ${quantity}
                        <div class="flex shrink-0 gap-1">${actions}</div>
                    </div>`;
            }).join('')}
        </div>`;
}

/**
 * Stock movements behind one family.
 *
 * A card is per variant, so this asks for one per variant and merges them by
 * date. That is a handful of requests for a family with a handful of variants,
 * which is what a family has — and the alternative, showing one variant and
 * pretending it is the item, is the kind of half-answer people stop trusting.
 */
async function loadHistory(item) {
    const variants = (item.variants ?? []).filter((variant) => variant.is_active);
    const body = $('#drawer-body');

    if (!item.tracks_stock) {
        body.innerHTML = `
            <p class="py-8 text-center text-sm text-muted-foreground">
                This item does not keep stock, so nothing moves through it.
            </p>`;

        return;
    }

    if (!variants.length) {
        body.innerHTML = '<p class="py-8 text-center text-sm text-muted-foreground">No variants to move yet.</p>';

        return;
    }

    try {
        const cards = await Promise.all(variants.map(async (variant) => {
            const { data } = await auth.call(`/stock/variants/${variant.id}?per_page=25`);

            return (data.movements ?? []).map((movement) => ({ movement, variant }));
        }));

        // Guard against a tab switch that happened while these were in flight.
        if (state.drawerTab !== 'history' || state.openItem?.id !== item.id) return;

        const rows = cards.flat().sort((a, b) =>
            String(b.movement.date ?? '').localeCompare(String(a.movement.date ?? '')));

        body.innerHTML = rows.length ? historyTable(rows, item) : `
            <p class="py-8 text-center text-sm text-muted-foreground">
                Nothing has moved yet. Stock arrives when you record a purchase or a count.
            </p>`;
    } catch (error) {
        body.innerHTML = `<p class="py-8 text-center text-sm text-rose-600">${esc(error.message)}</p>`;
    }
}

const MOVEMENT_CHIP = {
    in: 'bg-blue-50 text-blue-700',
    out: 'bg-rose-50 text-rose-600',
    adjust: 'bg-violet-50 text-violet-700',
    opening: 'bg-muted text-muted-foreground',
};

function historyTable(rows, item) {
    return `
        <h4 class="section-label mb-3">Stock movement history</h4>
        <div class="overflow-hidden rounded-[12px] border border-border">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="border-b border-border bg-background text-left">
                        ${['Date', 'Type', 'Variant', 'Qty'].map((label) =>
                            `<th class="px-3 py-2.5 text-[11px] font-semibold whitespace-nowrap text-muted-foreground">${label}</th>`
                        ).join('')}
                    </tr>
                </thead>
                <tbody class="divide-y divide-muted">
                    ${rows.map(({ movement, variant }) => {
                        const quantity = Number(movement.quantity ?? 0);
                        const chip = MOVEMENT_CHIP[movement.type] ?? 'bg-muted text-muted-foreground';

                        return `
                            <tr class="transition hover:bg-background">
                                <td class="px-3 py-2.5 text-xs whitespace-nowrap text-muted-foreground">
                                    ${esc(formatDate(movement.date))}
                                </td>
                                <td class="px-3 py-2.5">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5
                                                 text-[11.5px] font-semibold ${chip}">
                                        ${esc(movement.type_label ?? movement.type ?? '—')}
                                    </span>
                                </td>
                                <td class="px-3 py-2.5 text-xs text-muted-foreground">
                                    ${esc(variant.display_label)}
                                </td>
                                <td class="px-3 py-2.5 text-[13px] font-semibold whitespace-nowrap
                                           ${quantity < 0 ? 'text-rose-500' : 'text-emerald-600'}">
                                    ${quantity > 0 ? '+' : ''}${formatQty(movement.quantity)}
                                    <span class="text-[11px] font-medium text-muted-foreground">${esc(item.base_uom_symbol)}</span>
                                </td>
                            </tr>`;
                    }).join('')}
                </tbody>
            </table>
        </div>`;
}

/** Who changed this item, and when. M13's answer, not a second copy of it. */
async function loadActivity(item) {
    const body = $('#drawer-body');

    try {
        const payload = await auth.call(`/audit-logs?resource=item&resource_id=${item.id}&per_page=25`);

        if (state.drawerTab !== 'activity' || state.openItem?.id !== item.id) return;

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
                                <p class="text-xs text-muted-foreground">
                                    ${esc(row.actor?.name ?? 'System')}
                                </p>
                                <p class="mt-0.5 text-[11.5px] text-muted-foreground">
                                    ${esc(formatDate(row.at))}
                                </p>
                            </div>
                        </div>`).join('')}
                </div>
            </div>` : '<p class="py-8 text-center text-sm text-muted-foreground">No recorded activity yet.</p>';
    } catch (error) {
        body.innerHTML = `<p class="py-8 text-center text-sm text-rose-600">${esc(error.message)}</p>`;
    }
}

/* -------------------------------------------------------------------------
 | The item form
 | ---------------------------------------------------------------------- */

/**
 * Reflect the chosen type into the rest of the form.
 *
 * The type decides three things, and saying so as the user picks it is much better
 * than refusing the save afterwards: which word the tax code goes by, which unit
 * it is counted in, and whether stock is even possible.
 */
function applyTypeToForm({ editing }) {
    const type = typeMeta($('#item-type').value);
    if (!type) return;

    $('#item-hsn-label').textContent = `${type.tax_code_label} code`;

    if (!editing) {
        $('#item-uom').value = type.default_uom;
    }

    const canHoldStock = type.can_hold_stock;
    const checkbox = $('#item-stock');

    checkbox.disabled = !canHoldStock;

    if (!canHoldStock) checkbox.checked = false;

    $('#item-stock-hint').textContent = canHoldStock
        ? 'Turn this off for something you buy to order and never hold.'
        : 'A service cannot be held in stock — an hour is produced when it is sold.';

    $('#item-type-hint').textContent = editing
        ? 'Fixed once the item exists: changing it would reinterpret everything recorded against it.'
        : `Described by ${describeAttributes(type)}.`;
}

function describeAttributes(type) {
    const keys = Object.keys(type.attributes ?? {});

    return keys.length
        ? keys.map((key) => type.attributes[key].label.toLowerCase()).join(', ')
        : 'nothing — a service has no variations';
}

async function openItemForm(item = null) {
    await loadMeta();

    const form = $('#item-form');
    const editing = item !== null;

    clearFormErrors(form);
    form.reset();

    $('#item-modal-title').textContent = editing ? `Edit ${item.name}` : 'Add New Item';
    $('#item-modal-subtitle').textContent = editing
        ? 'Type and unit are fixed once an item exists.'
        : 'Fill in the details for the new catalogue item.';

    form.elements.id.value = editing ? item.id : '';

    $('#item-name').value = editing ? item.name : '';
    $('#item-code').value = editing ? (item.code ?? '') : '';
    $('#item-hsn').value = editing ? (item.hsn_sac ?? '') : '';
    $('#item-gst').value = editing ? item.gst_rate : '';
    $('#item-type').value = editing ? item.type : ($('#item-type').options[0]?.value ?? '');
    $('#item-uom').value = editing ? item.base_uom : '';
    $('#item-stock').checked = editing ? item.is_stock : true;
    $('#item-description').value = editing ? (item.description ?? '') : '';

    // Both are fixed once the item exists, and disabled rather than hidden so the
    // record still reads completely.
    $('#item-type').disabled = editing;
    $('#item-uom').disabled = editing;

    applyTypeToForm({ editing });
    showModal('#item-modal');
}

async function submitItem() {
    const form = $('#item-form');
    const id = form.elements.id.value;

    clearFormErrors(form);

    if (!$('#item-name').value.trim()) {
        showFormErrors(form, {
            fields: { name: ['Give the item a name.'] },
            message: 'Give the item a name.',
        });

        return;
    }

    const body = {
        name: $('#item-name').value.trim(),
        code: $('#item-code').value.trim() || null,
        hsn_sac: $('#item-hsn').value.trim() || null,
        gst_rate: $('#item-gst').value.trim() || '0',
        is_stock: $('#item-stock').checked,
        description: $('#item-description').value.trim() || null,
    };

    // Sent only on create. Both are fixed afterwards, and the server ignores them
    // on a PATCH — sending them anyway would suggest they had been applied.
    if (!id) {
        body.type = $('#item-type').value;
        body.base_uom = $('#item-uom').value;
    }

    setSubmitting(form, true);

    try {
        if (id) {
            await auth.call(`/items/${id}`, { method: 'PATCH', body });
        } else {
            await auth.call('/items', { method: 'POST', body });
        }

        hideModal('#item-modal');
        toast(id ? 'Item updated.' : 'Item created.');
        await refresh({ keepPage: true });
    } catch (error) {
        showFormErrors(form, error);
    } finally {
        setSubmitting(form, false);
    }
}

/* -------------------------------------------------------------------------
 | Variants
 | ---------------------------------------------------------------------- */

/**
 * Build the attribute inputs from the server's schema for this item's type.
 *
 * A select where the values are genuinely fixed — a motor's phase is 1 or 3 — and a
 * text box where the range is open, because pinning frame size to a list would make
 * the product wrong about the next frame.
 */
function renderAttributeFields(item, values = {}) {
    const type = typeMeta(item.type);
    const schema = type?.attributes ?? {};
    const host = $('#variant-attributes');

    const keys = Object.keys(schema);

    if (!keys.length) {
        host.innerHTML = `
            <p class="sm:col-span-2 rounded-[10px] border border-border bg-secondary/40 px-3.5 py-2.5
                      text-[0.8125rem] text-secondary-foreground">
                A ${esc((type?.label ?? item.type_label).toLowerCase())} has no variations to describe —
                an hour of work is an hour of work.
            </p>`;

        return;
    }

    host.innerHTML = keys.map((key) => {
        const field = schema[key];
        const value = values[key] ?? '';
        const suffix = field.suffix ? ` <span class="font-normal text-muted-foreground">(${esc(field.suffix)})</span>` : '';

        const input = field.values
            ? `<select class="field-input" data-attribute="${esc(key)}">
                   <option value="">Choose…</option>
                   ${field.values.map((option) => `
                       <option value="${esc(option)}" ${String(option) === String(value) ? 'selected' : ''}>
                           ${esc(option)}
                       </option>`).join('')}
               </select>`
            : `<input type="text" class="field-input" data-attribute="${esc(key)}"
                      value="${esc(value)}" autocomplete="off">`;

        return `
            <div>
                <label class="field-label">
                    ${esc(field.label)}${suffix}
                    ${field.required ? '' : '<span class="font-normal text-muted-foreground">(optional)</span>'}
                </label>
                ${input}
            </div>`;
    }).join('');
}

function collectAttributes() {
    const bag = {};

    $$('[data-attribute]', $('#variant-attributes')).forEach((input) => {
        const value = input.value.trim();

        // Blank is absent, not "". A form submits every field it renders, and
        // storing an untouched box would be noise every reader has to filter out.
        if (value !== '') bag[input.dataset.attribute] = value;
    });

    return bag;
}

async function openVariantForm(variant = null) {
    const item = state.openItem;
    if (!item) return;

    const form = $('#variant-form');

    clearFormErrors(form);
    form.reset();

    const editing = variant !== null;

    $('#variant-modal-title').textContent = editing
        ? `Edit ${variant.display_label}`
        : `New variant of ${item.name}`;

    form.elements.id.value = editing ? variant.id : '';
    form.elements.item_id.value = item.id;

    $('#variant-sku').value = editing ? (variant.sku ?? '') : '';
    $('#variant-label').value = editing ? (variant.label ?? '') : '';
    $('#variant-price').value = editing ? (variant.sell_price ?? '') : '';
    $('#variant-markup').value = editing ? (variant.markup_percent ?? '') : '';
    $('#variant-reorder').value = editing ? (variant.reorder_level ?? '') : '';

    $('#variant-reorder-unit').textContent = item.base_uom_symbol;
    // A reorder level is meaningless for something that is never held.
    $('#variant-reorder-field').classList.toggle('hidden', !item.tracks_stock);

    renderAttributeFields(item, editing ? variant.attributes : {});

    showModal('#variant-modal');
}

async function submitVariant() {
    const form = $('#variant-form');
    const id = form.elements.id.value;
    const itemId = form.elements.item_id.value;

    clearFormErrors(form);

    const body = {
        attributes: collectAttributes(),
        sku: $('#variant-sku').value.trim() || null,
        label: $('#variant-label').value.trim() || null,
        sell_price: $('#variant-price').value.trim() || null,
        markup_percent: $('#variant-markup').value.trim() || null,
        reorder_level: $('#variant-reorder').value.trim() || null,
    };

    setSubmitting(form, true);

    try {
        const response = id
            ? await auth.call(`/items/${itemId}/variants/${id}`, { method: 'PATCH', body })
            : await auth.call(`/items/${itemId}/variants`, { method: 'POST', body });

        hideModal('#variant-modal');
        toast(id ? 'Variant updated.' : 'Variant added.');

        // A second variant at the same specification is saved and reported, not
        // refused: two brands at one rating is a real arrangement, but the far
        // commoner cause is the same thing entered twice.
        const warning = response.meta?.warnings?.[0];
        if (warning) toast(warning.message, 'info');

        await refresh({ keepPage: true });
        await openDrawer(itemId, { tab: 'variants' });
    } catch (error) {
        showFormErrors(form, error);
    } finally {
        setSubmitting(form, false);
    }
}

async function deleteVariant(variantId) {
    const item = state.openItem;
    if (!item) return;

    const confirmed = await confirmAction({
        title: 'Delete this variant',
        body: 'Nothing has been recorded against it yet, so there is nothing to lose. '
            + 'Once it appears on a bill you will archive it instead.',
        confirmLabel: 'Delete variant',
    });

    if (!confirmed) return;

    try {
        await auth.call(`/items/${item.id}/variants/${variantId}`, { method: 'DELETE' });
        toast('Variant deleted.');

        await refresh({ keepPage: true });
        await openDrawer(item.id, { tab: 'variants' });
    } catch (error) {
        toast(error.message, 'error');
    }
}

/* -------------------------------------------------------------------------
 | Row actions
 | ---------------------------------------------------------------------- */

async function setActive(id, isActive) {
    const confirmed = isActive || await confirmAction({
        title: 'Archive this item',
        body: 'It stops appearing when you choose an item, and everything already recorded against it '
            + 'stays exactly as it is. You can restore it at any time.',
        confirmLabel: 'Archive item',
    });

    if (!confirmed) return;

    try {
        await auth.call(`/items/${id}`, { method: 'PATCH', body: { is_active: isActive } });
        toast(isActive ? 'Item restored.' : 'Item archived.');
        await refresh({ keepPage: true });
    } catch (error) {
        toast(error.message, 'error');
    }
}

async function destroy(id) {
    const confirmed = await confirmAction({
        title: 'Delete this item',
        body: 'Only possible while nothing points at it. If you have dealt in it, archive it instead so '
            + 'its history keeps the name that explains it.',
        confirmLabel: 'Delete item',
    });

    if (!confirmed) return;

    try {
        await auth.call(`/items/${id}`, { method: 'DELETE' });
        toast('Item deleted.');
        hideModal('#item-drawer');
        await refresh();
    } catch (error) {
        toast(error.message, 'error');
    }
}

/* -------------------------------------------------------------------------
 | Toolbar
 | ---------------------------------------------------------------------- */

const SORT_OPTIONS = [
    { column: 'name', label: 'Name' },
    { column: 'type', label: 'Category' },
    { column: 'code', label: 'Code' },
    { column: 'stock', label: 'Stock', stock: true },
    { column: 'cost', label: 'Average cost', stock: true },
    { column: 'price', label: 'Selling price', stock: true },
    { column: 'status', label: 'Status' },
];

function renderSortPanel() {
    $('#sort-panel').innerHTML = SORT_OPTIONS
        .filter((option) => !option.stock || state.canStock)
        .flatMap((option) => ['asc', 'desc'].map((direction) => {
            const on = state.sort.column === option.column && state.sort.direction === direction;

            return `
                <button type="button" role="menuitem" class="row-menu-item ${on ? 'text-primary' : ''}"
                        data-sort-option="${option.column}" data-sort-direction="${direction}">
                    ${direction === 'asc' ? iconArrowUp : iconArrowDown}
                    ${option.label} ${direction === 'asc' ? '(A–Z)' : '(Z–A)'}
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
        state.type !== '',
        state.isStock !== '',
        state.isActive !== '1',
    ].filter(Boolean).length;

    const badge = $('#filter-count');

    badge.textContent = active;
    badge.classList.toggle('hidden', active === 0);
}

function setPill(pill) {
    // Clicking the applied filter clears it, which is what a toggle means and
    // what the tiles look like they do.
    state.pill = state.pill === pill ? 'all' : pill;
    state.page = 1;

    $$('#filter-pills [data-pill]').forEach((button) =>
        button.setAttribute('aria-pressed', String(button.dataset.pill === state.pill)));

    render();
}

function clearFilters() {
    state.search = '';
    state.type = '';
    state.isStock = '';
    state.isActive = '1';
    state.pill = 'all';
    state.onlyDrafts = false;
    state.page = 1;

    $('#filter-search').value = '';
    $('#filter-type').value = '';
    $('#filter-stock').value = '';
    $('#filter-status').value = '1';
    $('#draft-banner').classList.remove('ring-2', 'ring-amber-300');

    $$('#filter-pills [data-pill]').forEach((button) =>
        button.setAttribute('aria-pressed', String(button.dataset.pill === 'all')));

    renderFilterCount();
    render();
}

/**
 * Strip the stock columns for a user who cannot read stock.
 *
 * Removed rather than blanked. A dash in an "Avg Cost" column reads as "nothing
 * on the shelf"; an absent column reads as what it is.
 */
function applyStockVisibility() {
    if (state.canStock) return;

    $$('[data-stock-only]').forEach((el) => el.remove());
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

async function refresh({ keepPage = false } = {}) {
    if (!keepPage) state.page = 1;

    // Re-fetched because a save may have cleared a draft flag, and a stale badge
    // is worse than no badge.
    await loadMeta({ refresh: true });
    renderDraftBanner();

    await Promise.all([loadCatalogue(), loadStock()]);

    // The open drawer points at an object that has just been replaced.
    if (state.openItem) {
        state.openItem = state.items.find((row) => row.id === state.openItem.id) ?? null;
    }

    render();
}

export default async function initItems() {
    state.canStock = can('READ', 'STOCK');

    applyStockVisibility();
    renderSortPanel();
    renderFilterCount();

    const columns = state.canStock ? 9 : 6;
    $('#items-body').innerHTML = tableMessage(columns, 'Loading items…');

    await loadMeta();
    renderDraftBanner();

    try {
        await Promise.all([loadCatalogue(), loadStock()]);
        render();
    } catch (error) {
        // A platform super-admin holds every permission but belongs to no
        // workshop, so they can reach this page by typing the URL and there is
        // nothing to show them. Not their mistake — say so plainly.
        $('#items-body').innerHTML = error.code === 'NO_WORKSPACE'
            ? tableMessage(columns, 'Your account administers the platform rather than a single workshop, so it has no catalogue of its own.')
            : tableMessage(columns, error.message, 'error');

        $('#items-summary').textContent = '';
        $('#new-item')?.classList.add('hidden');
    }

    /* Toolbar ---------------------------------------------------------- */

    $('#new-item')?.addEventListener('click', () => openItemForm());

    $('#filter-search').addEventListener('input', debounce((event) => {
        state.search = event.target.value.trim();
        state.page = 1;
        render();
    }, 200));

    $('#filter-toggle').addEventListener('click', (event) => {
        event.stopPropagation();

        const panel = $('#filter-panel');
        const open = panel.classList.toggle('hidden');

        $('#filter-toggle').setAttribute('aria-expanded', String(!open));
        $('#sort-panel').classList.add('hidden');
    });

    $('#sort-toggle').addEventListener('click', (event) => {
        event.stopPropagation();

        const panel = $('#sort-panel');
        const open = panel.classList.toggle('hidden');

        $('#sort-toggle').setAttribute('aria-expanded', String(!open));
        $('#filter-panel').classList.add('hidden');
    });

    $('#sort-panel').addEventListener('click', (event) => {
        const option = event.target.closest('[data-sort-option]');
        if (!option) return;

        applySort(option.dataset.sortOption, option.dataset.sortDirection);
        $('#sort-panel').classList.add('hidden');
        $('#sort-toggle').setAttribute('aria-expanded', 'false');
    });

    ['filter-type', 'filter-stock', 'filter-status'].forEach((id) => {
        $(`#${id}`)?.addEventListener('change', (event) => {
            const key = { 'filter-type': 'type', 'filter-stock': 'isStock', 'filter-status': 'isActive' }[id];

            state[key] = event.target.value;
            state.page = 1;

            renderFilterCount();
            render();
        });
    });

    $('#filter-pills').addEventListener('click', (event) => {
        const pill = event.target.closest('[data-pill]');

        if (pill) setPill(pill.dataset.pill);
    });

    $$('[data-stat-filter]').forEach((tile) =>
        tile.addEventListener('click', () => setPill(tile.dataset.statFilter)));

    $('#clear-filters').addEventListener('click', clearFilters);

    $('#draft-banner').addEventListener('click', () => {
        state.onlyDrafts = !state.onlyDrafts;
        $('#draft-banner').classList.toggle('ring-2', state.onlyDrafts);
        $('#draft-banner').classList.toggle('ring-amber-300', state.onlyDrafts);
        state.page = 1;
        render();
    });

    $('#items-head').addEventListener('click', (event) => {
        const th = event.target.closest('[data-sort]');

        if (th) applySort(th.dataset.sort);
    });

    $('#items-pager').addEventListener('click', (event) => {
        const button = event.target.closest('[data-page]');
        if (!button) return;

        state.page = Number(button.dataset.page);
        render();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    /* The table -------------------------------------------------------- */

    $('#items-body').addEventListener('click', (event) => {
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

    // The menu itself is a fixed layer on the body, not a child of the row, so
    // its clicks are caught here rather than by the table's handler.
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

    // A row is a link, so it answers to the keyboard like one.
    $('#items-body').addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;

        const row = event.target.closest('[data-row]');

        if (row) {
            event.preventDefault();
            openDrawer(row.dataset.row);
        }
    });

    /* The drawer ------------------------------------------------------- */

    $('#drawer-tabs').addEventListener('click', (event) => {
        const tab = event.target.closest('[data-tab]');
        if (!tab) return;

        state.drawerTab = tab.dataset.tab;

        $$('#drawer-tabs .tab').forEach((other) =>
            other.setAttribute('aria-selected', String(other.dataset.tab === state.drawerTab)));

        renderDrawerBody();
    });

    $('#drawer-body').addEventListener('click', (event) => {
        const edit = event.target.closest('[data-edit-variant]');
        const remove = event.target.closest('[data-delete-variant]');

        if (edit) {
            const variant = (state.openItem?.variants ?? [])
                .find((row) => String(row.id) === edit.dataset.editVariant);

            if (variant) openVariantForm(variant);
        }

        if (remove) deleteVariant(remove.dataset.deleteVariant);
    });

    $('#drawer-edit').addEventListener('click', () => {
        if (state.openItem) openItemForm(state.openItem);
    });

    $('#drawer-add-variant').addEventListener('click', () => openVariantForm());

    /* Forms ------------------------------------------------------------ */

    $('#item-form').addEventListener('submit', (event) => {
        event.preventDefault();
        submitItem();
    });

    $('#item-type').addEventListener('change', () => applyTypeToForm({ editing: false }));

    $('#variant-form').addEventListener('submit', (event) => {
        event.preventDefault();
        submitVariant();
    });

    // One listener for every "click away to dismiss": the row menus and both
    // toolbar popovers.
    document.addEventListener('click', () => {
        closeMenus();
        $('#filter-panel').classList.add('hidden');
        $('#sort-panel').classList.add('hidden');
        $('#filter-toggle').setAttribute('aria-expanded', 'false');
        $('#sort-toggle').setAttribute('aria-expanded', 'false');
    });

    $('#filter-panel').addEventListener('click', (event) => event.stopPropagation());
}

/** The row menu's entries, in one place so the menu markup stays declarative. */
function runAction(action, id) {
    const item = state.items.find((row) => String(row.id) === String(id));
    if (!item) return;

    if (action === 'open') openDrawer(id);
    if (action === 'variants') openDrawer(id, { tab: 'variants' });
    if (action === 'edit') openItemForm(item);
    if (action === 'archive') setActive(id, false);
    if (action === 'restore') setActive(id, true);
    if (action === 'delete') destroy(id);
}
