import auth from '../auth-client';
import { formatQuantity } from '../components/badge';
import {
    averageCostOf, positionStatus, rollUpPositions, stockStatusBadge,
} from '../components/stock-position';
import { can } from '../permissions';
import {
    $, $$, clearFormErrors, confirmAction, debounce, esc, formatDate, formatMoney,
    hideModal, setSubmitting, showFormErrors, showModal, tableMessage, toast,
} from '../ui';
import { adoptForm, mountWorkspace } from '../workspace';
import { initCatalogueMaster, openCatalogueMaster } from './catalogue-master.js';

/** The §2A frame this module is mounted in. Set at the end of {@link initItems}. */
let workspace = null;

/**
 * The item form, held rather than looked up.
 *
 * It is one node with two homes — the level-1 slot and the edit dialog — and
 * while the workspace is showing its list it is detached from the document
 * entirely (§2A.2). A `document.querySelector('#item-form')` finds nothing at
 * that moment, which is exactly when "edit this row" needs it.
 */
let itemForm = null;

/**
 * The list surface, held for the same reason {@link itemForm} is.
 *
 * §2A.2 keeps exactly one surface in the document, so while the create form is
 * up the whole table — its rows, its filters, its stat tiles and the draft
 * banner — is detached. `document.querySelector` finds none of it, which is
 * precisely the moment a save wants to bring the list up to date: the lookup
 * came back null, the repaint threw on the first `.classList`, and the refetch
 * behind it never ran. The list then sat on its pre-creation rows until
 * somebody reloaded the page, which §3.2 forbids anyway.
 *
 * Querying the node itself works whether it is attached or not, so a refresh
 * from the form paints the detached table and it is already current when it
 * comes back on screen.
 */
let listRoot = null;

const $list = (selector) => $(selector, listRoot ?? document);
const $$list = (selector) => $$(selector, listRoot ?? document);

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
    categoryId: '',
    isStock: '',
    isActive: '1',
    pill: 'all',
    onlyDrafts: false,

    sort: { column: 'name', direction: 'asc' },
    page: 1,
    lastPage: 1,

    meta: null,          // categories, their field schemas, and the units
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
        state.meta = { categories: [], units: [], draft_counts: { items: 0, variants: 0 } };
    }

    // The selects whose options are rows rather than a fixed set. Painted here
    // rather than in the Blade template, so adding a category or a unit shows up
    // without a deployment.
    paintVocabularySelects();

    return state.meta;
}

function typeMeta(value) {
    if (value === '' || value === null || value === undefined) return null;

    // Categories are matched on id, sent as a string because that is what a
    // <select> value always is. `types` and `categories` are the same array —
    // see ItemController::meta() on why both keys exist.
    const list = state.meta?.categories ?? [];

    return list.find((category) => String(category.value) === String(value)) ?? null;
}

/**
 * Fill the selects whose options are rows rather than a fixed set.
 *
 * The categories and the units are tables an admin edits, so rendering them into
 * the Blade template would be a copy that goes stale the moment one is added —
 * which is the whole failure this module was rebuilt to remove. They are painted
 * from the server's answer instead, every time the meta is (re)loaded.
 */
function paintVocabularySelects() {
    const categories = state.meta?.categories ?? [];
    const brands = state.meta?.brands ?? [];
    const units = state.meta?.units ?? [];

    const categoryOptions = categories
        .map((category) => `<option value="${esc(category.value)}">${esc(category.label)}</option>`)
        .join('');

    // Scoped to the form node rather than the document: `#item-form` lives in a
    // detached slot while the workspace is showing its list, so a document query
    // would find nothing there.
    const formSelect = itemForm ? $('#item-type', itemForm) : null;

    if (formSelect) {
        const held = formSelect.value;
        formSelect.innerHTML = categories.length
            ? categoryOptions
            : '<option value="">No categories yet — add one first</option>';
        if (held) formSelect.value = held;
    }

    if (itemForm) paintBrandSelect($('#item-brand', itemForm), brands);

    const unitSelect = itemForm ? $('#item-uom', itemForm) : null;

    if (unitSelect) {
        const held = unitSelect.value;
        unitSelect.innerHTML = units
            .map((unit) => `<option value="${esc(unit.value)}">${esc(unit.label)} (${esc(unit.symbol)})</option>`)
            .join('');
        if (held) unitSelect.value = held;
    }

    const filter = $list('#filter-type');

    if (filter) {
        const held = filter.value;
        filter.innerHTML = `<option value="">All categories</option>${categoryOptions}`;
        filter.value = held;
    }
}

/**
 * Fill the brand dropdown, keeping whatever it was already on.
 *
 * Two things it has to survive. The blank option is first and stays selected by
 * default — an unbranded bush is a real thing, and a dropdown that pre-picked
 * whichever make came first alphabetically would file half the catalogue under
 * it. And the held value may be a brand `/items/meta` does not send, because meta
 * publishes active brands only and a product being edited may carry an archived
 * one; that option is put back, labelled, for as long as it is the answer.
 * Dropping it silently would turn "save this description" into "and also clear
 * the brand".
 *
 * @param {HTMLSelectElement|null} select
 * @param {Array<{value: string, label: string}>} brands
 * @param {{id: string|number|null, label: string|null}} held  A brand to keep offered even if meta omits it.
 */
function paintBrandSelect(select, brands, held = null) {
    if (!select) return;

    const keepId = held?.id != null && held.id !== '' ? String(held.id) : select.value;
    const keepLabel = held?.label ?? select.selectedOptions[0]?.textContent?.trim() ?? null;

    let options = brands
        .map((brand) => `<option value="${esc(brand.value)}">${esc(brand.label)}</option>`)
        .join('');

    if (keepId && !brands.some((brand) => String(brand.value) === keepId)) {
        options += `<option value="${esc(keepId)}">${esc(keepLabel ?? 'Brand')} (archived)</option>`;
    }

    select.innerHTML = `<option value="">No brand</option>${options}`;
    select.value = keepId ?? '';
}

function renderDraftBanner() {
    const count = state.meta?.draft_counts?.items ?? 0;
    const banner = $list('#draft-banner');

    // Hidden when there is nothing in the queue rather than shown reading "0":
    // a permanent empty banner is a banner people stop seeing.
    banner.classList.toggle('hidden', count === 0);
    banner.classList.toggle('flex', count > 0);

    if (count > 0) {
        $list('#draft-banner-title').textContent =
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

    // Grouped here, summed by the shared rule — the Stock module rolls the same
    // rows up the same way, and two copies of "what is average cost" is two
    // answers (§4.4).
    const byItem = new Map();

    rows.forEach((row) => {
        if (!byItem.has(row.item_id)) byItem.set(row.item_id, []);
        byItem.get(row.item_id).push(row);
    });

    byItem.forEach((positions, itemId) => {
        state.stock.set(itemId, rollUpPositions(positions));
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

    // Worst-wins, decided by the shared rule.
    return positionStatus(state.stock.get(item.id));
}

function money(value) {
    return value === null || value === undefined ? '—' : `₹${formatMoney(value)}`;
}

/** This family's average cost, by the shared rule: value over quantity. */
function averageCost(item) {
    return averageCostOf(state.stock.get(item.id));
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
        item.category_label,
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

/**
 * How many things are on the shelf under this family.
 *
 * The loaded variants where the row carries them and the server's count where it
 * does not — the list asks for `with_variants`, so the first is the usual answer
 * and the second is the honest fallback rather than a guess. Never null: a family
 * nobody has hung a variant off yet has none, and printing a dash for it would
 * read as "not known" when it means zero.
 */
function variantCount(item) {
    if (Array.isArray(item.variants)) return item.variants.length;

    return item.variant_count ?? 0;
}

const SORTERS = {
    name: (item) => item.name?.toLowerCase() ?? '',
    type: (item) => item.category_label?.toLowerCase() ?? '',
    code: (item) => item.code?.toLowerCase() ?? '',
    variants: (item) => variantCount(item),
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
        if (state.categoryId && String(item.category_id ?? '') !== String(state.categoryId)) return false;
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
    // Name, Category, Code, Variants, Status, Actions — and the four stock
    // columns where the caller may read them.
    const columns = state.canStock ? 10 : 7;

    $list('#items-body').innerHTML = pageRows.length
        ? pageRows.map(renderRow).join('')
        : tableMessage(columns, emptyMessage());

    renderStats();
    renderSummary(rows.length);
    renderPager();
    renderSortIndicators();

    const filtered = Boolean(state.search) || state.pill !== 'all' || state.categoryId
        || state.isStock !== '' || state.isActive !== '1' || state.onlyDrafts;

    $list('#clear-filters').classList.toggle('hidden', !filtered);
    $list('#clear-filters').classList.toggle('flex', filtered);
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

    // Zero is printed rather than dashed, and printed in amber: a family with no
    // variants cannot be sold, priced or counted, so it should look like the
    // thing needing attention that it is.
    const variants = variantCount(item);

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
                        ${item.tracks_stock ? formatQuantity(quantity ?? 0) : '—'}
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

    // §2A.8 — an item written on the form while the list was not on screen is
    // flashed the first time the list is looked at, so the eye can find it.
    const flash = workspace?.isNew(item.id) ? ' row-new' : '';

    return `
        <tr class="group cursor-pointer transition hover:bg-background${flash} ${item.is_active ? '' : 'opacity-60'}"
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
                    ${esc(item.category_label)}
                </span>
            </td>

            <td class="px-4 py-3">
                ${item.code
                    ? `<code class="rounded bg-muted px-2 py-0.5 font-mono text-xs text-muted-foreground">${esc(item.code)}</code>`
                    : '<span class="text-[13px] text-muted-foreground">—</span>'}
            </td>

            <td class="px-4 py-3">
                <span class="text-[13px] font-semibold ${variants === 0 ? 'text-amber-600' : 'text-secondary-foreground'}">
                    ${variants}
                </span>
            </td>

            ${stockCells}

            <td class="px-4 py-3">${stockStatusBadge(status ?? (item.is_active ? 'active' : 'archived')) || catalogueBadge(item)}</td>

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

    $list('#stat-total').textContent = scope.length.toLocaleString('en-IN');

    if (!state.canStock) return;

    const counts = { in_stock: 0, low: 0, out: 0, negative: 0 };

    scope.forEach((item) => {
        const status = statusOf(item);

        if (status in counts) counts[status] += 1;
    });

    $list('#stat-in-stock').textContent = counts.in_stock.toLocaleString('en-IN');
    $list('#stat-low').textContent = counts.low.toLocaleString('en-IN');
    // Negative positions are counted with "out" here for the same reason the
    // filter includes them: neither is on the shelf.
    $list('#stat-out').textContent = (counts.out + counts.negative).toLocaleString('en-IN');

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

    $list('#items-summary').textContent = parts.join(' ');
}

function renderPager() {
    const host = $list('#items-pager');

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
    $$list('#items-head [data-sort]').forEach((th) => {
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
        item.category_label,
        `counted in ${item.base_uom_label.toLowerCase()}`,
    ].filter(Boolean).join(' · ');

    $('#drawer-status').innerHTML = stockStatusBadge(status) || catalogueBadge(item);

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
        // Deliberately does not name a cause. It used to say a sale was probably
        // recorded before the purchase that supplied it, which is one of three
        // ways a position goes negative — a reversed purchase and a stock count
        // are the others — and naming the wrong one sends somebody looking
        // through the wrong document.
        message = 'More has been issued than was ever received. Check the stock history for the '
            + 'movement that took it below zero.';
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
                            ${formatQuantity(roll?.quantity ?? 0)}
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
        ['Category', esc(item.category_label)],
        ['Brand', item.brand ? esc(item.brand) : '—'],
        ['Code', item.code ? `<code class="rounded bg-muted px-2 py-0.5 font-mono text-xs">${esc(item.code)}</code>` : '—'],
        [`${esc(item.tax_code_label)} code`, item.hsn_sac ? esc(item.hsn_sac) : '—'],
        ['GST rate', `${esc(item.gst_rate)}%`],
        ['Counted in', esc(item.base_uom_label)],
        ['Keeps stock', item.can_hold_stock ? (item.is_stock ? 'Yes' : 'No') : 'Cannot — a service is produced when sold'],
        ['Variants', String(variantCount(item))],
        ...(state.canStock && item.tracks_stock ? [['Average cost', money(averageCost(item))]] : []),
        ['Selling price', sellPrice(item) ?? '—'],
        ['Status', stockStatusBadge(status) || catalogueBadge(item)],
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
                                ${formatQuantity(position.quantity)}
                                <span class="text-[11px] font-medium text-muted-foreground">${esc(item.base_uom_symbol)}</span>
                            </p>
                            <p class="text-[11.5px] text-muted-foreground">
                                ${position.reorder_level !== null && position.reorder_level !== undefined
                                    ? `reorder at ${formatQuantity(position.reorder_level)}`
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

                        /*
                        | A reversed purchase is stored as an adjustment, so
                        | without this it read exactly like a physical count and
                        | there was no way to tell which document had taken the
                        | stock off the shelf. The server decides what to call it
                        | — see StockMovement::sourceLabel() — and sends null for
                        | every movement whose type already says everything, so
                        | every other row is unchanged.
                        */
                        const source = movement.source_label ?? null;
                        const document = movement.transaction ?? null;

                        return `
                            <tr class="transition hover:bg-background">
                                <td class="px-3 py-2.5 text-xs whitespace-nowrap text-muted-foreground">
                                    ${esc(formatDate(movement.date))}
                                </td>
                                <td class="px-3 py-2.5">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5
                                                 text-[11.5px] font-semibold ${source ? 'bg-amber-50 text-amber-800' : chip}">
                                        ${esc(source ?? movement.type_label ?? movement.type ?? '—')}
                                    </span>
                                    ${document ? `
                                        <span class="mt-0.5 block text-[11px] text-muted-foreground">
                                            ${esc(document.doc_no ?? `#${document.id}`)}
                                        </span>` : ''}
                                </td>
                                <td class="px-3 py-2.5 text-xs text-muted-foreground">
                                    ${esc(variant.display_label)}
                                </td>
                                <td class="px-3 py-2.5 text-[13px] font-semibold whitespace-nowrap
                                           ${quantity < 0 ? 'text-rose-500' : 'text-emerald-600'}">
                                    ${quantity > 0 ? '+' : ''}${formatQuantity(movement.quantity)}
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
 * Reflect the chosen category into the rest of the form.
 *
 * The category decides four things, and saying so as the user picks it is much
 * better than refusing the save afterwards: **which fields the form asks for**,
 * which word the tax code goes by, which unit it is counted in, and whether stock
 * is even possible.
 *
 * The first of those is what makes this form universal. Nothing here knows what a
 * motor or a bearing or an LED lamp is; it draws whatever the server said this
 * category asks for.
 */
function applyTypeToForm({ editing }) {
    /*
    | Scoped to the form rather than to the document.
    |
    | `#item-form` is one node with two homes — the level-1 slot and the edit
    | dialog — and while the workspace is showing its list the level-1 slot is
    | detached. A `document.querySelector` would find nothing there and this
    | would throw; the form itself is always a real node.
    */
    const form = itemForm;
    const category = typeMeta($('#item-type', form).value);

    const section = $('#item-attributes-section', form);
    const host = $('#item-attributes', form);

    if (!category) {
        if (section) section.classList.add('hidden');

        return;
    }

    $('#item-hsn-label', form).textContent = `${category.tax_code_label} code`;

    if (!editing) {
        $('#item-uom', form).value = category.default_uom;

        // The category's defaults are *copied* onto the product, never
        // referenced — correcting a category's rate next March must not restate
        // what every product already charges. So they are filled in only where
        // the user has not typed something of their own.
        const gst = $('#item-gst', form);
        if (!gst.value.trim() && category.default_gst_rate !== null) gst.value = category.default_gst_rate;

        // Which of the two it is, said on the form: a rate that arrived from the
        // category is a value somebody can tab past, so it has to be visible
        // that it arrived rather than that it was typed.
        const hint = $('#item-gst-hint', form);

        if (hint) {
            hint.textContent = category.default_gst_rate === null
                ? `${category.label ?? 'This category'} has no default rate — enter one, or 0 if this is exempt.`
                : `${category.default_gst_rate}% from ${category.label ?? 'the category'}. Change it if this product differs.`;
        }

        const hsn = $('#item-hsn', form);
        if (!hsn.value.trim() && category.default_hsn_sac) hsn.value = category.default_hsn_sac;
    }

    const canHoldStock = category.can_hold_stock;
    const checkbox = $('#item-stock', form);

    checkbox.disabled = !canHoldStock;

    if (!canHoldStock) checkbox.checked = false;

    $('#item-stock-hint', form).textContent = canHoldStock
        ? 'Turn this off for something you buy to order and never hold.'
        : 'This category holds no stock — an hour of labour is produced when it is sold.';

    // Hidden wholesale rather than merely disabled: offering an opening quantity
    // for labour teaches somebody it is possible.
    const stockSection = $('#item-stock-section', form);
    if (stockSection) stockSection.classList.toggle('hidden', !canHoldStock);

    applyStockFieldsState();
    paintUnitSuffix();

    $('#item-type-hint', form).textContent = editing
        ? 'Fixed once the product exists: changing it would reinterpret everything recorded against it.'
        : (category.description || `Asks for ${describeAttributes(category)}.`);

    // The specification section: whatever this category asks for.
    const schema = category.attributes ?? {};
    const keys = Object.keys(schema);

    if (section) section.classList.toggle('hidden', keys.length === 0);

    const forLabel = $('#item-attributes-for', form);
    if (forLabel) forLabel.textContent = keys.length ? `— what a ${category.label.toLowerCase()} is described by` : '';

    if (host) renderAttributeFields(host, schema, editing ? undefined : defaultsFor(schema));
}

/**
 * The pre-filled values a category's fields declare.
 *
 * Applied only on create. Filling defaults into an edit form would quietly
 * rewrite a product that had deliberately been left blank.
 */
function defaultsFor(schema) {
    const values = {};

    Object.keys(schema).forEach((key) => {
        if (schema[key].default !== undefined) values[key] = schema[key].default;
    });

    return values;
}

/**
 * Grey the opening-stock boxes out when the product is not being stocked.
 *
 * Left visible rather than removed: the checkbox above them is what explains
 * why they are inert, and a box that vanishes reads as a bug.
 */
function applyStockFieldsState() {
    const form = itemForm;
    const on = $('#item-stock', form)?.checked;
    const fields = $('#item-stock-fields', form);

    if (!fields) return;

    fields.classList.toggle('opacity-50', !on);

    $$('input', fields).forEach((input) => {
        input.disabled = !on;
    });
}

/** Print the product's unit after the opening-stock box, so "5" says 5 what. */
function paintUnitSuffix() {
    const form = itemForm;
    const code = $('#item-uom', form)?.value;
    const unit = (state.meta?.units ?? []).find((candidate) => candidate.value === code);

    $$('[data-uom-suffix]', form).forEach((node) => {
        node.textContent = unit?.symbol ?? '';
    });
}

function describeAttributes(category) {
    const keys = Object.keys(category.attributes ?? {});

    return keys.length
        ? keys.map((key) => category.attributes[key].label.toLowerCase()).join(', ')
        : 'no specification fields yet';
}

/**
 * Fill the item form in, and put it where it belongs.
 *
 * One form, two homes (§4.4): writing a new item is the module's level-1 landing
 * surface, and editing one is a dialog over the list you found it in. The fields
 * are identical, so they are declared once and the node is moved.
 */
async function openItemForm(item = null) {
    await loadMeta();

    const form = itemForm;
    const editing = item !== null;

    adoptForm(
        form,
        editing ? $('[data-item-modal-slot]') : $('[data-item-form-slot]'),
        { chrome: editing ? 'modal' : 'inline' },
    );

    clearFormErrors(form);
    form.reset();

    $('#item-modal-title', form).textContent = editing ? `Edit ${item.name}` : 'Add product';
    $('#item-modal-subtitle', form).textContent = editing
        ? 'Category and unit are fixed once a product exists.'
        : 'The fields below the category are the ones that category asks for.';

    form.elements.id.value = editing ? item.id : '';

    $('#item-name', form).value = editing ? item.name : '';
    $('#item-code', form).value = editing ? (item.code ?? '') : '';
    // The brand the product already carries, kept offered even where it has since
    // been archived — see paintBrandSelect(). On a create it lands on "No brand".
    paintBrandSelect(
        $('#item-brand', form),
        state.meta?.brands ?? [],
        editing ? { id: item.brand_id ?? '', label: item.brand } : { id: '', label: null },
    );
    $('#item-hsn', form).value = editing ? (item.hsn_sac ?? '') : '';
    $('#item-gst', form).value = editing ? item.gst_rate : '';
    $('#item-type', form).value = editing
        ? String(item.category_id ?? '')
        : ($('#item-type', form).options[0]?.value ?? '');
    $('#item-uom', form).value = editing ? item.base_uom : '';
    $('#item-stock', form).checked = editing ? item.is_stock : true;
    $('#item-description', form).value = editing ? (item.description ?? '') : '';

    /*
    | The variant half of the form, and why it disappears on an edit.
    |
    | Creating is one act — the product and the first thing on the shelf — so the
    | form carries both. Editing is not: a product has many variants by then, and
    | a single set of SKU/price boxes could only mean one of them. Those are
    | edited from the drawer, against the variant they belong to.
    */
    const variantOnly = $$('[data-variant-half]', form);
    variantOnly.forEach((node) => node.classList.toggle('hidden', editing));

    if (!editing) {
        $('#item-sku', form).value = '';
        $('#item-barcode', form).value = '';
        $('#item-sell-price', form).value = '';
        $('#item-purchase-price', form).value = '';
        $('#item-opening-stock', form).value = '';
        $('#item-opening-cost', form).value = '';
        $('#item-reorder', form).value = '';
        $('#item-min-stock', form).value = '';
    }

    // Both are fixed once the product exists, and disabled rather than hidden so
    // the record still reads completely.
    $('#item-type', form).disabled = editing;
    $('#item-uom', form).disabled = editing;

    applyTypeToForm({ editing });

    if (editing) {
        showModal('#item-modal');

        return;
    }

    await workspace?.showForm();
    $('#item-name', form).focus();
}

async function submitItem() {
    const form = itemForm;
    const id = form.elements.id.value;

    clearFormErrors(form);

    if (!$('#item-name', form).value.trim()) {
        showFormErrors(form, {
            fields: { name: ['Give the product a name.'] },
            message: 'Give the product a name.',
        });

        return;
    }

    if (!id && !$('#item-type', form).value) {
        showFormErrors(form, {
            fields: { category_id: ['Choose a category.'] },
            message: 'Choose a category — it decides what this product records and how it is taxed.',
        });

        return;
    }

    /*
    | A rate nobody has stated, on a category that cannot state one for them.
    |
    | The server's fallback is the category's `default_gst_rate`, and where that
    | is null it settles on 0% — which is a real answer for exempt goods and the
    | wrong one for everything else. Asked here rather than assumed, because 0%
    | applies silently to every line of every bill the product ever appears on
    | and nothing afterwards points at the day it was chosen.
    */
    if (!$('#item-gst', form).value.trim() && typeMeta($('#item-type', form).value)?.default_gst_rate === null) {
        showFormErrors(form, {
            fields: { gst_rate: ['Enter a GST rate — 0 if this is exempt.'] },
            message: 'This category has no default GST rate, so this product needs one of its own.',
        });

        return;
    }

    const value = (selector) => $(selector, form).value.trim() || null;

    const body = {
        name: $('#item-name', form).value.trim(),
        code: value('#item-code'),
        // The id, not the name. Null clears it, which is a real edit.
        brand_id: $('#item-brand', form).value ? Number($('#item-brand', form).value) : null,
        hsn_sac: value('#item-hsn'),
        /*
        | Null, not '0'.
        |
        | The server resolves a missing rate from the category's own
        | `default_gst_rate` — but `'0'` is a value, not a missing one, so
        | sending it defeated that fallback and saved every product at 0% GST
        | whatever its category said. The box being empty means "you decide",
        | and this is the only way to say so.
        */
        gst_rate: value('#item-gst'),
        is_stock: $('#item-stock', form).checked,
        description: value('#item-description'),
    };

    /*
    | Sent only on create.
    |
    | The category and the unit are fixed afterwards, and the server ignores them
    | on a PATCH — sending them anyway would suggest they had been applied. The
    | variant half is create-only for the reason openItemForm() explains: on an
    | edit there are many variants and one set of boxes could only mean one.
    */
    if (!id) {
        body.category_id = Number($('#item-type', form).value);
        body.base_uom = $('#item-uom', form).value;

        // This form always creates the first thing on the shelf as well as the
        // product — it collected the specification, the SKU and the price on the
        // same screen. Said outright rather than inferred, so an API client
        // adding a family alone is not given a blank variant it never asked for.
        body.with_variant = true;

        body.attributes = collectAttributes($('#item-attributes', form));

        body.sku = value('#item-sku');
        body.barcode = value('#item-barcode');
        body.sell_price = value('#item-sell-price');
        body.purchase_price = value('#item-purchase-price');
        body.reorder_level = value('#item-reorder');
        body.min_stock = value('#item-min-stock');
        body.opening_stock = value('#item-opening-stock');
        body.opening_cost = value('#item-opening-cost');
    }

    setSubmitting(form, true);

    try {
        const saved = id
            ? await auth.call(`/items/${id}`, { method: 'PATCH', body })
            : await auth.call('/items', { method: 'POST', body });

        if (id) {
            hideModal('#item-modal');
            toast('Product updated.');
            await refresh({ keepPage: true });

            return;
        }

        /*
        | The server may have saved the product and declined the opening stock —
        | recording a quantity is a TRANSACTIONS grant and cataloguing is not.
        | Surfaced rather than swallowed: somebody who typed "5" needs to know
        | the 5 was not recorded.
        */
        const warning = saved?.meta?.warnings?.[0];

        if (warning) toast(warning.message, 'warning');
        else toast('Product created.');

        /*
        | §2A.8 — a save stays on the form.
        |
        | Somebody entering the workshop's catalogue writes several in a row, and
        | dropping them onto a table after each one would cost a click back to
        | the form every time. The new row is flagged instead, so it is
        | highlighted whenever they next choose to look at the list.
        */
        workspace?.flagNew(saved?.data?.id);

        /*
        | The list is refetched *and* repainted even though it is detached —
        | `$list` reaches it either way — so it is already current when it next
        | comes on screen, rather than showing the pre-creation rows until
        | somebody reloads.
        |
        | The header follows in both branches: the count rides on the Show
        | control (§2A.4), and a badge still reading the old total is the one
        | figure somebody on the form can actually see.
        */
        if (workspace?.hasList()) await refresh({ keepPage: true });

        workspace?.refresh();

        await openItemForm();
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
 * Build the specification inputs from the server's schema.
 *
 * **Nothing here is written per category.** The control drawn for each field
 * comes from its declared data type — a select where the values are genuinely
 * fixed, a date picker for a date, a numeric box for a rating — and the labels,
 * units, options and bounds all come from the same payload. Adding "Lumens" to a
 * category is therefore a change to rows in `item_attributes` and to nothing in
 * this file, which is the module's whole acceptance criterion.
 *
 * @param {HTMLElement} host   Where to draw them — the create form or the variant dialog.
 * @param {object} schema      The category's resolved question set, keyed by field.
 * @param {object} values      Current values, keyed by field.
 */
function renderAttributeFields(host, schema = {}, values = {}) {
    const keys = Object.keys(schema);

    if (!keys.length) {
        host.innerHTML = `
            <p class="sm:col-span-2 rounded-[10px] border border-border bg-secondary/40 px-3.5 py-2.5
                      text-[0.8125rem] text-secondary-foreground">
                This category has no specification fields. Add them under Categories if it should have any.
            </p>`;

        return;
    }

    host.innerHTML = keys.map((key) => {
        const field = schema[key];
        const value = values[key] ?? '';
        const suffix = field.suffix
            ? ` <span class="font-normal text-muted-foreground">(${esc(field.suffix)})</span>`
            : '';

        const help = field.help
            ? `<p class="mt-1.5 text-xs text-muted-foreground">${esc(field.help)}</p>`
            : '';

        return `
            <div>
                <label class="field-label" for="attr-${esc(key)}">
                    ${esc(field.label)}${suffix}
                    ${field.required ? '' : '<span class="font-normal text-muted-foreground">(optional)</span>'}
                </label>
                ${attributeInput(key, field, value)}
                ${help}
            </div>`;
    }).join('');
}

/**
 * The control one field asks for.
 *
 * The data types are the *system's* capability rather than the shop's vocabulary
 * — the set of inputs this function knows how to draw — which is exactly why they
 * stayed an enum on the server while the categories and units became tables.
 */
function attributeInput(key, field, value) {
    const id = `attr-${esc(key)}`;
    const common = `id="${id}" class="field-input" data-attribute="${esc(key)}"`;

    switch (field.type) {
        case 'dropdown':
            return `
                <select ${common}>
                    <option value="">Choose…</option>
                    ${(field.values ?? []).map((option) => `
                        <option value="${esc(option)}" ${String(option) === String(value) ? 'selected' : ''}>
                            ${esc(option)}
                        </option>`).join('')}
                </select>`;

        case 'boolean':
            // A select rather than a checkbox, because a checkbox has two states
            // and this field has three: yes, no, and never answered. A tick box
            // would record "no" for every field nobody looked at.
            return `
                <select ${common}>
                    <option value="">—</option>
                    <option value="yes" ${String(value) === 'yes' ? 'selected' : ''}>Yes</option>
                    <option value="no" ${String(value) === 'no' ? 'selected' : ''}>No</option>
                </select>`;

        case 'date':
            return `<input type="date" ${common} value="${esc(value)}">`;

        case 'number':
        case 'decimal': {
            const step = field.type === 'number' ? '1' : 'any';
            const min = field.min !== undefined ? ` min="${esc(field.min)}"` : '';
            const max = field.max !== undefined ? ` max="${esc(field.max)}"` : '';

            return `<input type="number" step="${step}"${min}${max} inputmode="${
                field.type === 'number' ? 'numeric' : 'decimal'
            }" ${common} value="${esc(value)}" autocomplete="off">`;
        }

        default:
            return `<input type="text" ${common} value="${esc(value)}" autocomplete="off">`;
    }
}

function collectAttributes(host) {
    const bag = {};

    if (!host) return bag;

    $$('[data-attribute]', host).forEach((input) => {
        const value = String(input.value ?? '').trim();

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

    renderAttributeFields(
        $('#variant-attributes'),
        typeMeta(String(item.category_id ?? ''))?.attributes ?? {},
        editing ? (variant.attributes ?? {}) : {},
    );

    showModal('#variant-modal');
}

async function submitVariant() {
    const form = $('#variant-form');
    const id = form.elements.id.value;
    const itemId = form.elements.item_id.value;

    clearFormErrors(form);

    const body = {
        attributes: collectAttributes($('#variant-attributes')),
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
    { column: 'variants', label: 'Variants' },
    { column: 'stock', label: 'Stock', stock: true },
    { column: 'cost', label: 'Average cost', stock: true },
    { column: 'price', label: 'Selling price', stock: true },
    { column: 'status', label: 'Status' },
];

function renderSortPanel() {
    $list('#sort-panel').innerHTML = SORT_OPTIONS
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
        state.categoryId !== '',
        state.isStock !== '',
        state.isActive !== '1',
    ].filter(Boolean).length;

    const badge = $list('#filter-count');

    badge.textContent = active;
    badge.classList.toggle('hidden', active === 0);
}

function setPill(pill) {
    // Clicking the applied filter clears it, which is what a toggle means and
    // what the tiles look like they do.
    state.pill = state.pill === pill ? 'all' : pill;
    state.page = 1;

    $$list('#filter-pills [data-pill]').forEach((button) =>
        button.setAttribute('aria-pressed', String(button.dataset.pill === state.pill)));

    render();
}

function clearFilters() {
    state.search = '';
    state.categoryId = '';
    state.isStock = '';
    state.isActive = '1';
    state.pill = 'all';
    state.onlyDrafts = false;
    state.page = 1;

    $list('#filter-search').value = '';
    $list('#filter-type').value = '';
    $list('#filter-stock').value = '';
    $list('#filter-status').value = '1';
    $list('#draft-banner').classList.remove('ring-2', 'ring-amber-300');

    $$list('#filter-pills [data-pill]').forEach((button) =>
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

    // Held now, while everything is still in the document — see the note on the
    // declaration.
    itemForm = $('#item-form');
    listRoot = $('[data-ws-list]');

    applyStockVisibility();
    renderSortPanel();
    renderFilterCount();

    // Name, Category, Code, Variants, Status, Actions — and the four stock
    // columns where the caller may read them.
    const columns = state.canStock ? 10 : 7;

    // The types, units and attribute schema the *form* is built from. Fetched on
    // open because the form is what the module opens on; the catalogue itself is
    // not — see `loadList` below.
    await loadMeta();
    renderDraftBanner();

    /*
    | The Category and Unit masters, which live in a drawer over this workspace
    | rather than a page of their own (§1.5).
    |
    | `onChange` is what keeps the create form honest: adding a category has to
    | invalidate the vocabulary the form is built from, or the dropdown keeps
    | offering yesterday's list until somebody reloads — which §3.2 forbids
    | anyway.
    */
    initCatalogueMaster();

    const openMaster = (options = {}) => openCatalogueMaster({
        ...options,
        onChange: async (change = null) => {
            await loadMeta({ refresh: true });

            // A brand created from "Add brand" is the answer to the field the
            // user was on, so it is selected rather than merely offered — going
            // back to the form to pick what you just typed is a step nobody
            // needs (§7.5).
            if (change?.resource === 'brand' && change.action === 'created' && change.id) {
                $('#item-brand', itemForm).value = String(change.id);
            }

            applyTypeToForm({ editing: Boolean(itemForm.elements.id.value) });
        },
    });

    $('#manage-catalogue')?.addEventListener('click', () => openMaster());

    // "Add brand" beside the brand field, landing straight on the Brand tab —
    // the shortest route from "the make I need is missing" to the place it is
    // added, without losing what is already typed on the form (§7.5).
    $('#manage-brands', itemForm)?.addEventListener('click', () => openMaster({ tab: 'brands' }));

    // "Configure fields" beside the specification section, which lands straight
    // inside the category the form is currently on — the shortest route from
    // "this field is missing" to the place it is added (§7.5).
    $('#item-attributes-configure', itemForm)?.addEventListener('click', () => openMaster({
        categoryId: $('#item-type', itemForm).value || null,
    }));

    /*
    | §2A.7 — the catalogue is fetched the first time the list is asked for, and
    | held from then on. Somebody who opened Items only to add one never pays for
    | two hundred rows they did not look at.
    */
    const loadList = async () => {
        $list('#items-body').innerHTML = tableMessage(columns, 'Loading items…');

        try {
            await Promise.all([loadCatalogue(), loadStock()]);
            render();
        } catch (error) {
            // A platform super-admin holds every permission but belongs to no
            // workshop, so they can reach this module and there is nothing to
            // show them. Not their mistake — say so plainly.
            $list('#items-body').innerHTML = error.code === 'NO_WORKSPACE'
                ? tableMessage(columns, 'Your account administers the platform rather than a single workshop, so it has no catalogue of its own.')
                : tableMessage(columns, error.message, 'error');

            $list('#items-summary').textContent = '';
        }
    };

    /* Toolbar ---------------------------------------------------------- */

    $list('#filter-search').addEventListener('input', debounce((event) => {
        state.search = event.target.value.trim();
        state.page = 1;
        render();
    }, 200));

    $list('#filter-toggle').addEventListener('click', (event) => {
        event.stopPropagation();

        const panel = $list('#filter-panel');
        const open = panel.classList.toggle('hidden');

        $list('#filter-toggle').setAttribute('aria-expanded', String(!open));
        $list('#sort-panel').classList.add('hidden');
    });

    $list('#sort-toggle').addEventListener('click', (event) => {
        event.stopPropagation();

        const panel = $list('#sort-panel');
        const open = panel.classList.toggle('hidden');

        $list('#sort-toggle').setAttribute('aria-expanded', String(!open));
        $list('#filter-panel').classList.add('hidden');
    });

    $list('#sort-panel').addEventListener('click', (event) => {
        const option = event.target.closest('[data-sort-option]');
        if (!option) return;

        applySort(option.dataset.sortOption, option.dataset.sortDirection);
        $list('#sort-panel').classList.add('hidden');
        $list('#sort-toggle').setAttribute('aria-expanded', 'false');
    });

    ['filter-type', 'filter-stock', 'filter-status'].forEach((id) => {
        $(`#${id}`)?.addEventListener('change', (event) => {
            const key = { 'filter-type': 'categoryId', 'filter-stock': 'isStock', 'filter-status': 'isActive' }[id];

            state[key] = event.target.value;
            state.page = 1;

            renderFilterCount();
            render();
        });
    });

    $list('#filter-pills').addEventListener('click', (event) => {
        const pill = event.target.closest('[data-pill]');

        if (pill) setPill(pill.dataset.pill);
    });

    $$('[data-stat-filter]').forEach((tile) =>
        tile.addEventListener('click', () => setPill(tile.dataset.statFilter)));

    $list('#clear-filters').addEventListener('click', clearFilters);

    $list('#draft-banner').addEventListener('click', () => {
        state.onlyDrafts = !state.onlyDrafts;
        $list('#draft-banner').classList.toggle('ring-2', state.onlyDrafts);
        $list('#draft-banner').classList.toggle('ring-amber-300', state.onlyDrafts);
        state.page = 1;
        render();
    });

    $list('#items-head').addEventListener('click', (event) => {
        const th = event.target.closest('[data-sort]');

        if (th) applySort(th.dataset.sort);
    });

    $list('#items-pager').addEventListener('click', (event) => {
        const button = event.target.closest('[data-page]');
        if (!button) return;

        state.page = Number(button.dataset.page);
        render();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    /* The table -------------------------------------------------------- */

    $list('#items-body').addEventListener('click', (event) => {
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
    $list('#items-body').addEventListener('keydown', (event) => {
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

    itemForm.addEventListener('submit', (event) => {
        event.preventDefault();
        submitItem();
    });

    $('#item-type', itemForm).addEventListener('change', () => applyTypeToForm({ editing: false }));

    $('#variant-form').addEventListener('submit', (event) => {
        event.preventDefault();
        submitVariant();
    });

    /*
    | One listener for every "click away to dismiss": the row menus and both
    | toolbar popovers.
    |
    | Optional throughout, because this is bound to the document and the toolbar
    | it reaches for is *detached* whenever the workspace is showing its form —
    | the list surface keeps its DOM off screen rather than being rebuilt
    | (§2A.2). A click on the form would otherwise throw on every one of these.
    */
    document.addEventListener('click', () => {
        closeMenus();
        $list('#filter-panel')?.classList.add('hidden');
        $list('#sort-panel')?.classList.add('hidden');
        $list('#filter-toggle')?.setAttribute('aria-expanded', 'false');
        $list('#sort-toggle')?.setAttribute('aria-expanded', 'false');
    });

    $list('#filter-panel').addEventListener('click', (event) => event.stopPropagation());

    /* The workspace ---------------------------------------------------- */

    /*
    | Mounted last, and that matters: everything above binds to nodes that are
    | still in the document, and mounting is what detaches whichever of the two
    | surfaces is not in use. Listeners survive the detachment — they belong to
    | the elements, not to the document.
    */
    const canWrite = can('WRITE', 'ITEMS');

    if (canWrite) await openItemForm();

    workspace = mountWorkspace(itemForm.closest('[data-module-root]'), {
        key: 'items',
        title: 'Items',
        formSubtitle: 'Add an item to the catalogue, or show what is already there.',
        listSubtitle: (count) => (count === null
            ? 'Catalogue, stock levels and pricing.'
            : `${count} item${count === 1 ? '' : 's'} in the catalogue. Click a row to open it.`),
        createLabel: 'Create item',
        count: () => (state.items.length ? state.items.length : null),
        canCreate: canWrite,
        onShowList: loadList,

        /*
        | Bring the form home.
        |
        | It may have been left in the edit dialog — closed with Cancel, with
        | Escape, or by a save — and level 1 is where it lives. A form still
        | holding an item's id is that item's *edit* form, so it is reset to a
        | blank one rather than offered as a draft: a half-typed new item is
        | worth keeping (§2A.6), somebody else's record is not.
        */
        onShowForm: async () => {
            if (itemForm.elements.id.value) {
                await openItemForm();

                return;
            }

            adoptForm(itemForm, $('[data-item-form-slot]'), { chrome: 'inline' });
            $('#item-name', itemForm)?.focus();
        },
    });
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
