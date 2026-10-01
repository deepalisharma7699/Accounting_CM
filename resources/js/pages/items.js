import auth from '../auth-client';
import {
    collectAttributes, defaultsFor, describeAttributes, renderAttributeFields,
} from '../components/attribute-fields';
import { formatQuantity } from '../components/badge';
import { mountItemPicker } from '../components/item-picker';
import {
    averageCostOf, positionStatus, rollUpPositions, statusOfRow, stockStatusBadge,
} from '../components/stock-position';
import { can } from '../permissions';
import { initStockAdjust, openStockAdjust } from '../components/stock-adjust';
import { clearModuleParams, moduleParams } from '../shell';
import {
    $, $$, clearFormErrors, confirmAction, debounce, esc, formatDate, formatMoney,
    hideModal, setSubmitting, showFormErrors, showFormMessage, showModal, tableMessage, toast,
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

    /*
    | Whether a family's archived variants are being shown.
    |
    | Module-level rather than per surface, because the drawer's Variants tab and
    | the item form's picker are the same list drawn by the same renderer and are
    | never on screen together — two flags would be one preference the user had to
    | set twice, and they would disagree.
    */
    showArchivedVariants: false,

    /*
    | The product the form is editing, or null while it is writing a new one.
    |
    | Not the same thing as `openItem`: the drawer is one way in, and the row
    | menu's Edit is another that never opens one. Held rather than read back off
    | the form, because the variant half needs the *record* — how many things are
    | on the shelf under it, which of them a block stands for, and what its
    | position is — and a hidden input holds an id.
    */
    formItem: null,
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

/**
 * The review queue's headline — items *and* the variants under them.
 *
 * `/items/meta` has always published both counts and this banner read only the
 * first, so a workshop whose import left forty unchecked ratings under checked
 * products was told there was nothing to review. A queue that cannot see half
 * its work is worse than no queue: it says the job is done.
 */
function renderDraftBanner() {
    const counts = state.meta?.draft_counts ?? {};
    const items = counts.items ?? 0;
    const variants = counts.variants ?? 0;
    const count = items + variants;

    const banner = $list('#draft-banner');

    // Hidden when there is nothing in the queue rather than shown reading "0":
    // a permanent empty banner is a banner people stop seeing.
    banner.classList.toggle('hidden', count === 0);
    banner.classList.toggle('flex', count > 0);

    if (count > 0) {
        // Both named separately, because they are checked in different places:
        // an item from its row, a variant from the drawer's Variants tab.
        const parts = [
            items ? `${items} item${items === 1 ? '' : 's'}` : '',
            variants ? `${variants} variant${variants === 1 ? '' : 's'}` : '',
        ].filter(Boolean);

        $list('#draft-banner-title').textContent =
            `${parts.join(' and ')} need${count === 1 ? 's' : ''} reviewing`;
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

/**
 * Whether this family has anything waiting to be checked.
 *
 * The family itself or any rating under it. Both are `is_draft`, both are
 * cleared the same way, and both are the queue's business — an item somebody has
 * confirmed whose variants nobody has looked at is exactly the row that would
 * otherwise disappear from the queue while still being unchecked.
 *
 * Falls back to the item's own flag where the row does not carry its variants,
 * which is what the server's count already includes.
 */
function needsReview(item) {
    return Boolean(item.is_draft) || (item.variants ?? []).some((variant) => variant.is_draft);
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
        if (state.onlyDrafts && !needsReview(item)) return false;

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
        needsReview(item) ? '<span class="badge bg-amber-100 text-amber-800">Needs review</span>' : '',
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
const iconPlus = svg('<path d="M12 5v14"/><path d="M5 12h14"/>', 15);
const iconArchive = svg('<rect x="2" y="3" width="20" height="5" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>', 13);
const iconRestore = svg('<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M12 8v4l3 2"/>', 13);
const iconOpening = svg('<path d="M3 21h18"/><path d="M5 21V8l7-5 7 5v13"/><path d="M9 21v-6h6v6"/>', 13);
const iconCount = svg('<rect width="8" height="4" x="8" y="2" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/>', 13);
const iconChecked = svg('<path d="M21.8 10A10 10 0 1 1 17 3.34"/><path d="m9 11 3 3L22 4"/>', 13);
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
        // Above Edit while it is waiting: the row is in the review queue, and
        // clearing it is what the person who opened this menu came for.
        if (item.is_draft) {
            entries.push({ label: 'Mark as checked', icon: iconChecked, action: 'checked' });
        }

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
    } else if (status === 'below_minimum') {
        // Said as the floor rather than as a colour: this is the workshop's own
        // figure, and the sentence is what makes the badge worth reading.
        message = `${roll.below} variant${roll.below === 1 ? ' is' : 's are'} below the minimum `
            + 'this workshop set for them.';
    } else if (status === 'low') {
        message = `${roll.low} variant${roll.low === 1 ? ' is' : 's are'} at or below the reorder level.`;
    } else if (item.is_draft) {
        message = 'Auto-created from an import or a capture and not yet checked.';
    } else if (needsReview(item)) {
        // The family has been confirmed and something under it has not. Says
        // where to go, because the badge on the row is the same either way.
        const pending = (item.variants ?? []).filter((variant) => variant.is_draft).length;

        message = `${pending} variant${pending === 1 ? '' : 's'} auto-created and not yet checked — `
            + 'open the Variants tab to look through them.';
    }

    alert.classList.toggle('hidden', message === '');
    alert.classList.toggle('flex', message !== '');
    $('#drawer-alert-text').textContent = message;

    // The family's own flag only. A drawer whose *variants* are waiting is told
    // to go to the Variants tab and sign them off one at a time, which is the
    // whole point of them being separate flags.
    //
    // The grant is checked here too, because this control's visibility is already
    // conditional and `data-requires-permission` decides the same `hidden` class
    // — two writers, and whichever ran last would win.
    $('#drawer-clear-draft').classList.toggle(
        'hidden',
        !(item.is_draft && can('UPDATE', 'ITEMS'))
    );
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
                            roll.below > 0 ? ` · ${roll.below} below minimum` : ''}${
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
        ['Price basis', item.price_includes_tax ? 'Includes GST' : 'Before GST'],
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
 * One variant's own position, from M8 rather than from anything on this screen.
 *
 * Null where the caller holds no READ:STOCK, which is not the same as zero — the
 * distinction the whole module is built around. Callers show nothing rather than
 * a figure: an empty cell reads as "none on the shelf" where it means "not yours
 * to see".
 */
function positionFor(itemId, variantId) {
    if (!state.canStock || itemId === null || itemId === undefined) return null;

    return (state.stock.get(itemId)?.positions ?? [])
        .find((row) => String(row.variant_id) === String(variantId)) ?? null;
}

/** One variant's position as a sentence, for the block that edits it. */
function describePosition(position, item) {
    // Painted with textContent rather than into markup, so nothing here is
    // escaped — escaping it would print the entities.
    const unit = item?.base_uom_symbol ? ` ${item.base_uom_symbol}` : '';

    const held = position.is_negative
        ? `${formatQuantity(position.quantity)}${unit} — more has been issued than was ever received`
        : `${formatQuantity(position.quantity)}${unit} on the shelf`;

    return position.average_cost === null
        ? held
        : `${held}, worth ${money(position.value)} at ${money(position.average_cost)} average`;
}

/**
 * The quantity's own colour, on the ladder the badge beside it uses.
 *
 * A map rather than a chain of ternaries because it was a chain of ternaries,
 * and a fourth state would have made it unreadable.
 */
const QUANTITY_TONE = {
    negative: 'text-rose-500',
    out: 'text-rose-500',
    below_minimum: 'text-orange-600',
    low: 'text-amber-600',
    in_stock: 'text-foreground',
};

/**
 * What the workshop said about this variant's shelf, under the figure.
 *
 * Both levels where both are set, because they answer different questions and
 * the floor is the one that turned the row orange. "No reorder level" stays the
 * wording where neither is set: it is the commoner absence, and naming both
 * would be a sentence about two things a workshop has not done.
 */
function describeLevels(position) {
    const parts = [];

    if (position.reorder_level !== null && position.reorder_level !== undefined) {
        parts.push(`reorder at ${formatQuantity(position.reorder_level)}`);
    }

    if (position.min_stock !== null && position.min_stock !== undefined) {
        parts.push(`never below ${formatQuantity(position.min_stock)}`);
    }

    return parts.length === 0 ? 'no reorder level' : esc(parts.join(' · '));
}

/**
 * The variants of one family as rows, with whatever controls the caller puts on
 * the right.
 *
 * Two screens ask this — the drawer's Variants tab and the edit form's picker —
 * and both show the same four facts: what it is, its code, its price, and what
 * is on the shelf. One renderer, because two would answer "what is under this
 * product" in two ways, and the pair would drift on the first column either of
 * them gained (§4.4).
 *
 * Archived ratings are **hidden by default and counted out loud**. A workshop
 * that has dealt in a part for ten years has archived more of them than it still
 * stocks, and a list where the live ones are three rows in twenty is a list
 * nobody reads. What is never done is hiding them silently: the footer states
 * the number, so the panel cannot claim a family has less under it than it does.
 *
 * @param {object} item                       The family.
 * @param {(variant: object) => string} actionsFor  The controls for one row.
 */
function variantRows(item, actionsFor) {
    const all = item.variants ?? [];
    const archived = all.filter((variant) => !variant.is_active);
    const shown = state.showArchivedVariants ? all : all.filter((variant) => variant.is_active);

    const rows = shown.map((variant) => {
        const position = positionFor(item.id, variant.id);

        const quantity = position
            ? `
                <div class="text-right">
                    <p class="text-[13px] font-bold ${
                        QUANTITY_TONE[statusOfRow(position)] ?? 'text-foreground'}">
                        ${formatQuantity(position.quantity)}
                        <span class="text-[11px] font-medium text-muted-foreground">${esc(item.base_uom_symbol)}</span>
                    </p>
                    <p class="text-[11.5px] text-muted-foreground">
                        ${describeLevels(position)}
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
                <div class="flex shrink-0 gap-1">${actionsFor(variant)}</div>
            </div>`;
    }).join('');

    // Nothing put away, nothing to say about it.
    if (!archived.length) return rows;

    const toggle = `
        <button type="button" class="btn btn-ghost btn-sm text-muted-foreground" data-toggle-archived>
            ${state.showArchivedVariants
                ? `Hide the ${archived.length} archived`
                : `Show ${archived.length} archived`}
        </button>`;

    if (shown.length) return `${rows}${toggle}`;

    // Every one of them is archived. Without this the panel would be blank with
    // a button under it, which reads as a family that has nothing on the shelf
    // rather than one whose shelf was cleared.
    return `
        <p class="rounded-[12px] border border-dashed border-border px-4 py-5 text-center
                  text-[0.8125rem] text-muted-foreground">
            Everything under this product has been archived.
        </p>
        ${toggle}`;
}

/**
 * The variants of one family, each with its own position.
 *
 * This is where the roll-up on the row is broken back down, and it is the reason
 * the row can afford to be a summary: "12 in stock" is only useful if one click
 * says which twelve.
 *
 * The pencil opens the **item form** on that variant rather than an editor of
 * its own. There used to be a second one here, and the two had already drifted:
 * this dialog could set a target markup the create form could not, and the
 * create form could set a barcode, a purchase price and a minimum this dialog
 * dropped on the floor (§5.1).
 */
function drawerVariants(item) {
    const variants = item.variants ?? [];
    const mayUpdate = can('UPDATE', 'ITEMS');
    const mayDelete = can('DELETE', 'ITEMS');
    const mayCount = state.canStock && can('WRITE', 'TRANSACTIONS') && item.can_hold_stock !== false;
    /*
    | Declaring opening stock needs UPDATE:WORKSPACE as well as
    | WRITE:TRANSACTIONS, which is what the endpoint asks for and in practice
    | means the owner. Deliberately not widened here: saying what the workshop
    | already owned is a setup act, dated at go-live and posted against the
    | owner's stake, and it sits beside books_start_date rather than beside the
    | day's takings (§6.2 — the grant is checked server-side either way).
    */
    const mayOpen = mayCount && can('UPDATE', 'WORKSPACE');


    if (!variants.length) {
        /*
        | Which of the two it is, and this branch was dead until now.
        |
        | It asked `item.type === 'service'`, and there has been no `type` on an
        | item since the catalogue's vocabulary became rows an admin edits — so
        | every product with nothing under it, labour included, was told to add
        | the ratings it buys and sells. `can_hold_stock` is the category's own
        | answer, which is the question that was meant.
        */
        return `
            <p class="py-8 text-center text-sm text-muted-foreground">
                No variants yet. ${item.can_hold_stock === false
                    ? 'Something that is produced when it is sold usually needs just one — add it so bills can reference it.'
                    : 'Add the ratings you actually buy and sell.'}
            </p>`;
    }

    return `
        <div class="space-y-2">
            ${variantRows(item, (variant) => [
                /*
                | It takes nothing away, so it does not confirm (§3.5): it is
                | somebody saying they looked, and the way back is the row's own
                | pencil. First in the group because while a rating is unchecked
                | it is the only thing on the row worth doing.
                */
                mayUpdate && variant.is_draft ? `<button type="button" class="btn btn-ghost btn-icon text-amber-600"
                                     data-clear-variant-draft="${variant.id}"
                                     title="Mark as checked" aria-label="Mark as checked">${iconChecked}</button>` : '',
                /*
                | Only while the shelf is empty, and that is the whole of when
                | it is the right answer.
                |
                | A variant with a position has had something move through it,
                | and what it needs then is a count — this would be declaring an
                | opening figure on top of a shelf that already has a history.
                | Once anything has been declared the server skips it anyway
                | (`hasOpeningStock`), so the button going quiet at nought is the
                | client agreeing with the rule rather than inventing one.
                */
                mayOpen && variant.is_active && !hasPosition(item, variant)
                    ? `<button type="button" class="btn btn-ghost btn-icon" data-opening-stock="${variant.id}"
                               title="Declare opening stock" aria-label="Declare opening stock">${iconOpening}</button>`
                    : '',
                mayCount && variant.is_active ? `<button type="button" class="btn btn-ghost btn-icon" data-set-stock="${variant.id}"
                                     title="Set what is on the shelf" aria-label="Set what is on the shelf">${iconCount}</button>` : '',
                mayUpdate ? `<button type="button" class="btn btn-ghost btn-icon" data-edit-variant="${variant.id}"
                                     title="Edit variant" aria-label="Edit variant">${iconPencil}</button>` : '',
                mayUpdate ? `<button type="button" class="btn btn-ghost btn-icon" data-toggle-variant="${variant.id}"
                                     data-variant-active="${variant.is_active}"
                                     title="${variant.is_active ? 'Archive variant' : 'Restore variant'}"
                                     aria-label="${variant.is_active ? 'Archive variant' : 'Restore variant'}">${
                                         variant.is_active ? iconArchive : iconRestore}</button>` : '',
                mayDelete ? `<button type="button" class="btn btn-ghost btn-icon" data-delete-variant="${variant.id}"
                                     title="Delete variant" aria-label="Delete variant">${iconTrash}</button>` : '',
            ].filter(Boolean).join(''))}
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

    if (!category) {
        // Which still repaints the blocks: with no category chosen there is no
        // question set, and a specification section left up from the last one
        // asks for fields this product will never be validated against.
        reindexVariants();

        return;
    }

    $('#item-hsn-label', form).textContent = `${category.tax_code_label} code`;

    if (!editing) {
        $('#item-uom', form).value = category.default_uom;

        /*
        | The category's defaults are *copied* onto the product, never
        | referenced — correcting a category's rate next March must not restate
        | what every product already charges.
        |
        | Which of three figures the box ends on is decided here, and the order
        | matters: the rate the user typed, else the category's own, else the
        | prefill the markup lands on. The test is `userSet` rather than an empty
        | box because the box is no longer empty to start with — reading
        | emptiness would leave a 12% category quietly charging the prefilled 18.
        */
        const gst = $('#item-gst', form);

        if (gst.dataset.userSet !== '1' && category.default_gst_rate !== null) {
            gst.value = category.default_gst_rate;
        }

        // Where the figure in the box came from, said on the form: all three are
        // a value somebody can tab straight past, so which one it is has to be
        // visible rather than inferred.
        const hint = $('#item-gst-hint', form);

        if (hint) {
            if (gst.dataset.userSet === '1') {
                hint.textContent = 'A percentage — 18, not 0.18.';
            } else if (category.default_gst_rate === null) {
                hint.textContent = `${category.label ?? 'This category'} states no rate of its own — `
                    + `${gst.defaultValue}% is filled in. Change it if this product differs, or 0 if it is exempt.`;
            } else {
                hint.textContent = `${category.default_gst_rate}% from ${category.label ?? 'the category'}. `
                    + 'Change it if this product differs.';
            }
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

    $('#item-type-hint', form).textContent = editing
        ? 'Fixed once the product exists: changing it would reinterpret everything recorded against it.'
        : (category.description || `Asks for ${describeAttributes(category.attributes)}.`);

    // What this category asks for, said once above the blocks that ask it.
    const keys = Object.keys(category.attributes ?? {});
    const forLabel = $('#item-variants-for', form);

    /*
    | "a" or "an", because the noun is the workshop's own and a hard-coded "a"
    | printed "what a air fan is described by" on the category a real workshop
    | added. Initial-vowel is the wrong rule for a handful of English words and
    | the right one for every category name anybody has typed here.
    */
    if (forLabel) {
        const noun = (category.label ?? '').toLowerCase();
        const article = /^[aeiou]/.test(noun) ? 'an' : 'a';

        forLabel.textContent = keys.length ? `— what ${article} ${noun} is described by` : '';
    }

    // Every block repainted: the fields this category asks for, the unit its
    // quantity is counted in, and whether its stock boxes apply at all. On an
    // edit there are no blocks, and this does nothing.
    reindexVariants();
}

/**
 * Grey every block's stock boxes out when the product is not being stocked, and
 * remove them for a category that can hold none.
 *
 * Two different answers to two different questions. "Keep stock of this" is off
 * for a part bought to order, which is a choice — so the boxes stay visible and
 * inert, because the checkbox above them is what explains why, and a box that
 * vanishes reads as a bug. A category that holds no stock at all is not a
 * choice: an opening quantity of labour would be inventing an asset, and
 * offering the box teaches somebody it is possible.
 */
/**
 * Whether the product being edited is counted on a shelf of its own.
 *
 * The item's own switch *and* the category's capability, which is
 * `Item::tracksStock()` on the server — one answer, because the stock boxes and
 * the recipe section are the two sides of the same question and a screen showing
 * both, or neither, would be asking somebody to resolve it.
 */
function formTracksStock() {
    const category = typeMeta($('#item-type', itemForm).value);
    const canHoldStock = category ? category.can_hold_stock !== false : true;

    return Boolean($('#item-stock', itemForm)?.checked) && canHoldStock;
}

function applyStockFieldsState() {
    const form = itemForm;
    const category = typeMeta($('#item-type', form).value);
    const canHoldStock = category ? category.can_hold_stock !== false : true;
    const on = formTracksStock();

    $$('[data-variant-stock]', form).forEach((fields) => {
        fields.classList.toggle('hidden', !canHoldStock);
        fields.classList.toggle('opacity-50', !on);

        $$('input', fields).forEach((input) => {
            input.disabled = !on;
        });
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

/* -------------------------------------------------------------------------
 | The variant half
 |
 | Two panes over one set of fields, and which is up depends on how many things
 | are on the shelf under this product:
 |
 |   create          the repeater — a block per variant, all submitted together
 |   edit, one       that variant's block, straight away: for the overwhelming
 |                   majority of products the family and the thing on the shelf
 |                   are one record in the user's head, and splitting them over a
 |                   dialog, a drawer, a tab and a second dialog was five clicks
 |                   to correct a SKU
 |   edit, several   the list — no single set of boxes could mean any one of them
 |                   — and a pencil opens the block for the one picked
 |
 | The block is the *only* variant editor in this module. The drawer's pencil
 | opens this form on that variant rather than a dialog of its own: two editors
 | over one record is the drift §5.1 exists to prevent, and the pair here had
 | already reached it — the dialog could set a target markup the create form
 | could not, and the create form could set a barcode, a purchase price and a
 | minimum the dialog dropped on the floor.
 |
 | The position is *stamped on* rather than rendered, so the template in the
 | markup carries no index and a removal is a walk over four data hooks. Every
 | `name`, `id`, `for` and `data-error-for` inside a block is written by
 | {@link indexVariantBlock} and nowhere else — which is what gives a 422 about
 | `variants.2.sell_price` a box to land in rather than a banner above five
 | identical blocks.
 | ---------------------------------------------------------------------- */

/** Every block on the form, in the order they will be submitted. */
const variantBlocks = () => $$('[data-variant-block]', itemForm);

/** True while the form is correcting a product rather than writing a new one. */
const editingItem = () => state.formItem !== null;

/**
 * One block's value, by the API key its input is declared under.
 *
 * Three answers, not two. A box somebody emptied is **null** — clearing a price
 * is a real edit, and the only way to say so. A box the form is not asking about
 * is **undefined**, which `JSON.stringify` drops, so the key never reaches the
 * server and `StoreVariantRequest::payload()` leaves that column exactly as it
 * was.
 *
 * Which is the difference between the two disabled cases. Unticking "Keep stock
 * of this" after typing an opening quantity must not send the quantity: the
 * server cannot see a greyed box, and it would answer with a warning about stock
 * that was never going to be recorded. But the reorder level and the minimum are
 * greyed by the same switch, and sending *those* as null would quietly wipe two
 * figures somebody set while the product was still being stocked.
 */
const variantValue = (block, field) => {
    const input = $(`[data-variant-field="${field}"]`, block);

    if (!input || input.disabled) return undefined;

    return input.value.trim() || null;
};

function indexVariantBlock(block, index) {
    const key = (field) => (field ? `variants.${index}.${field}` : `variants.${index}`);

    $('[data-variant-number]', block).textContent = String(index + 1);

    $$('[data-variant-field]', block).forEach((input) => {
        input.id = `item-variant-${index}-${input.dataset.variantField}`;
        input.name = key(input.dataset.variantField);
    });

    $$('[data-variant-label]', block).forEach((label) => {
        label.setAttribute('for', `item-variant-${index}-${label.dataset.variantLabel}`);
    });

    // `data-variant-error` with no value is the block's own footer, which is
    // where a refusal that named no field of its own lands — see
    // ApiException::underField().
    $$('[data-variant-error]', block).forEach((slot) => {
        slot.setAttribute('data-error-for', key(slot.dataset.variantError));
    });
}

/**
 * Draw the specification this category asks for, into one block.
 *
 * Values already typed survive the repaint, and that matters more than it looks:
 * "Configure fields" is opened *from* this form, halfway through filling it in,
 * and the schema comes back changed — with three blocks up, wiping them costs
 * three specifications rather than one. A key the new category does not have is
 * dropped for free, because the render walks the schema and not the values.
 *
 * What sits underneath those typed values is the difference between the two
 * panes. A new variant falls back to the schema's own defaults. One that already
 * exists falls back to **its stored bag** and never to a default: a category's
 * defaults arriving on an edit would refill a field somebody had deliberately
 * left empty, and `normaliseAttributes()` re-validates the whole set on the way
 * back — so a motor that lost its HP here is either saved without it or refused
 * with nothing on screen saying why.
 */
function paintVariantSpecification(block, index) {
    const schema = typeMeta($('#item-type', itemForm).value)?.attributes ?? {};
    const host = $('[data-variant-attributes]', block);
    const held = block.variantRecord ? (block.variantRecord.attributes ?? {}) : defaultsFor(schema);

    $('[data-variant-attributes-section]', block)
        .classList.toggle('hidden', Object.keys(schema).length === 0);

    renderAttributeFields(
        host,
        schema,
        { ...held, ...collectAttributes(host) },
        `attr-v${index}`,
    );
}

/**
 * What one block shows, which depends on whether it stands for something that
 * already exists.
 *
 * Opening stock is create-only: it is a stock adjustment that posted on the day
 * the shelf was counted, and there is no second one to be had by retyping the
 * figure here — correcting it is a count, from the screen that counts. A name
 * and a target markup are the other way round: a variant is named where its
 * specification has failed to say what it is, and a markup suggests a price
 * against an average that nothing has moved yet.
 */
/* -------------------------------------------------------------------------
 | The recipe — what a made thing consumes
 | ---------------------------------------------------------------------- */

/**
 * The rows live on the node, exactly as the bound variant record does.
 *
 * A block is cloned in and thrown away, and a list keyed by index beside it
 * would be a second place the binding is decided — wrong from the first removal,
 * which is the reasoning `block.variantRecord` already records.
 */
function recipeRowsOf(block) {
    if (!Array.isArray(block.recipeRows)) block.recipeRows = [];

    return block.recipeRows;
}

/** What one material's row knows, from either of the two places it can arrive. */
function recipeRowFrom(component) {
    return {
        variant_id: Number(component.component_variant_id),
        item_id: component.component_item_id ?? null,
        label: component.item_name && component.label && component.item_name !== component.label
            ? `${component.item_name} · ${component.label}`
            : (component.label ?? component.item_name ?? `#${component.component_variant_id}`),
        unit_symbol: component.unit_symbol ?? '',
        quantity: component.quantity ?? '',
    };
}

/**
 * Paint the whole section: whether it is offered at all, its rows, and the
 * estimate under them.
 *
 * Offered only for a product that holds no stock of its own — the other half of
 * `applyStockFieldsState()`, from the opposite side of one question.
 */
function paintVariantRecipe(block) {
    const section = $('[data-variant-recipe]', block);

    if (!section) return;

    const offered = !formTracksStock();

    section.classList.toggle('hidden', !offered);

    if (!offered) return;

    const rows = recipeRowsOf(block);
    const host = $('[data-recipe-rows]', block);

    host.innerHTML = rows.map((row, index) => `
        <div class="flex flex-wrap items-center gap-2" data-recipe-row data-index="${index}">
            <span class="min-w-0 flex-1 truncate text-[0.8125rem] text-foreground">${esc(row.label)}</span>
            <div class="relative w-32">
                <input type="text" inputmode="decimal" value="${esc(String(row.quantity ?? ''))}"
                       class="field-input pr-12 text-right font-mono" data-recipe-qty aria-label="Quantity">
                <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">${esc(row.unit_symbol)}</span>
            </div>
            <button type="button" class="btn btn-ghost btn-sm text-rose-600" data-recipe-remove
                    aria-label="Remove ${esc(row.label)}">
                ${iconTrash}
            </button>
        </div>
    `).join('');

    $('[data-recipe-empty]', block).classList.toggle('hidden', rows.length > 0);

    paintRecipeCost(block);
}

/**
 * "Materials ≈ ₹1,915 at today's cost", from the positions the screen already
 * holds.
 *
 * No request and no second arithmetic: `loadStock()` has every variant's
 * weighted average, and the multiplication is the one `Quantity::costAt()`
 * performs on the server for the figure that actually gets posted. This is an
 * estimate and says so — what a bill is charged is the average at the moment it
 * posts, and a rate set against a fortnight-old copper price is exactly what the
 * line is here to prevent.
 */
function paintRecipeCost(block) {
    const slot = $('[data-recipe-cost]', block);
    const rows = recipeRowsOf(block);

    // Nothing to say with no rows, and nothing honest to say when the reader
    // cannot see stock at all — a figure derived from positions they are not
    // shown would be a cost leaking past the permission that withholds it.
    if (rows.length === 0 || !state.canStock) {
        slot.classList.add('hidden');

        return;
    }

    let total = 0;
    let unpriced = 0;

    rows.forEach((row) => {
        const position = positionFor(row.item_id, row.variant_id);
        const cost = Number(position?.average_cost ?? 0);
        const quantity = Number(row.quantity ?? 0);

        if (!position || !(cost > 0)) unpriced += 1;

        total += cost * (Number.isFinite(quantity) ? quantity : 0);
    });

    slot.classList.remove('hidden');
    slot.textContent = unpriced > 0
        ? `Materials ≈ ${formatMoney(total)} at today's cost — ${unpriced} of them has no cost on the shelf yet.`
        : `Materials ≈ ${formatMoney(total)} at today's cost.`;
}

/**
 * The material search, shown only while adding one.
 *
 * `mountItemPicker` is the bill counter's own, so the badge beside each result
 * is the live position and there is no second way to search the catalogue
 * (§5.1). What it will not accept is a service: a recipe consumes something that
 * is counted, and a row naming labour would take nothing off anything while
 * looking on screen as though it did.
 */
function openRecipePicker(block) {
    const host = $('[data-recipe-picker]', block);

    host.classList.remove('hidden');

    const picker = mountItemPicker(host, {
        hint: 'Materials only — something counted on a shelf.',
        onPick: (choice) => {
            host.classList.add('hidden');
            host.innerHTML = '';

            if (choice.kind !== 'stock') {
                toast('A recipe consumes something that is counted on a shelf. That one holds no stock.', 'warning');

                return;
            }

            const rows = recipeRowsOf(block);

            if (rows.some((row) => row.variant_id === Number(choice.variant_id))) {
                toast('That material is already on this recipe — change its quantity instead.', 'warning');

                return;
            }

            rows.push({
                variant_id: Number(choice.variant_id),
                item_id: choice.item_id ?? null,
                label: choice.label,
                unit_symbol: choice.unit_symbol ?? '',
                quantity: '',
            });

            paintVariantRecipe(block);

            // Straight to the box they have to fill in, which is the only thing
            // left to say about the row they just chose.
            $$('[data-recipe-row]', block).at(-1)?.querySelector('[data-recipe-qty]')?.focus();
        },
    });

    picker.focus();
}

/**
 * The section's own events, bound once per block.
 *
 * Delegated from the section rather than per row, because the rows are rebuilt
 * on every paint and handlers attached to them would be rebound each time —
 * which is how a remove button ends up firing twice.
 */
function bindVariantRecipe(block) {
    const section = $('[data-variant-recipe]', block);

    if (!section || section.dataset.bound === '1') return;

    section.dataset.bound = '1';

    $('[data-recipe-add]', section).addEventListener('click', () => openRecipePicker(block));

    section.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-recipe-remove]');

        if (!remove) return;

        const index = Number(remove.closest('[data-recipe-row]').dataset.index);

        recipeRowsOf(block).splice(index, 1);
        paintVariantRecipe(block);
    });

    section.addEventListener('input', (event) => {
        const box = event.target.closest('[data-recipe-qty]');

        if (!box) return;

        const index = Number(box.closest('[data-recipe-row]').dataset.index);

        recipeRowsOf(block)[index].quantity = box.value;

        // The rows are not repainted — that would take the caret out of the box
        // being typed in. Only the figure under them moves.
        paintRecipeCost(block);
    });
}

function paintVariantBlockMode(block) {
    const variant = block.variantRecord;

    $$('[data-opening-field]', block).forEach((node) => node.classList.toggle('hidden', editingItem()));
    $$('[data-variant-edit-only]', block).forEach((node) => node.classList.toggle('hidden', !variant));

    $('[data-variant-generic]', block).classList.toggle('hidden', Boolean(variant));
    $('[data-variant-name]', block).classList.toggle('hidden', !variant);

    if (variant) $('[data-variant-name]', block).textContent = variant.display_label;

    const slot = $('[data-variant-position]', block);
    const position = variant ? positionFor(state.formItem?.id, variant.id) : null;

    slot.classList.toggle('hidden', position === null);

    if (position) slot.textContent = describePosition(position, state.formItem);

    bindVariantRecipe(block);
    paintVariantRecipe(block);
}

/**
 * Re-stamp every block with its position, and repaint what depends on it.
 *
 * After any add or removal, because the position is what the server counts its
 * error keys in: leave the third block named `variants.2` once the first has
 * gone and its 422 paints into the block above it. The attribute inputs are
 * repainted for the same reason — their ids carry the index too.
 */
function reindexVariants() {
    const blocks = variantBlocks();

    blocks.forEach((block, index) => {
        indexVariantBlock(block, index);
        paintVariantSpecification(block, index);
        paintVariantBlockMode(block);

        // A product needs at least one thing on the shelf under it — it cannot
        // otherwise be sold, priced or counted — so the only block keeps no
        // remove control. Nor does an edit, which shows one block by definition.
        $('[data-remove-variant]', block).classList.toggle('hidden', blocks.length < 2);
    });

    applyStockFieldsState();
    paintUnitSuffix();
}

/**
 * Add a block, optionally bound to a variant that already exists.
 *
 * The bound record is held **on the node**, because the node is the thing that
 * gets cloned in and removed — a map keyed by index beside it would be a second
 * place the binding is decided, and it would be wrong from the first removal.
 */
function addVariantBlock({ variant = null, focus = false } = {}) {
    const block = $('#item-variant-template', itemForm).content.firstElementChild.cloneNode(true);

    block.variantRecord = variant;
    block.dataset.variantId = variant ? String(variant.id) : '';

    // The recipe as the server last sent it. Absent rather than empty where the
    // installation has no recipes at all (§4.6), which is the same thing as
    // "this product consumes nothing" as far as this screen is concerned.
    block.recipeRows = (variant?.components ?? []).map(recipeRowFrom);

    if (variant) {
        const fill = (field, value) => {
            $(`[data-variant-field="${field}"]`, block).value = value ?? '';
        };

        fill('sku', variant.sku);
        fill('barcode', variant.barcode);
        fill('label', variant.label);
        fill('purchase_price', variant.purchase_price);
        fill('sell_price', variant.sell_price);
        fill('markup_percent', variant.markup_percent);
        fill('reorder_level', variant.reorder_level);
        fill('min_stock', variant.min_stock);
    }

    $('#item-variants', itemForm).append(block);
    reindexVariants();

    if (focus) $('[data-variant-field="sku"]', block).focus();
}

/**
 * Show one of the variant half's two panes.
 *
 * The blocks are **emptied and rebuilt** rather than hidden, on the way into
 * either pane. Whatever is in `#item-variants` is what gets submitted, and a
 * hidden block still holding the previous variant's SKU is the kind of thing
 * that is eventually saved onto this one.
 */
function showVariantPane(pane, { variant = null } = {}) {
    const form = itemForm;
    const item = state.formItem;
    const count = (item?.variants ?? []).length;

    $('#item-variants', form).innerHTML = '';
    $('#item-variant-list', form).classList.toggle('hidden', pane !== 'list');

    // The repeater's own control, and it belongs to the create: on an edit each
    // variant is one request against one endpoint, where a create submits them
    // together as `variants[]`.
    $('#item-add-variant', form).classList.toggle('hidden', editingItem());

    /*
    | Somewhere to go back to only where the list means something.
    |
    | Never on a create — there is nothing behind the repeater — and not while a
    | product's only variant is the one open, because the picker behind it would
    | hold that same row and nothing else. Adding a *second* to such a product
    | does get the control: the one that already exists is then worth going back
    | to.
    */
    const sole = count === 1 && variant !== null;

    $('#item-variant-back', form).classList.toggle('hidden', pane !== 'block' || !editingItem() || sole);

    if (pane === 'list') {
        renderVariantList(item);

        return;
    }

    addVariantBlock({ variant, focus: editingItem() && variant === null });
}

/**
 * The variants of this product, as a picker.
 *
 * The same facts the drawer's Variants tab shows, through the same row renderer
 * (§4.4) — what differs is the control on the right, which here opens the block
 * below rather than a surface of its own.
 */
function renderVariantList(item) {
    const variants = item?.variants ?? [];
    const host = $('#item-variant-list', itemForm);

    const add = can('WRITE', 'ITEMS')
        ? `<button type="button" class="btn btn-secondary btn-sm" data-add-variant>
               ${iconPlus} Add a variant
           </button>`
        : '';

    if (!variants.length) {
        host.innerHTML = `
            <div class="rounded-[12px] border border-dashed border-border px-4 py-6 text-center">
                <p class="text-[0.8125rem] text-muted-foreground">
                    Nothing on the shelf under this product yet — it cannot be sold, priced or counted
                    until there is.
                </p>
                <div class="mt-3">${add}</div>
            </div>`;

        return;
    }

    host.innerHTML = `
        ${variantRows(item, (variant) => `
            <button type="button" class="btn btn-ghost btn-icon" data-open-variant="${variant.id}"
                    title="Edit variant" aria-label="Edit ${esc(variant.display_label)}">${iconPencil}</button>`)}
        <div class="pt-1">${add}</div>`;
}

/**
 * Fill the item form in, and put it where it belongs.
 *
 * One form, two homes (§4.4): writing a new item is the module's level-1 landing
 * surface, and editing one is a dialog over the list you found it in. The fields
 * are identical, so they are declared once and the node is moved.
 *
 * @param {object|null} item     The product to edit, or null to write a new one.
 * @param {object} options
 * @param {object|null|undefined} options.variant
 *        Which variant's block to open on. `undefined` — the usual case — lets
 *        the count decide; an object opens that one, for the drawer's pencil;
 *        `null` opens a blank block, for "add a variant".
 */
async function openItemForm(item = null, { variant } = {}) {
    await loadMeta();

    const form = itemForm;
    const editing = item !== null;

    state.formItem = item;

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
    /*
    | The rate this form lands on: the product's own when editing, the markup's
    | prefill otherwise. `userSet` is cleared alongside it, so the category
    | chosen next still replaces an untouched prefill — see applyTypeToForm().
    */
    const gstField = $('#item-gst', form);

    gstField.value = editing ? item.gst_rate : gstField.defaultValue;
    delete gstField.dataset.userSet;

    $('#item-price-incl', form).checked = editing ? item.price_includes_tax === true : false;
    $('#item-type', form).value = editing
        ? String(item.category_id ?? '')
        : ($('#item-type', form).options[0]?.value ?? '');
    $('#item-uom', form).value = editing ? item.base_uom : '';
    $('#item-stock', form).checked = editing ? item.is_stock : true;
    $('#item-description', form).value = editing ? (item.description ?? '') : '';

    /*
    | Opening stock is the whole of what an edit withholds.
    |
    | It is a stock adjustment that posted on the day the shelf was counted, and
    | there is no second one to be had by retyping the figure here: correcting it
    | is a count, from the screen that counts. Everything else about a variant —
    | the specification, both prices, the reorder level, the floor, the barcode —
    | is an ordinary edit, and until now none of it could be reached from this
    | form at all.
    */
    $('#item-opening-date-field', form).classList.toggle('hidden', editing);

    if (!editing) {
        // Today, and set here rather than in the markup: the module's fragment
        // is fetched once and its root is then cached detached, so a value
        // rendered by Blade would still read the opening day of the session at
        // midnight. §2A.8 brings the form back through here after every save.
        $('#item-opening-date', form).value = new Date().toISOString().slice(0, 10);
    }

    paintVariantHeading();

    /*
    | Which pane. The count decides, unless the caller already knows.
    |
    | Every block is rebuilt from the template each time, on an edit as well as a
    | create: a half-typed third rating left attached to another product's edit
    | form is the kind of thing that is eventually submitted.
    */
    const variants = editing ? (item.variants ?? []) : [];

    if (!editing || variant !== undefined) showVariantPane('block', { variant: variant ?? null });
    else if (variants.length === 1) showVariantPane('block', { variant: variants[0] });
    else showVariantPane('list');

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

/**
 * What the variant half is called, which is a different question on each side.
 *
 * Three answers, because a product with one variant is not a small case of a
 * product with several: the family and the thing on the shelf are one record in
 * the user's head, and calling that half "Variants" is what taught people to go
 * looking for a second screen to correct a SKU on.
 */
function paintVariantHeading() {
    const form = itemForm;
    const count = (state.formItem?.variants ?? []).length;

    const [title, hint] = !editingItem()
        ? ['Variants', 'The actual things you buy and sell. One is normal; add a block per rating or size.']
        : count === 1
            ? ['What it is on the shelf', 'The thing this product actually is. Priced, counted and reordered here.']
            : count === 0
                ? ['Variants', 'Nothing is on the shelf under this product yet.']
                : ['Variants', 'Each is priced and counted on its own. Open one to correct it.'];

    $('#item-variants-title', form).textContent = title;
    $('#item-variants-hint', form).textContent = hint;
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
        // Whether the selling price above has the tax in it. A plain boolean and
        // never null: unticked is a real answer, and the only way to turn it back
        // off on an item that had it on.
        price_includes_tax: $('#item-price-incl', form).checked,
        is_stock: $('#item-stock', form).checked,
        description: value('#item-description'),
    };

    /*
    | The category and the unit are sent only on create.
    |
    | Both are fixed afterwards and the server ignores them on a PATCH — sending
    | them anyway would suggest they had been applied.
    */
    if (!id) {
        body.category_id = Number($('#item-type', form).value);
        body.base_uom = $('#item-uom', form).value;

        // One date for the whole submission: every quantity below posts on a
        // single stock adjustment, and a document has one date.
        body.opening_date = value('#item-opening-date');

        /*
        | Every block, in the order they are on screen.
        |
        | The longhand always, even for the single block this form opens on. The
        | server takes the flat one-variant shorthand too, but the index in
        | `variants.2.sku` is what a refusal comes back named after — and a form
        | that sent one shape sometimes and the other the rest of the time would
        | have to paint its errors two ways.
        */
        body.variants = variantBlocks().map((block) => variantPayload(block, { creating: true }));

        /*
        | Stock cannot arrive on the shelf worth nothing.
        |
        | Valued at zero it never reaches the Inventory account, and the first
        | sale of it reports the whole price as profit. The server refuses it —
        | `ItemService::openingCostFor()` — and this asks the same question
        | before the round trip. Never *instead* of it: the importer and the
        | capture agent reach that service without passing a form at all, which
        | is why the rule lives there and a copy of it lives here (§6.1).
        |
        | The first block that cannot be valued, because that is the one the
        | server stops at too. Only where the product is actually being stocked:
        | where it is not, the server saves the product and skips the quantities
        | with a warning rather than refusing — and the same is true for somebody
        | without the grant to write transactions, so refusing either here would
        | block a save the server would have accepted.
        */
        const unvalued = body.variants.findIndex((variant) => Number(variant.opening_stock ?? 0) > 0
            && !(Number(variant.opening_cost ?? variant.purchase_price ?? 0) > 0));

        if (body.is_stock && unvalued !== -1 && can('WRITE', 'TRANSACTIONS')) {
            showFormErrors(form, {
                fields: {
                    [`variants.${unvalued}.opening_cost`]: [
                        'Say what a unit cost. Stock cannot arrive worth nothing — the buying price above will do.',
                    ],
                },
                message: 'Opening stock has to arrive at a value.',
            });

            return;
        }
    }

    setSubmitting(form, true);

    try {
        if (id) {
            await saveItemEdit(id, body);

            return;
        }

        const saved = await auth.call('/items', { method: 'POST', body });

        /*
        | The server may have saved the product and declined the opening stock —
        | recording a quantity is a TRANSACTIONS grant and cataloguing is not —
        | and it may separately have noticed two blocks describing the same
        | thing. Surfaced rather than swallowed: somebody who typed "5" needs to
        | know the 5 was not recorded.
        |
        | All of them, not the first. One warning quietly replacing another is
        | how the second is never seen, and these two arrive together in exactly
        | the case where both matter.
        */
        const warnings = saved?.meta?.warnings ?? [];
        const created = saved?.data?.variants?.length ?? 1;

        if (warnings.length) warnings.forEach((warning) => toast(warning.message, 'warning'));
        else toast(created > 1 ? `Product created, with ${created} variants.` : 'Product created.');

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

/**
 * One block, under the keys the endpoint it is bound for names its fields by.
 *
 * Almost the same set either way, and deliberately so: `variants[]` on a create
 * is the longhand of the shape the variant endpoints take, which is what lets
 * one block serve both. Two keys differ, and neither is an accident. A create
 * calls the variant's own name **`variant_label`**, because `label` sitting
 * beside `name` in a product payload would read as the product's. And the
 * opening pair exists only on a create, because opening stock is a document that
 * posts once — an edit corrects it with a count, from the screen that counts.
 */
function variantPayload(block, { creating = false } = {}) {
    const field = (name) => variantValue(block, name);

    const payload = {
        sku: field('sku'),
        barcode: field('barcode'),
        attributes: collectAttributes($('[data-variant-attributes]', block)),
        sell_price: field('sell_price'),
        purchase_price: field('purchase_price'),
        markup_percent: field('markup_percent'),
        reorder_level: field('reorder_level'),
        min_stock: field('min_stock'),
    };

    if (creating) {
        return {
            ...payload,
            variant_label: field('label'),
            opening_stock: field('opening_stock'),
            opening_cost: field('opening_cost'),
        };
    }

    const edit = { ...payload, label: field('label') };

    /*
    | Sent only where the section was actually offered, and that is the whole of
    | the rule. `sync()` on the server *replaces* whatever it is given, so
    | sending `[]` for a product whose recipe this screen never showed — one that
    | holds stock of its own, or a server that has not run the schema step — would
    | silently clear something the operator was never looking at.
    */
    if (!$('[data-variant-recipe]', block)?.classList.contains('hidden')) {
        edit.components = recipeRowsOf(block).map((row) => ({
            component_variant_id: row.variant_id,
            quantity: row.quantity,
        }));
    }

    return edit;
}

/**
 * Save an edit: the product, then the variant open under it.
 *
 * **Two requests, deliberately.** `PATCH /items/{id}` and
 * `PATCH /items/{id}/variants/{vid}` are the endpoints the catalogue already
 * has, and a combined one would be a second write path into it — a second place
 * a SKU is checked for uniqueness, a second place an attribute bag is validated
 * (§4.4). They run one after the other under a single busy state, so the form
 * behaves as the one save it looks like.
 *
 * The product goes **first**, because the second call may be a POST: a product
 * that had no variants gets its first one from here, and a create that ran ahead
 * of a refusal above it would be created twice on the retry. Nothing after the
 * product call can be retried into a duplicate.
 *
 * Which leaves the one state this has to say out loud — the product saved and
 * the variant did not, which is what a SKU somebody else already used looks
 * like. The dialog stays open, the refusal paints on the box it is about, and a
 * toast says the first half went through: otherwise Cancel looks like it cancels
 * both.
 */
async function saveItemEdit(id, body) {
    const block = variantBlocks()[0];

    await auth.call(`/items/${id}`, { method: 'PATCH', body });

    if (block) {
        try {
            await saveVariantBlock(id, block);
        } catch (error) {
            toast('The product details were saved. The variant was not.', 'warning');

            throw underBlock(error);
        }
    }

    hideModal('#item-modal');
    toast('Product updated.');

    await refresh({ keepPage: true });

    // The drawer this was opened from is still behind the dialog, showing the
    // values that have just changed.
    if (state.openItem) renderDrawerBody();
}

/**
 * Re-key a variant refusal onto the block it is about — the client's half of
 * `ApiException::underField()`.
 *
 * The variant endpoints answer about a variant and know nothing about a product
 * form above them, so a 422 comes back naming `sku`. The block labels its boxes
 * `variants.0.sku`, because it is the same block the create form repeats and
 * there is one set of hooks indexed the one way. Left alone where there are no
 * fields: a conflict, or a network that did not answer, belongs on the banner,
 * and an edit has exactly one block on screen for it to be about.
 */
function underBlock(error) {
    if (error?.fields) {
        error.fields = Object.fromEntries(
            Object.entries(error.fields).map(([field, messages]) => [`variants.0.${field}`, messages]),
        );
    }

    return error;
}

/**
 * Write one block back to the variant it stands for, or create the first one.
 *
 * The PATCH sends every field a variant has bar its flags, and
 * `StoreVariantRequest::payload()` touches nothing the caller did not name — so
 * this is a full write of what the form shows rather than a diff, and clearing a
 * box really does clear the column.
 */
async function saveVariantBlock(itemId, block) {
    const variantId = block.dataset.variantId;
    const body = variantPayload(block);

    const response = await auth.call(
        variantId ? `/items/${itemId}/variants/${variantId}` : `/items/${itemId}/variants`,
        { method: variantId ? 'PATCH' : 'POST', body },
    );

    // Bound now, so a later failure cannot make a retry create a second one.
    if (!variantId && response?.data?.id) {
        block.dataset.variantId = String(response.data.id);
        block.variantRecord = response.data;
    }

    // A second variant at the same specification is saved and reported, not
    // refused: two brands at one rating is a real arrangement, but the far
    // commoner cause is the same thing entered twice.
    (response?.meta?.warnings ?? []).forEach((warning) => toast(warning.message, 'warning'));
}

/* -------------------------------------------------------------------------
 | Variants
 | ---------------------------------------------------------------------- */

/**
 * Correct what is on the shelf under one variant — the shared dialog, level 3
 * over the drawer.
 *
 * Everything about the act itself is `components/stock-adjust.js`, which is also
 * the Stock screen's "Record a count": this hands it the variant, what the books
 * currently say, and what to do afterwards. It types a count and the component
 * subtracts, which is why the position goes in as well as the id.
 *
 * There is deliberately no "edit quantity" here and nowhere else either. What
 * gets posted is a stock adjustment through the posting engine and the stock
 * ledger, exactly as it would be from Stock (§4.3).
 */
function openStockCount(variantId) {
    const item = state.openItem;
    if (!item) return;

    const variant = (item.variants ?? []).find((row) => String(row.id) === String(variantId));
    if (!variant) return;

    const position = positionFor(item.id, variant.id);

    openStockAdjust({
        mode: 'variant',
        variant: { id: variant.id, label: `${item.name} · ${variant.display_label}` },
        unit: item.base_uom_symbol ?? '',

        /*
        | No position row at all is a variant nothing has ever moved for, which
        | is a genuine zero rather than an unknown — the ledger has no other way
        | to say "none". `average_cost` is null in that case too, and the dialog
        | says so rather than letting found stock come on at nothing.
        */
        current: position?.quantity ?? 0,
        averageCost: position?.average_cost ?? null,

        /*
        | The whole module, not just this variant's row. The adjustment posts to
        | another module's endpoint, so the family's rolled-up position, the four
        | tiles above the table and the row's own status are all a count out of
        | date until the catalogue and the stock map are refetched together.
        */
        onPosted: async () => {
            await refresh({ keepPage: true });

            if (state.openItem) await openDrawer(state.openItem.id, { tab: 'variants' });
        },
    });
}

/* -------------------------------------------------------------------------
 | Opening stock, declared after the fact
 | ---------------------------------------------------------------------- */

/**
 * Has anything ever been on this variant's shelf?
 *
 * Absent and nought are the same answer here — the ledger has no other way to
 * say "none" — and either means the product was written without its opening
 * figure and nothing has moved since. That is the one state in which declaring
 * an opening balance is the right act rather than a count.
 */
function hasPosition(item, variant) {
    const position = positionFor(item.id, variant.id);

    return position !== null && Number(position.quantity) !== 0;
}

/**
 * Declare what was on the shelf at go-live for a variant that missed it.
 *
 * The gap this closes: `opening_stock` is on the create form and deliberately
 * withheld from the edit, so a product written without it had no way to acquire
 * one — and the obvious substitute, "Record a count", is the wrong document.
 * A count posts `Dr Inventory / Cr COGS` dated today, which credits cost of
 * goods sold and puts the whole value of the shelf into this period's gross
 * profit as though the workshop had earned it. An opening declaration posts
 * `Dr Inventory / Cr Opening Balance Equity` dated at go-live, which is what
 * stock the workshop already owned actually is.
 *
 * Nothing here is a second implementation of that. It builds one structured
 * `rows[]` entry and hands it to `POST /opening-balances` — the same endpoint,
 * the same service, the same resolution and the same duplicate guards as the
 * paste box on the Opening balances card (§4.3, §4.4). The row carries
 * `variant_id`, so the service skips its name matcher entirely: this screen
 * knows exactly which variant it is looking at, and a fuzzy match on "Bearing
 * 6204" against "Bearing 6204 ZZ" is the one way this path could land a
 * declaration on the wrong shelf.
 */
function openOpeningStock(variantId) {
    const item = state.openItem;
    if (!item) return;

    const variant = (item.variants ?? []).find((row) => String(row.id) === String(variantId));
    if (!variant) return;

    const form = $('#opening-stock-form');

    clearFormErrors(form);
    form.reset();

    form.elements.variant_id.value = variant.id;
    $('#opening-stock-subtitle', form).textContent = `${item.name} · ${variant.display_label}`;
    $('#opening-stock-unit', form).textContent = item.base_uom_symbol ?? '';
    $('#opening-stock-total', form).textContent = '';

    showModal('#opening-stock-modal');
    $('#opening-stock-quantity', form).focus();
}

/** The value of what is being declared, so the figure is read before it is posted. */
function paintOpeningTotal() {
    const form = $('#opening-stock-form');
    const quantity = Number($('#opening-stock-quantity', form).value);
    const cost = Number($('#opening-stock-cost', form).value);
    const host = $('#opening-stock-total', form);

    /*
    | `toFixed(2)` rather than the product itself: 12.3 * 4.1 is 50.42999...
    | in a float, and formatMoney truncates the fraction to two places rather
    | than rounding it — so the figure on screen would read a paisa under the
    | one the server posts. Indicative either way; the value that lands is
    | computed from the quantity and the rate server-side.
    */
    host.textContent = Number.isFinite(quantity) && Number.isFinite(cost) && quantity > 0 && cost > 0
        ? `Declares ${formatMoney((quantity * cost).toFixed(2))} of stock against the owner's stake.`
        : '';
}

/**
 * Check it, then post it — the module's own two verbs, in the order it uses them.
 *
 * The preview is not ceremony. A refusal here is a *row* refusal — a quantity
 * with more decimals than the unit allows, a category that holds no stock, an
 * opening figure already declared — and `POST /opening-balances` answers all of
 * those with `OPENING_PLAN_HAS_ERRORS`, which carries a count and a sentence
 * about fixing the file. There is no file. The preview resolves the identical
 * row through the identical code and hands back that row's own reason, which is
 * the only thing worth showing somebody with two boxes in front of them.
 */
async function submitOpeningStock(event) {
    event.preventDefault();

    const form = event.target;

    clearFormErrors(form);

    const body = {
        rows: [{
            kind: 'stock',
            // The id is what resolves the row; the name is what every message
            // about it quotes, so both go.
            variant_id: Number(form.elements.variant_id.value),
            name: state.openItem?.name ?? '',
            quantity: $('#opening-stock-quantity', form).value.trim(),
            unit_cost: $('#opening-stock-cost', form).value.trim(),
        }],
    };

    setSubmitting(form, true, 'Checking…');

    try {
        const { data } = await auth.call('/opening-balances/preview', { method: 'POST', body });
        const row = data?.[0];

        /*
        | Anything the resolution would not post is reported here and the dialog
        | stays open. `skipped` is its own outcome and not an error: it is what
        | an opening figure that has already been declared looks like, and the
        | server's own sentence says so better than a guess would.
        */
        if (!row || row.outcome !== 'ready') {
            showFormMessage(form, row?.reason
                ?? 'That cannot be declared as it stands, and nothing has been posted.');

            return;
        }

        await auth.call('/opening-balances', {
            method: 'POST',
            body: { ...body, filename: `Opening stock — ${row.resolved}` },
        });

        hideModal('#opening-stock-modal');
        toast('Opening stock declared.');

        // The catalogue and the stock map together: the family's roll-up, the
        // tiles above the table and this row's own status are all a declaration
        // out of date until both come back.
        await refresh({ keepPage: true });

        if (state.openItem) renderDrawerBody();
    } catch (error) {
        showFormErrors(form, error);
    } finally {
        setSubmitting(form, false);
    }
}

/**
 * Take a variant off the shelf, or put it back.
 *
 * A status change rather than an edit, so it happens where the row is rather
 * than inside the form that corrects it: everything already recorded against the
 * variant stays exactly as it is, and only what a picker offers changes. The
 * same treatment the family above it gets from the row menu (§7.4).
 */
async function setVariantActive(variantId, isActive) {
    const item = state.openItem;
    if (!item) return;

    const confirmed = isActive || await confirmAction({
        title: 'Archive this variant',
        body: 'It stops being offered when somebody picks an item, and everything already recorded '
            + 'against it — every bill, every movement — stays exactly as it is. '
            + 'You can restore it at any time.',
        confirmLabel: 'Archive variant',
    });

    if (!confirmed) return;

    try {
        await auth.call(`/items/${item.id}/variants/${variantId}`, {
            method: 'PATCH',
            body: { is_active: isActive },
        });

        toast(isActive ? 'Variant restored.' : 'Variant archived.');

        await refresh({ keepPage: true });
        await openDrawer(item.id, { tab: 'variants' });
    } catch (error) {
        toast(error.message, 'error');
    }
}

/**
 * Sign off one rating that was created for the workshop rather than by it.
 *
 * `is_draft` is set by M11's importer and M15's capture agent and means nobody
 * has looked at this yet. Clearing it is somebody saying they have — so there is
 * **no confirmation** (§3.5 asks for one where something is taken away, and this
 * takes nothing away) and no way to set the flag back on from here. Putting a
 * rating back into the queue is not a thing anybody wants; correcting it is, and
 * that is the pencil beside this control.
 *
 * One variant at a time, deliberately. A "confirm all" over a family is one
 * click that says a whole import was checked, which is the one claim this flag
 * exists to stop somebody making by accident.
 */
async function setVariantDraft(variantId) {
    const item = state.openItem;
    if (!item) return;

    try {
        await auth.call(`/items/${item.id}/variants/${variantId}`, {
            method: 'PATCH',
            body: { is_draft: false },
        });

        toast('Marked as checked.');

        // The whole module: the queue's count is served by `/items/meta`, the
        // badge on the row behind the drawer is drawn from the family's variants,
        // and both are a review out of date until the catalogue comes back.
        await refresh({ keepPage: true });
        await openDrawer(item.id, { tab: 'variants' });
    } catch (error) {
        toast(error.message, 'error');
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

/**
 * The same sign-off for the family itself.
 *
 * Shipped with the variant control rather than after it, because the banner
 * counts both and a queue that can only be emptied of half its work never
 * reaches zero — which is the state that makes people stop opening it.
 */
async function setItemDraft(id) {
    // Whether the drawer is actually on screen, not whether `state.openItem` is
    // set: that is never cleared on close, so it still names the last product
    // looked at — and asking it instead would re-open the drawer over the list
    // for anybody who signed a row off after viewing it.
    const inDrawer = !$('#item-drawer').classList.contains('hidden')
        && String(state.openItem?.id) === String(id);

    try {
        await auth.call(`/items/${id}`, { method: 'PATCH', body: { is_draft: false } });
        toast('Marked as checked.');
        await refresh({ keepPage: true });

        // The alert above the tabs is painted when the drawer opens, and leaving
        // it reading "not yet checked" straight after somebody checked it is
        // worse than not offering the control there at all.
        if (inDrawer) await openDrawer(id, { tab: state.drawerTab });
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
    initStockAdjust();

    /*
    | Declaring opening stock for a variant that missed it — bound once here
    | rather than per open, because the dialog lives in the module's fragment and
    | the fragment is mounted once (`shell.js` caches its root detached).
    */
    const openingForm = $('#opening-stock-form');

    openingForm.addEventListener('submit', submitOpeningStock);
    openingForm.addEventListener('input', paintOpeningTotal);

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

    // Typing in the rate box settles it: from here on this product charges what
    // the user said, whatever category they land on next (applyTypeToForm()).
    $('#item-gst', itemForm)?.addEventListener('input', (event) => {
        event.target.dataset.userSet = '1';
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
        const count = event.target.closest('[data-set-stock]');
        const opening = event.target.closest('[data-opening-stock]');
        const checked = event.target.closest('[data-clear-variant-draft]');
        const archive = event.target.closest('[data-toggle-variant]');
        const remove = event.target.closest('[data-delete-variant]');
        const archived = event.target.closest('[data-toggle-archived]');

        // A preference, not a write: repainted here rather than refetched,
        // because every row it shows or hides is already loaded.
        if (archived) {
            state.showArchivedVariants = !state.showArchivedVariants;
            renderDrawerBody();

            return;
        }

        if (edit) {
            const variant = (state.openItem?.variants ?? [])
                .find((row) => String(row.id) === edit.dataset.editVariant);

            // The item form, opened on that variant — level 3 over the drawer,
            // which is where a single record's fields belong. There is no second
            // editor to open any more; see drawerVariants().
            if (variant) openItemForm(state.openItem, { variant });
        }

        if (count) openStockCount(count.dataset.setStock);

        if (opening) openOpeningStock(opening.dataset.openingStock);

        if (checked) setVariantDraft(checked.dataset.clearVariantDraft);

        if (archive) {
            setVariantActive(archive.dataset.toggleVariant, archive.dataset.variantActive !== 'true');
        }

        if (remove) deleteVariant(remove.dataset.deleteVariant);
    });

    $('#drawer-edit').addEventListener('click', () => {
        if (state.openItem) openItemForm(state.openItem);
    });

    $('#drawer-clear-draft').addEventListener('click', () => {
        if (state.openItem) setItemDraft(state.openItem.id);
    });

    // `variant: null` rather than nothing at all: a blank block, where leaving it
    // out would let the count decide and land on the variant that already exists.
    $('#drawer-add-variant').addEventListener('click', () => {
        if (state.openItem) openItemForm(state.openItem, { variant: null });
    });

    /* Forms ------------------------------------------------------------ */

    itemForm.addEventListener('submit', (event) => {
        event.preventDefault();
        submitItem();
    });

    $('#item-type', itemForm).addEventListener('change', () => applyTypeToForm({ editing: false }));

    // Every block's stock boxes follow the family's own choice. Bound here
    // rather than only repainted when the category changes, which is what left
    // them live after somebody unticked "Keep stock of this".
    $('#item-stock', itemForm).addEventListener('change', applyStockFieldsState);

    $('#item-add-variant', itemForm).addEventListener('click', () => addVariantBlock({ focus: true }));

    /*
    | Delegated, because the blocks are cloned in and out.
    |
    | Refused below one block here as well as hidden there: a product with
    | nothing on the shelf under it cannot be sold, priced or counted, and the
    | hidden control is the presentation of that rule rather than the rule.
    */
    $('#item-variants', itemForm).addEventListener('click', (event) => {
        const remove = event.target.closest('[data-remove-variant]');

        if (!remove || variantBlocks().length < 2) return;

        remove.closest('[data-variant-block]').remove();
        reindexVariants();
    });

    /*
    | "Clear" rebuilds the form rather than resetting its fields.
    |
    | A native reset empties the boxes and leaves behind however many variant
    | blocks were on screen, each still holding the attribute inputs of whatever
    | category was chosen — and it would not put today's date back either.
    */
    $('#item-form-clear', itemForm).addEventListener('click', () => openItemForm());

    /*
    | The picker, and the two ways out of it. Delegated: its rows are drawn per
    | product, and scoped to the form because the form is detached from the
    | document whenever the workspace is showing its list (§2A.2).
    */
    $('#item-variant-list', itemForm).addEventListener('click', (event) => {
        const open = event.target.closest('[data-open-variant]');
        const add = event.target.closest('[data-add-variant]');
        const archived = event.target.closest('[data-toggle-archived]');

        if (archived) {
            state.showArchivedVariants = !state.showArchivedVariants;
            renderVariantList(state.formItem);

            return;
        }

        if (open) {
            const variant = (state.formItem?.variants ?? [])
                .find((row) => String(row.id) === open.dataset.openVariant);

            if (variant) showVariantPane('block', { variant });
        }

        if (add) showVariantPane('block', { variant: null });
    });

    /*
    | Back to the picker, discarding whatever was typed into the block.
    |
    | No confirmation, and that is the judgement rather than an omission: the
    | product half above is untouched, and what is lost is one variant's
    | half-typed correction, which is on screen the whole time. A dialog here
    | would be asked on every glance at another variant.
    */
    $('#item-variant-back', itemForm).addEventListener('click', () => showVariantPane('list'));

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

    /*
    | Held before the mount, like the two surfaces above. A read-only caller
    | lands on the list, which detaches the form — and `itemForm.closest()` then
    | walks up a subtree that no longer reaches the module root.
    */
    const moduleRoot = itemForm.closest('[data-module-root]');

    workspace = mountWorkspace(moduleRoot, {
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

        // The catalogue *and* the position beside each row (M7 joined to M8),
        // so this list goes behind on a sale as surely as on an edit here.
        refreshOn: ['items', 'stock'],

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

    await applyDeepLink(moduleParams());

    // Reopening an already-mounted module cannot run this function again, so a
    // second deep link is announced on the root instead.
    moduleRoot.addEventListener('module:params', (event) => applyDeepLink(event.detail));
}

/**
 * `#items?item=12` — an item picked out of the topbar's search.
 *
 * Unlike a bill's drawer, this one reads its row out of the loaded catalogue
 * rather than refetching it, so a link that arrives before the list has ever
 * been asked for has to bring it up first (§2A.7 fetches it there). That is also
 * the right surface to land on: closing the drawer leaves somebody on the row
 * they came for rather than on a blank create form.
 *
 * Spent once acted on, or a refresh or a Back would reopen a drawer somebody has
 * just closed.
 */
async function applyDeepLink(params) {
    const id = params.get('item');

    if (!id) return;

    clearModuleParams();

    await workspace?.showList();

    // Archived items are in the catalogue this holds — `is_active` is
    // deliberately not sent — so a miss here means the item has been deleted
    // since the results were painted. Said rather than swallowed (§3.4).
    if (!state.items.some((row) => String(row.id) === String(id))) {
        toast('That item is no longer in the catalogue.', 'error');

        return;
    }

    await openDrawer(id);
}

/** The row menu's entries, in one place so the menu markup stays declarative. */
function runAction(action, id) {
    const item = state.items.find((row) => String(row.id) === String(id));
    if (!item) return;

    if (action === 'open') openDrawer(id);
    if (action === 'variants') openDrawer(id, { tab: 'variants' });
    if (action === 'edit') openItemForm(item);
    if (action === 'checked') setItemDraft(id);
    if (action === 'archive') setActive(id, false);
    if (action === 'restore') setActive(id, true);
    if (action === 'delete') destroy(id);
}
