/**
 * Find something to put on the bill by typing — the brief's §5 and §9.
 *
 * ## Why this searches stock rather than the catalogue
 *
 * Because the question at a counter is never "does this workshop deal in
 * bearings", it is "is there a 6205 on the shelf and what does it cost". So the
 * primary source is `GET /stock?search=`, whose every row already carries the
 * live quantity, the unit, the average cost, the list price and whether the
 * position is low or negative — one request, and the badge on the result is a
 * fact rather than a guess.
 *
 * Services are the exception and are fetched alongside from `/items?type=service`.
 * An hour of rewinding has no shelf and no position; it would never appear in a
 * stock report, and a picker that could not offer labour would be useless in a
 * rewinding shop. The two lists are merged with stocked goods first, because
 * that is the order somebody scanning results expects.
 *
 * ## What the badge is for
 *
 * §9's IN STOCK / LOW / OUT, shown at the moment of choosing rather than after
 * the bill is refused. It does not *prevent* anything — a workshop legitimately
 * bills a part it is about to buy in, and M17 decides at posting whether that is
 * allowed. It is there so the decision is made knowingly.
 */

import auth from '../auth-client';
import { can } from '../permissions';
import { $, debounce, esc, formatMoney, toast } from '../ui';
import { formatQuantity } from './badge';

/** The three states §9 names, and the classes that say so at a glance. */
function stockBadge(row) {
    if (row.kind === 'needs-variant') {
        return '<span class="badge bg-sky-100 text-sky-800">ADD A VARIANT</span>';
    }

    if (row.kind === 'service') {
        return '<span class="badge bg-purple-50 text-purple-700">SERVICE</span>';
    }

    if (row.is_negative) return '<span class="badge bg-rose-100 text-rose-700">NEGATIVE</span>';
    if (!row.has_stock) return '<span class="badge bg-rose-100 text-rose-700">OUT</span>';
    if (row.is_low) return '<span class="badge bg-amber-100 text-amber-800">LOW</span>';

    return '<span class="badge bg-emerald-100 text-emerald-800">IN STOCK</span>';
}

/**
 * Flatten a stock row and an item into the one shape the picker and the bill
 * line both speak.
 */
function fromStock(row) {
    return {
        kind: 'stock',
        key: `v:${row.variant_id}`,
        item_id: row.item_id,
        variant_id: row.variant_id,
        label: row.item?.name === row.display_label
            ? row.display_label
            : `${row.item?.name ?? ''} · ${row.display_label}`,
        sku: row.sku,
        unit: row.item?.base_uom ?? null,
        unit_symbol: row.item?.base_uom_symbol ?? '',
        gst_rate: row.item?.gst_rate ?? '0',
        price: row.sell_price ?? '',
        quantity: row.quantity,
        average_cost: row.average_cost,
        has_stock: row.has_stock,
        is_low: row.is_low,
        is_negative: row.is_negative,
    };
}

function fromServiceVariant(item, variant) {
    return {
        kind: 'service',
        key: variant ? `v:${variant.id}` : `i:${item.id}`,
        item_id: item.id,
        variant_id: variant?.id ?? null,
        label: variant && variant.display_label !== item.name
            ? `${item.name} · ${variant.display_label}`
            : item.name,
        sku: variant?.sku ?? null,
        unit: item.base_uom,
        unit_symbol: item.base_uom_symbol ?? '',
        gst_rate: item.gst_rate ?? '0',
        price: variant?.sell_price ?? '',
        quantity: null,
        average_cost: null,
        has_stock: true,
        is_low: false,
        is_negative: false,
    };
}

/**
 * Search both sources for one term.
 *
 * `allSettled`, so a user without READ:STOCK still gets the services and a user
 * without READ:ITEMS still gets the goods. A picker that returned nothing
 * because one of two requests was refused would look broken rather than
 * restricted.
 */
export async function searchCatalogue(term) {
    const query = encodeURIComponent(term);

    const [stock, services, bare] = await Promise.allSettled([
        can('READ', 'STOCK')
            ? auth.call(`/stock?per_page=12&is_active=1&search=${query}`)
            : Promise.resolve({ data: [] }),
        /*
        | `is_stock=0`, not `type=service`.
        |
        | The `type` filter stopped existing when the ItemType enum was deleted
        | and the catalogue's vocabulary became data. An unknown query parameter
        | is *ignored*, not refused — so this request had quietly been returning
        | the entire catalogue, every stocked family included, and each one was
        | then offered as a family-level line the posting engine would refuse
        | ("say which variant, because stock is counted per variant"). The bill
        | was written before anybody found out.
        |
        | `is_stock` is a real filter on IndexItemRequest, and it is the exact
        | complement of what /stock returns.
        */
        can('READ', 'ITEMS')
            ? auth.call(`/items?per_page=12&is_stock=0&is_active=1&with_variants=1&search=${query}`)
            : Promise.resolve({ data: [] }),
        /*
        | A stocked family with nothing under it yet, and the only query that
        | can find one.
        |
        | It has no variant, so it has no stock row and the first request cannot
        | see it; it *is* stocked, so `is_stock=0` excludes it from the second.
        | Between them the item was invisible — "motor 3" returned the same bare
        | "Nothing matched" as a name nobody had ever entered, which is the one
        | answer that sends somebody off to create a duplicate.
        |
        | Small on purpose: this is an exception list, not a way to browse.
        */
        can('READ', 'ITEMS')
            ? auth.call(`/items?per_page=5&is_stock=1&has_variants=0&is_active=1&search=${query}`)
            : Promise.resolve({ data: [] }),
    ]);

    const rows = stock.status === 'fulfilled'
        ? stock.value.data.map(fromStock)
        : [];

    if (services.status === 'fulfilled') {
        services.value.data.forEach((item) => {
            const variants = (item.variants ?? []).filter((variant) => variant.is_active);

            if (variants.length > 0) {
                variants.forEach((variant) => rows.push(fromServiceVariant(item, variant)));

                return;
            }

            /*
            | A family with nothing under it. Whether it can go on a bill as
            | itself depends on one thing, and it is the server's answer rather
            | than ours: labour has no specification and is billed as the family,
            | while anything held in stock is counted per variant and would be
            | refused.
            |
            | Guarded here as well as by the filter above, because "never offer a
            | line that cannot post" is worth being structurally true rather than
            | true by virtue of a query parameter being spelled correctly.
            */
            rows.push(item.tracks_stock ? needsVariant(item) : fromServiceVariant(item, null));
        });
    }

    // Last, because they cannot be put on a bill as they stand — whoever is
    // scanning results should reach what is buyable first.
    if (bare.status === 'fulfilled') {
        bare.value.data.forEach((item) => rows.push(needsVariant(item)));
    }

    return rows;
}

/**
 * A stocked family with no specification yet — offered, but not as a line.
 *
 * Dropping it would be worse than the bug this replaced: somebody who typed
 * "motor 3" and saw nothing would conclude the catalogue had lost it. Saying
 * what is missing, and opening the form that fixes it, is the honest answer.
 */
function needsVariant(item) {
    return {
        kind: 'needs-variant',
        key: `i:${item.id}`,
        item,
        item_id: item.id,
        variant_id: null,
        label: item.name,
        sku: null,
        unit: item.base_uom,
        unit_symbol: item.base_uom_symbol ?? '',
        gst_rate: item.gst_rate ?? '0',
        price: '',
        quantity: null,
        average_cost: null,
        has_stock: false,
        is_low: false,
        is_negative: false,
    };
}

/**
 * Mount the search box.
 *
 * @param {HTMLElement} host
 * @param {object} options
 * @param {(choice: object) => void} options.onPick   Called with a flattened row.
 * @param {() => void} [options.onCreate]             "+ Create a new item".
 * @param {string} [options.hint]                     The line under the box.
 */
export function mountItemPicker(host, {
    onPick = () => {},
    onCreate = null,
    onNeedsVariant = null,
    /*
    | Said by the host, because it is not true in both directions.
    |
    | A sale line *is* prefilled from the shelf's selling price. A purchase line
    | is deliberately not — stock arrives at the rate on the supplier's invoice
    | and that arrival is what sets the weighted average, so a guess seeded here
    | would become the cost basis permanently (docs/purchase-module.md). This
    | line had been promising "price comes from the shelf" on both, which read as
    | a broken prefill on every purchase somebody wrote.
    */
    hint = 'Enter adds the highlighted row. Stock, unit and price come from the shelf.',
} = {}) {
    // A thunk where the host can change direction under a mounted picker.
    const say = (value) => (typeof value === 'function' ? value() : value);

    host.innerHTML = `
        <div class="relative" data-item-picker>
            <label class="field-label" for="item-search">Add an item or a service</label>

            <input id="item-search" type="text" class="field-input" autocomplete="off"
                   role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="item-results"
                   placeholder="Start typing — bearing, winding wire, labour…" data-item-input>

            <p class="mt-1.5 text-xs text-muted-foreground" data-item-hint>${esc(say(hint))}</p>

            <ul id="item-results" role="listbox"
                class="surface absolute z-30 mt-1 hidden max-h-80 w-full overflow-y-auto p-1 shadow-raised"
                data-item-results></ul>
        </div>`;

    const input = $('[data-item-input]', host);
    const results = $('[data-item-results]', host);

    const state = { rows: [], active: -1, open: false };

    const close = () => {
        state.open = false;
        results.classList.add('hidden');
        input.setAttribute('aria-expanded', 'false');
    };

    const createRow = onCreate && can('WRITE', 'ITEMS')
        ? `<li role="option" aria-selected="false" data-create
               class="cursor-pointer rounded-md border-t border-border px-3 py-2 text-sm font-medium text-primary">
               ＋ Create a new item…
           </li>`
        : '';

    const paint = () => {
        results.innerHTML = (state.rows.length
            ? state.rows.map((row, index) => `
                <li role="option" aria-selected="${index === state.active}" data-index="${index}"
                    class="flex cursor-pointer items-center gap-3 rounded-md px-3 py-2 text-sm
                           ${index === state.active ? 'bg-accent' : ''}">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium text-foreground">${esc(row.label)}</span>
                        <span class="block text-xs text-muted-foreground">
                            ${row.kind === 'needs-variant'
                                // Why it cannot go on the bill, said on the row
                                // rather than left for the click to reveal.
                                ? 'In the catalogue, but nothing specified yet — add one to buy or sell it.'
                                : `${row.sku ? `${esc(row.sku)} · ` : ''}${esc(row.gst_rate)}% GST${
                                    row.quantity === null
                                        ? ''
                                        : ` · ${esc(formatQuantity(row.quantity, row.unit_symbol))} on hand`
                                }`}
                        </span>
                    </span>

                    <span class="shrink-0 text-right">
                        <span class="block font-mono text-[0.8125rem] ${row.price === '' ? 'text-muted-foreground' : ''}">
                            ${row.kind === 'needs-variant'
                                ? ''
                                : (row.price === '' ? 'no price' : esc(formatMoney(row.price)))}
                        </span>
                        ${stockBadge(row)}
                    </span>
                </li>`).join('')
            : `<li class="px-3 py-3 text-sm text-muted-foreground">Nothing matched.</li>`) + createRow;

        state.open = true;
        results.classList.remove('hidden');
        input.setAttribute('aria-expanded', 'true');
    };

    const run = debounce(async (term) => {
        if (term.trim().length === 0) {
            close();

            return;
        }

        try {
            state.rows = await searchCatalogue(term);
            state.active = state.rows.length ? 0 : -1;
            paint();
        } catch (error) {
            toast(error.message, 'error');
            close();
        }
    }, 220);

    const pick = (row) => {
        /*
        | A family that is held in stock cannot be a line until something under
        | it exists, so choosing one opens the form that adds a specification
        | instead of putting an unpostable row on the bill. The search term is
        | kept in that case — whoever comes back from the dialog is still
        | looking for the same thing.
        */
        if (row.kind === 'needs-variant') {
            close();

            if (onNeedsVariant) onNeedsVariant(row.item);
            else toast(`${row.label} has no specification yet. Add one on Items first.`, 'info');

            return;
        }

        onPick(row);
        // Cleared and left focused, because the next thing somebody does at a
        // counter is add another line. Keeping the term would mean deleting it
        // before every one.
        input.value = '';
        close();
        input.focus();
    };

    input.addEventListener('input', (event) => run(event.target.value));

    input.addEventListener('keydown', (event) => {
        if (!state.open) {
            if (event.key === 'ArrowDown' && input.value.trim()) run(input.value);

            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            state.active = (state.active + step + state.rows.length) % Math.max(state.rows.length, 1);
            paint();

            return;
        }

        if (event.key === 'Enter') {
            event.preventDefault();

            if (state.rows[state.active]) pick(state.rows[state.active]);

            return;
        }

        if (event.key === 'Escape') {
            event.stopPropagation();
            close();
        }
    });

    results.addEventListener('mousedown', (event) => {
        event.preventDefault();

        if (event.target.closest('[data-create]')) {
            close();
            onCreate?.();

            return;
        }

        const row = event.target.closest('[data-index]');

        if (row) pick(state.rows[Number(row.dataset.index)]);
    });

    input.addEventListener('blur', () => setTimeout(close, 120));

    return {
        focus: () => input.focus(),
        /** Re-run the current search — used after the catalogue has been added to. */
        refresh: () => input.value.trim() && run(input.value),
        /** Re-read the hint, for a host that has just changed direction. */
        repaintHint: () => {
            $('[data-item-hint]', host).textContent = say(hint);
        },
    };
}
