import auth from '../auth-client';
import { formatQuantity } from '../components/badge';
import {
    averageCostOf, positionStatus, rollUpPositions, stockStatusBadge,
} from '../components/stock-position';
import {
    $, $$, clearFormErrors, debounce, downloadCsv, esc, formatDate, formatMoney,
    hideModal, setSubmitting, showFormErrors, showModal, tableMessage, toast,
} from '../ui';
import { mountWorkspace } from '../workspace';

/**
 * The inventory menu — what is on the shelf, right now.
 *
 * Five things drive the design.
 *
 * **It opens on its list and has no create form.** §2A.10 names Stock as
 * read-mostly and this is the plainest case of it: nothing is created here, so
 * `canCreate: false` lands the module straight on the table and the workspace
 * paints no "Show list" switch. The frame is still the shared one — the heading,
 * the Escape handling and the URL sync are not this module's to reinvent.
 *
 * **There is no way to edit a quantity.** The only control that changes stock is
 * "Record a count", and it posts a transaction like any other. A field that wrote
 * a position directly would be a second write path, and everything M8 guarantees
 * rests on there not being one.
 *
 * **A row is an item and its variants open underneath it.** The API answers by
 * variant, which is the truth of the ledger — but a workshop with four variants
 * per item scrolls four times as far if each is a top-level row. So the rows are
 * grouped, the item carries the rolled-up position, and it is a *variant* that
 * opens the stock card: movements belong to a variant, not to a family.
 *
 * **The whole matched set is fetched, then paged here.** Grouping cannot be done
 * on a page the database chose — an item's variants would straddle the boundary
 * and the row would report half a position. It is also what lets the tiles, the
 * pills and the export agree with the rows underneath them, and it is the same
 * trade the Items screen makes for the same reason.
 *
 * **Negative is not a kind of low.** A low position is a purchasing decision; a
 * negative one means a sale was recorded before the purchase that supplied it,
 * which is a data problem with a different fix. Separate tiles, separate pills
 * and separate colours, because a screen that showed them alike would train
 * people to ignore the second.
 */

/** Items per page, once the variants have been grouped under them. */
const PAGE_SIZE = 25;

/** The endpoint caps `per_page` at 200; ten pages of that is a large workshop. */
const FETCH_SIZE = 200;
const MAX_PAGES = 10;

const SORT_OPTIONS = [
    { column: 'name', label: 'Item name' },
    { column: 'quantity', label: 'Quantity on hand' },
    { column: 'value', label: 'Stock value' },
    { column: 'cost', label: 'Average cost' },
];

const state = {
    /** Every variant position the server filters matched. */
    rows: [],
    /** Those rows grouped into one entry per item. */
    groups: [],
    /** The server's tallies, over the text-filtered set — see {@link render}. */
    totals: {},
    /** The catalogue outgrew MAX_PAGES and the figures are of a prefix. */
    truncated: false,

    /* Server-side filters — a change to any of these refetches. */
    search: '',
    categoryId: '',
    isActive: '1',

    /* Client-side — the rows are already held, so these only repaint (§3.6). */
    status: '',
    sort: { column: 'name', direction: 'asc' },
    page: 1,
    lastPage: 1,
    expanded: new Set(),

    loading: false,
    /** Set when a load failed, so a repaint does not overwrite the message. */
    failure: null,
};

/* -------------------------------------------------------------------------
 | Loading
 | ---------------------------------------------------------------------- */

function query(page) {
    const params = new URLSearchParams();

    if (state.search) params.set('search', state.search);
    if (state.categoryId) params.set('category_id', state.categoryId);
    // Archived variants keep their stock, so "include archived" is `is_active=0`
    // meaning "do not restrict", which is what the endpoint documents.
    if (state.isActive === '0') params.set('is_active', '0');

    params.set('per_page', FETCH_SIZE);
    params.set('page', page);

    return params;
}

/**
 * Every variant the current server-side filters match.
 *
 * Paged through rather than asked for in one request because the endpoint caps
 * a page at 200. The cap on the number of pages is deliberate: a runaway loop
 * against a report that is recomputed per request is worse than a screen that
 * says it is showing the first two thousand.
 */
async function fetchAll() {
    const rows = [];
    let totals = {};
    let truncated = false;

    for (let page = 1; page <= MAX_PAGES; page += 1) {
        // Sequential by nature: whether there is another page is the answer
        // to this one.
        const payload = await auth.call(`/stock?${query(page)}`);

        rows.push(...(payload.data ?? []));

        // Identical on every page — the server computes them over the whole
        // matched set, not over the slice it returned.
        totals = payload.meta?.totals ?? totals;

        if (!payload.meta?.pagination?.has_more) return { rows, totals, truncated };

        truncated = page === MAX_PAGES;
    }

    return { rows, totals, truncated };
}

async function load() {
    state.loading = true;
    state.failure = null;

    $('#stock-body').innerHTML = tableMessage(7, 'Counting…');

    try {
        const { rows, totals, truncated } = await fetchAll();

        state.rows = rows;
        state.totals = totals;
        state.truncated = truncated;
        state.groups = groupByItem(rows);
        state.page = 1;
    } catch (error) {
        state.rows = [];
        state.groups = [];
        state.totals = {};

        // A platform super-admin holds every grant and owns no books. Their
        // request is well formed; there is simply nothing to show them.
        state.failure = error.code === 'NO_WORKSPACE'
            ? { message: 'Your account administers the platform rather than a single workshop, so it has no stock of its own.', tone: 'muted' }
            : { message: error.message, tone: 'error' };
    } finally {
        state.loading = false;
    }

    render();
}

/**
 * One entry per item, carrying its variants and their rolled-up position.
 *
 * A variant whose item did not come back — which the API allows, since `item` is
 * nullable — is grouped under itself rather than dropped. A position nobody can
 * see is how stock goes missing quietly.
 */
function groupByItem(rows) {
    const map = new Map();

    rows.forEach((row) => {
        // A string, because it comes back off `dataset.group` as one and a
        // Set of numbers would never match it.
        const key = String(row.item?.id ?? `orphan-${row.variant_id}`);

        if (!map.has(key)) {
            map.set(key, { key, item: row.item, variants: [] });
        }

        map.get(key).variants.push(row);
    });

    return [...map.values()].map((group) => ({
        ...group,
        roll: rollUpPositions(group.variants),
    }));
}

/* -------------------------------------------------------------------------
 | Narrowing and ordering — both over rows already held
 | ---------------------------------------------------------------------- */

/**
 * Whether one variant is in the state the pills are asking for.
 *
 * Read off the flags the server sent rather than recomputed from the quantity:
 * "low" depends on a reorder level this screen never sees, and guessing at it
 * would be a second definition of low stock.
 */
function matchesStatus(row) {
    switch (state.status) {
        case 'negative': return row.is_negative;
        case 'low': return row.is_low && !row.is_negative;
        case 'out': return !row.has_stock && !row.is_negative;
        case 'in_stock': return row.has_stock && !row.is_low && !row.is_negative;
        default: return true;
    }
}

/**
 * The groups the current status filter leaves, each narrowed to the variants
 * that match.
 *
 * The *group* is narrowed too, not just marked — asking for what is running low
 * and being shown a full shelf with one amber row in it is not an answer.
 */
function visibleGroups() {
    const groups = state.status
        ? state.groups
            .map((group) => {
                const variants = group.variants.filter(matchesStatus);

                return { ...group, variants, roll: rollUpPositions(variants) };
            })
            .filter((group) => group.variants.length)
        : state.groups;

    return sortGroups(groups);
}

const SORT_KEYS = {
    name: (group) => (group.item?.name ?? '').toLowerCase(),
    quantity: (group) => group.roll.quantity,
    value: (group) => group.roll.value,
    cost: (group) => Number(averageCostOf(group.roll) ?? 0),
};

/**
 * Ordered here rather than by the endpoint's `sort`, and deliberately.
 *
 * The rows are grouped after they arrive, so an item's place in the list depends
 * on a figure — its rolled-up quantity — that the server never computed. Asking
 * the API to sort as well would be a second ordering that disagrees with this
 * one as soon as an item has more than one variant.
 */
function sortGroups(groups) {
    const key = SORT_KEYS[state.sort.column] ?? SORT_KEYS.name;
    const factor = state.sort.direction === 'desc' ? -1 : 1;

    return [...groups].sort((a, b) => {
        const left = key(a);
        const right = key(b);

        if (left === right) return SORT_KEYS.name(a).localeCompare(SORT_KEYS.name(b));

        return (left > right ? 1 : -1) * factor;
    });
}

/* -------------------------------------------------------------------------
 | Painting
 | ---------------------------------------------------------------------- */

function money(value) {
    return value === null || value === undefined ? '—' : `₹${formatMoney(value)}`;
}

function render() {
    renderTiles();
    renderPills();
    renderFilterCount();

    const body = $('#stock-body');

    if (state.failure) {
        body.innerHTML = tableMessage(7, state.failure.message, state.failure.tone);
        $('#stock-summary').textContent = '';
        $('#stock-pager').innerHTML = '';

        return;
    }

    const groups = visibleGroups();

    state.lastPage = Math.max(1, Math.ceil(groups.length / PAGE_SIZE));
    state.page = Math.min(state.page, state.lastPage);

    const page = groups.slice((state.page - 1) * PAGE_SIZE, state.page * PAGE_SIZE);

    body.innerHTML = page.length
        ? page.map(renderGroup).join('')
        : tableMessage(7, emptyMessage());

    renderSummary(groups);
    renderPager();
}

function emptyMessage() {
    if (state.status || state.search || state.categoryId) {
        return 'Nothing matches those filters.';
    }

    return 'Nothing is stocked yet. Stock arrives when you record a purchase or a count.';
}

/**
 * The three tallies come from the server, over everything the *text* filters
 * matched and before the status filter narrowed it. That is what makes them
 * usable as filters: "6 low" has to still say 6 after somebody clicks it.
 */
function renderTiles() {
    const totals = state.totals ?? {};

    $('#stat-value').textContent = money(totals.value ?? '0.00');
    $('#stat-out').textContent = totals.out_of_stock ?? 0;
    $('#stat-low').textContent = totals.low ?? 0;
    $('#stat-negative').textContent = totals.negative ?? 0;

    $$('[data-stat-filter]').forEach((tile) => {
        const on = state.status === tile.dataset.statFilter;

        tile.classList.toggle('stat-tile-on', on);
        tile.querySelector('[data-stat-chevron]')?.classList.toggle('text-primary', on);
        tile.querySelector('[data-stat-chevron]')?.classList.toggle('text-border', !on);
    });
}

function renderPills() {
    $$('#filter-pills [data-pill]').forEach((pill) => {
        pill.setAttribute('aria-pressed', String(pill.dataset.pill === state.status));
    });

    const filtered = Boolean(state.status || state.search || state.categoryId || state.isActive === '0');

    $('#clear-filters').classList.toggle('hidden', !filtered);
    $('#clear-filters').classList.toggle('flex', filtered);
}

function renderFilterCount() {
    const active = [state.categoryId, state.isActive === '0' ? '1' : ''].filter(Boolean).length;
    const badge = $('#filter-count');

    badge.textContent = String(active);
    badge.classList.toggle('hidden', active === 0);
}

function renderSummary(groups) {
    if (state.failure) return;

    const variants = groups.reduce((total, group) => total + group.variants.length, 0);
    const value = groups.reduce((total, group) => total + group.roll.value, 0);

    const parts = groups.length
        ? [
            `${variants} variant${variants === 1 ? '' : 's'} across ${groups.length} item${groups.length === 1 ? '' : 's'},`,
            `worth ${money(value.toFixed(2))}.`,
        ]
        : [];

    // Said plainly rather than silently. A figure computed from a prefix of the
    // catalogue is not the workshop's stock value, and somebody reading it as
    // one would be wrong by however much was left off.
    if (state.truncated) {
        parts.push(`Showing the first ${FETCH_SIZE * MAX_PAGES} variants — narrow the search to see the rest.`);
    }

    $('#stock-summary').textContent = parts.join(' ');
}

function renderPager() {
    const host = $('#stock-pager');

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

/* -------------------------------------------------------------------------
 | A row, and the variants under it
 | ---------------------------------------------------------------------- */

const CHEVRON = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>`;

function renderGroup(group) {
    const open = state.expanded.has(group.key);
    const unit = group.item?.base_uom_symbol ?? '';
    const status = positionStatus(group.roll);
    const count = group.variants.length;

    /*
    | An item with one variant names it rather than saying "1 variant", which
    | tells nobody anything. Suppressed where the variant has no label of its
    | own — `displayLabel()` falls back to the item's name, and a row that read
    | "Copper Wire · Copper Wire" would be worse than the count.
    */
    const only = count === 1 ? group.variants[0] : null;
    const variantCell = only && only.display_label !== group.item?.name
        ? esc(only.display_label)
        : `${count} variant${count === 1 ? '' : 's'}`;

    const head = `
        <tr class="cursor-pointer border-t border-border transition hover:bg-secondary/50 ${open ? 'bg-secondary/30' : ''}"
            data-group="${esc(String(group.key))}" tabindex="0" role="button" aria-expanded="${open}"
            aria-label="${open ? 'Collapse' : 'Expand'} the variants of ${esc(group.item?.name ?? 'this item')}">
            <td class="px-4 py-3">
                <span class="flex items-center gap-2">
                    <span class="text-muted-foreground transition-transform ${open ? 'rotate-90' : ''}">${CHEVRON}</span>
                    <span class="min-w-0">
                        <span class="block truncate text-[13.5px] font-semibold text-foreground">
                            ${esc(group.item?.name ?? 'Unfiled variant')}
                        </span>
                        ${group.item?.code
                            ? `<span class="block font-mono text-[11.5px] text-muted-foreground">${esc(group.item.code)}</span>`
                            : ''}
                    </span>
                </span>
            </td>
            <td class="px-4 py-3 text-[13px] text-secondary-foreground">${esc(group.item?.category_label ?? '—')}</td>
            <td class="px-4 py-3 text-[13px] text-muted-foreground">${variantCell}</td>
            <td class="px-4 py-3 text-right font-mono text-[13px] font-semibold ${group.roll.quantity < 0 ? 'text-rose-700' : 'text-foreground'}">
                ${esc(formatQuantity(group.roll.quantity, unit))}
            </td>
            <td class="px-4 py-3 text-right font-mono text-[13px] text-secondary-foreground">
                ${esc(money(averageCostOf(group.roll)))}
            </td>
            <td class="px-4 py-3 text-right font-mono text-[13px] font-semibold text-foreground">
                ${esc(money(group.roll.value.toFixed(2)))}
            </td>
            <td class="px-4 py-3">${stockStatusBadge(status)}</td>
        </tr>`;

    if (!open) return head;

    return head + group.variants.map((row) => renderVariant(row, unit)).join('');
}

/**
 * One variant, indented under its item.
 *
 * This is the row that opens the stock card, because movements belong to a
 * variant. The item row above it has no card of its own — a family's "history"
 * would be several ledgers interleaved, which is not a document anybody reconciles
 * against.
 */
function renderVariant(row, unit) {
    const status = row.is_negative ? 'negative' : (row.is_low ? 'low' : (row.has_stock ? 'in_stock' : 'out'));

    return `
        <tr class="cursor-pointer border-t border-muted bg-secondary/10 transition hover:bg-secondary/40"
            data-variant="${row.variant_id}" tabindex="0" role="button"
            aria-label="Open the stock card for ${esc(row.display_label)}">
            <td class="py-2.5 pl-12 pr-4">
                <span class="block truncate text-[13px] text-secondary-foreground">${esc(row.display_label)}</span>
                <span class="flex items-center gap-2">
                    ${row.sku ? `<span class="font-mono text-[11.5px] text-muted-foreground">${esc(row.sku)}</span>` : ''}
                    ${row.is_active ? '' : '<span class="text-[11.5px] text-muted-foreground">archived</span>'}
                </span>
            </td>
            <td class="px-4 py-2.5"></td>
            <td class="px-4 py-2.5"></td>
            <td class="px-4 py-2.5 text-right">
                <span class="block font-mono text-[13px] ${row.is_negative ? 'font-semibold text-rose-700' : 'text-secondary-foreground'}">
                    ${esc(formatQuantity(row.quantity, unit))}
                </span>
                ${row.reorder_level === null || row.reorder_level === undefined
                    ? ''
                    : `<span class="mt-0.5 block text-[11.5px] text-muted-foreground">reorder at ${esc(formatQuantity(row.reorder_level, unit))}</span>`}
            </td>
            <td class="px-4 py-2.5 text-right font-mono text-[13px] text-muted-foreground">
                ${row.has_stock ? esc(money(row.average_cost)) : '—'}
            </td>
            <td class="px-4 py-2.5 text-right font-mono text-[13px] text-secondary-foreground">
                ${esc(money(row.value))}
            </td>
            <td class="px-4 py-2.5">${stockStatusBadge(status)}</td>
        </tr>`;
}

function toggleGroup(key) {
    if (state.expanded.has(key)) state.expanded.delete(key);
    else state.expanded.add(key);

    render();
}

/* -------------------------------------------------------------------------
 | Reconciliation
 | ---------------------------------------------------------------------- */

/**
 * Stock value against the Inventory account.
 *
 * Only rendered when the two differ, and only shown at all to somebody who can
 * read the books — the API leaves the ledger half out for anybody else, so the
 * absence of the key is the permission check.
 */
async function loadReconciliation() {
    const host = $('#reconciliation');

    try {
        const { data } = await auth.call('/stock/summary');

        if (data.reconciles === undefined || data.reconciles) {
            host.classList.add('hidden');

            return;
        }

        host.classList.remove('hidden');
        host.innerHTML = `
            <div class="surface border-rose-200 bg-rose-50 px-4 py-3 text-[0.8125rem] text-rose-700">
                <span class="font-semibold">Stock and the Inventory account disagree by ${esc(money(data.difference))}.</span>
                The shelf is worth ${esc(money(data.value))} and the account stands at
                ${esc(money(data.inventory_account.balance))}. Every purchase, sale and count writes both
                together, so a difference almost always means a journal entry was posted straight to
                ${esc(data.inventory_account.name)}.
            </div>`;
    } catch {
        // Nothing to say. The list below reports its own failure if it has one,
        // and a second error banner for a panel that is advisory would only be
        // noise on top of it.
        host.classList.add('hidden');
    }
}

/* -------------------------------------------------------------------------
 | The stock card
 | ---------------------------------------------------------------------- */

function cardFigure(label, value) {
    return `
        <div>
            <span class="block text-[11px] uppercase tracking-wide text-muted-foreground">${esc(label)}</span>
            <span class="mt-0.5 block font-mono text-[15px] font-semibold text-foreground">${esc(value)}</span>
        </div>`;
}

async function openCard(variantId) {
    const row = state.rows.find((item) => String(item.variant_id) === String(variantId));
    const unit = row?.item?.base_uom_symbol ?? '';

    $('#stock-card-title').textContent = row?.display_label ?? 'Stock card';
    $('#stock-card-subtitle').textContent = row?.item?.name ?? '';
    $('#stock-card-position').innerHTML = row
        ? cardFigure('On hand', formatQuantity(row.quantity, unit))
            + cardFigure('Average cost', money(row.average_cost))
            + cardFigure('Value', money(row.value))
        : '';
    $('#stock-card-body').innerHTML = tableMessage(5, 'Loading the movements…');

    showModal('#stock-card-drawer');

    try {
        const { data } = await auth.call(`/stock/variants/${variantId}?per_page=100`);

        const opening = `
            <tr class="border-t border-muted bg-secondary/20 text-[12.5px] italic text-muted-foreground">
                <td class="px-4 py-2.5" colspan="4">Brought forward</td>
                <td class="px-4 py-2.5 text-right font-mono not-italic">
                    ${esc(formatQuantity(data.opening.quantity, unit))}
                </td>
            </tr>`;

        const rows = data.movements.map((movement) => `
            <tr class="border-t border-muted">
                <td class="whitespace-nowrap px-4 py-2.5 text-[12.5px]">${esc(formatDate(movement.date))}</td>
                <td class="px-4 py-2.5">
                    <span class="text-[13px] font-medium text-foreground">${esc(movement.type_label)}</span>
                    <span class="mt-0.5 block text-[12px] text-muted-foreground">
                        ${movement.transaction ? `#${movement.transaction.id} · ${esc(movement.transaction.type_label)}` : ''}
                        ${movement.memo ? ` · ${esc(movement.memo)}` : ''}
                    </span>
                </td>
                <td class="px-4 py-2.5 text-right font-mono text-[13px] ${String(movement.quantity).startsWith('-') ? 'text-rose-700' : 'text-emerald-700'}">
                    ${esc(formatQuantity(movement.quantity))}
                </td>
                <td class="px-4 py-2.5 text-right font-mono text-[13px]">${esc(money(movement.unit_cost))}</td>
                <td class="px-4 py-2.5 text-right font-mono text-[13px] font-semibold">
                    ${esc(formatQuantity(movement.balance_quantity, unit))}
                </td>
            </tr>`).join('');

        $('#stock-card-body').innerHTML = data.movements.length
            ? opening + rows
            : tableMessage(5, 'Nothing has moved through this variant yet.');
    } catch (error) {
        $('#stock-card-body').innerHTML = tableMessage(5, error.message, 'error');
    }
}

/* -------------------------------------------------------------------------
 | Export
 | ---------------------------------------------------------------------- */

/**
 * The inventory report, as a spreadsheet.
 *
 * One line per variant with its item repeated beside it, rather than the nested
 * shape the table draws — a nested CSV cannot be sorted or pivoted, which is the
 * only reason anybody exports one.
 *
 * It covers everything the filters matched, not the page on screen. An export
 * that stopped at row 25 because that is where the pager happened to be would be
 * worse than no export, because it would look complete.
 */
function exportCsv() {
    const groups = visibleGroups();

    if (!groups.length) {
        toast('Nothing to export with these filters.', 'info');

        return;
    }

    const rows = groups.flatMap((group) => group.variants.map((row) => [
        group.item?.name ?? '',
        group.item?.code ?? '',
        group.item?.category_label ?? '',
        row.display_label,
        row.sku ?? '',
        group.item?.base_uom_symbol ?? '',
        // Sent as the API sent them: a spreadsheet wants the number, not the
        // grouped, unit-suffixed string the table shows.
        row.quantity,
        row.average_cost,
        row.value,
        row.reorder_level ?? '',
        row.is_negative ? 'Negative' : (row.is_low ? 'Low stock' : (row.has_stock ? 'In stock' : 'Out of stock')),
        row.is_active ? 'Active' : 'Archived',
    ]));

    downloadCsv(`stock-${new Date().toISOString().slice(0, 10)}.csv`, [
        [
            'Item', 'Code', 'Category', 'Variant', 'SKU', 'Unit',
            'Quantity', 'Average cost', 'Value', 'Reorder level', 'Status', 'Variant state',
        ],
        ...rows,
    ]);

    toast(`Exported ${rows.length} variant${rows.length === 1 ? '' : 's'}.`);
}

/* -------------------------------------------------------------------------
 | Recording a count
 | ---------------------------------------------------------------------- */

let lineSeq = 0;

function adjustmentLine() {
    const id = ++lineSeq;

    const options = state.rows
        .map((row) => `<option value="${row.variant_id}">${esc(row.display_label)}${row.item ? ` · ${esc(row.item.name)}` : ''}</option>`)
        .join('');

    return `
        <div class="grid gap-2 rounded-[10px] border border-border p-3 sm:grid-cols-[2fr_1fr_1fr_auto]" data-line="${id}">
            <label class="field">
                <span class="field-label">Variant</span>
                <select name="variant_id" class="field-input" required>
                    <option value="">Choose…</option>
                    ${options}
                </select>
            </label>

            <label class="field">
                <span class="field-label">Difference</span>
                <input type="text" name="quantity" class="field-input font-mono" inputmode="decimal"
                       placeholder="-2" required>
            </label>

            <label class="field">
                <span class="field-label">Cost, if found</span>
                <input type="text" name="unit_cost" class="field-input font-mono" inputmode="decimal"
                       placeholder="Leave blank">
            </label>

            <button type="button" class="btn btn-ghost btn-icon self-end" data-remove-line
                    aria-label="Remove this line">×</button>
        </div>`;
}

function openAdjustment() {
    const form = $('#adjustment-form');

    clearFormErrors(form);
    form.reset();
    form.elements.date.value = new Date().toISOString().slice(0, 10);

    lineSeq = 0;
    $('#adjustment-lines').innerHTML = adjustmentLine();

    showModal('#adjustment-modal');
}

function collectAdjustments() {
    return $$('#adjustment-lines [data-line]')
        .map((line) => ({
            variant_id: Number($('[name=variant_id]', line).value),
            quantity: $('[name=quantity]', line).value.trim(),
            unit_cost: $('[name=unit_cost]', line).value.trim() || null,
        }))
        .filter((row) => row.variant_id && row.quantity !== '');
}

async function submitAdjustment(event) {
    event.preventDefault();

    const form = event.target;

    clearFormErrors(form);

    const adjustments = collectAdjustments();

    if (!adjustments.length) {
        showFormErrors(form, {
            details: { errors: { adjustments: ['Say what the count found — at least one variant and the difference.'] } },
        });

        return;
    }

    setSubmitting(form, true, 'Posting…');

    try {
        await auth.call('/transactions/stock-adjustment', {
            method: 'POST',
            body: {
                date: form.elements.date.value,
                notes: form.elements.notes.value.trim() || null,
                // Never defaulted. Committing to the ledger is the consequential
                // act, and this screen is explicit about doing it.
                post: true,
                adjustments,
            },
        });

        hideModal('#adjustment-modal');
        toast('The count is recorded and the books are updated.');

        await Promise.all([load(), loadReconciliation()]);
    } catch (error) {
        showFormErrors(form, error);
    } finally {
        setSubmitting(form, false, 'Post the correction');
    }
}

/* -------------------------------------------------------------------------
 | Controls
 | ---------------------------------------------------------------------- */

/** A server-side filter changed: refetch, then repaint. */
function refetch() {
    state.expanded.clear();
    load();
}

function setStatus(status) {
    // Clicking the applied filter clears it, which is what a toggle means and
    // what the tiles look like they do.
    state.status = state.status === status ? '' : status;
    state.page = 1;

    render();
}

function clearFilters() {
    const refetches = Boolean(state.search || state.categoryId || state.isActive === '0');

    state.search = '';
    state.categoryId = '';
    state.isActive = '1';
    state.status = '';
    state.page = 1;

    $('#filter-search').value = '';
    $('#filter-type').value = '';
    $('#filter-archived').value = '1';

    if (refetches) refetch();
    else render();
}

const ARROW_UP = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m5 12 7-7 7 7"/><path d="M12 19V5"/></svg>`;
const ARROW_DOWN = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m19 12-7 7-7-7"/><path d="M12 5v14"/></svg>`;

function renderSortPanel() {
    $('#sort-panel').innerHTML = SORT_OPTIONS
        .flatMap((option) => ['asc', 'desc'].map((direction) => {
            const on = state.sort.column === option.column && state.sort.direction === direction;

            return `
                <button type="button" role="menuitem" class="row-menu-item ${on ? 'text-primary' : ''}"
                        data-sort-option="${option.column}" data-sort-direction="${direction}">
                    ${direction === 'asc' ? ARROW_UP : ARROW_DOWN}
                    ${option.label} ${direction === 'asc' ? '(low to high)' : '(high to low)'}
                </button>`;
        }))
        .join('');
}

function closeMenus() {
    $('#filter-panel').classList.add('hidden');
    $('#sort-panel').classList.add('hidden');
    $('#filter-toggle').setAttribute('aria-expanded', 'false');
    $('#sort-toggle').setAttribute('aria-expanded', 'false');
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

export default async function initStock() {
    const root = $('[data-ws-list]').closest('[data-module-root]');

    /*
    | §2A.10 — read-mostly, so it opens on its list. `canCreate: false` is what
    | says so: the workspace lands on the list, paints no switch control, and
    | leaves Escape to take one press out to the grid rather than to a form that
    | does not exist.
    */
    mountWorkspace(root, {
        key: 'stock',
        title: 'Stock',
        formSubtitle: '',
        listSubtitle: () => 'What is on the shelf, and what it is worth. Every figure is a sum of stock movements — there is no quantity column anywhere to go out of step.',
        createLabel: '',
        canCreate: false,
        onShowList: async () => {
            await load();
            await loadReconciliation();
        },
    });

    /* --- filters that refetch ----------------------------------------- */

    $('#filter-search').addEventListener('input', debounce((event) => {
        state.search = event.target.value.trim();
        refetch();
    }));

    $('#filter-type').addEventListener('change', (event) => {
        state.categoryId = event.target.value;
        refetch();
    });

    $('#filter-archived').addEventListener('change', (event) => {
        state.isActive = event.target.value;
        refetch();
    });

    /* --- filters that only repaint ------------------------------------ */

    // The tiles are filters as well as figures: seeing "6 low" and having no way
    // to ask which six would be a dead end.
    $$('[data-stat-filter]').forEach((tile) => {
        tile.addEventListener('click', () => setStatus(tile.dataset.statFilter));
    });

    $('#filter-pills').addEventListener('click', (event) => {
        const pill = event.target.closest('[data-pill]');
        if (!pill) return;

        // "Everything" carries an empty value, so it clears rather than toggles.
        state.status = pill.dataset.pill;
        state.page = 1;
        render();
    });

    $('#clear-filters').addEventListener('click', clearFilters);

    /* --- the two menus -------------------------------------------------- */

    renderSortPanel();

    $('#filter-toggle').addEventListener('click', (event) => {
        event.stopPropagation();

        const open = $('#filter-panel').classList.toggle('hidden');

        $('#filter-toggle').setAttribute('aria-expanded', String(!open));
        $('#sort-panel').classList.add('hidden');
    });

    $('#sort-toggle').addEventListener('click', (event) => {
        event.stopPropagation();

        const open = $('#sort-panel').classList.toggle('hidden');

        $('#sort-toggle').setAttribute('aria-expanded', String(!open));
        $('#filter-panel').classList.add('hidden');
    });

    $('#sort-panel').addEventListener('click', (event) => {
        const option = event.target.closest('[data-sort-option]');
        if (!option) return;

        state.sort = { column: option.dataset.sortOption, direction: option.dataset.sortDirection };
        state.page = 1;

        renderSortPanel();
        closeMenus();
        render();
    });

    // Clicking away closes both. Bound to the module root rather than the
    // document, so it goes with the module when the shell swaps it out.
    root.addEventListener('click', (event) => {
        if (!event.target.closest('#filter-panel, #sort-panel, #filter-toggle, #sort-toggle')) closeMenus();
    });

    /* --- the table ------------------------------------------------------ */

    function activate(target) {
        const variant = target.closest('[data-variant]');

        if (variant) {
            openCard(variant.dataset.variant);

            return;
        }

        const group = target.closest('[data-group]');

        if (group) toggleGroup(group.dataset.group);
    }

    $('#stock-body').addEventListener('click', (event) => activate(event.target));

    $('#stock-body').addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;

        // Space scrolls the page unless the row claims it.
        event.preventDefault();
        activate(event.target);
    });

    $('#stock-pager').addEventListener('click', (event) => {
        const button = event.target.closest('[data-page]');
        if (!button) return;

        state.page = Number(button.dataset.page);
        render();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    /* --- export and the count ------------------------------------------- */

    $('#export-csv').addEventListener('click', exportCsv);

    $('#new-adjustment').addEventListener('click', openAdjustment);
    $('#add-adjustment-line').addEventListener('click', () => {
        $('#adjustment-lines').insertAdjacentHTML('beforeend', adjustmentLine());
    });

    $('#adjustment-lines').addEventListener('click', (event) => {
        const remove = event.target.closest('[data-remove-line]');

        // Never the last one: an empty form with no way to add a line back
        // without closing and reopening is worse than a line you can ignore.
        if (remove && $$('#adjustment-lines [data-line]').length > 1) {
            remove.closest('[data-line]').remove();
        }
    });

    $('#adjustment-form').addEventListener('submit', submitAdjustment);

    /* --- the category filter's options ----------------------------------- */

    // Rows an admin edits, so they come from the server rather than from the
    // Blade template. Categories that hold no stock are dropped: labour was
    // never on a shelf, so offering it here would be offering a filter that can
    // only ever come back empty.
    try {
        const { data } = await auth.call('/items/meta');
        const categories = (data?.categories ?? []).filter((category) => category.can_hold_stock);

        $('#filter-type').innerHTML = `<option value="">All categories</option>${
            categories.map((category) => `<option value="${esc(String(category.value))}">${esc(category.label)}</option>`).join('')
        }`;
    } catch {
        // A user who cannot read the catalogue keeps an "All categories" filter
        // that does nothing, which is the honest degradation.
    }
}
