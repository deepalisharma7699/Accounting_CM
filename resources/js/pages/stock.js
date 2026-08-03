import auth from '../auth-client';
import {
    $, $$, clearFormErrors, debounce, esc, formatDate, formatMoney,
    hideModal, setSubmitting, showFormErrors, showModal, tableMessage, toast,
} from '../ui';

/**
 * What is on the shelf.
 *
 * Three things drive the design of this page.
 *
 * **There is no way to edit a quantity.** The only control that changes stock is
 * "Record a count", and it posts a transaction like any other. A field that
 * wrote a position directly would be a second write path, and everything M8
 * guarantees rests on there not being one.
 *
 * **Negative is not a kind of low.** A low position is a purchasing decision; a
 * negative one means a sale was recorded before the purchase that supplied it,
 * which is a data problem with a different fix. They get separate tiles, separate
 * filters and separate colours, because a screen that showed them the same way
 * would train people to ignore the second.
 *
 * **The reconciliation banner appears only when it has something to say.** Stock
 * value and the Inventory account are written in the same database transaction
 * from the same figure, so they agree for everything that goes through a posting
 * template. A permanent "they agree" banner would be noise; a banner that only
 * ever appears when they do not is the alarm it is meant to be.
 */

const PAGE_SIZE = 50;

const state = {
    search: '',
    type: '',
    status: '',
    sort: 'name',
    includeArchived: false,
    page: 1,
    hasMore: false,
    variants: [],   // the stocked catalogue, for the adjustment form's picker
};

/* -------------------------------------------------------------------------
 | The list
 | ---------------------------------------------------------------------- */

function query() {
    const params = new URLSearchParams();

    if (state.search) params.set('search', state.search);
    if (state.type) params.set('type', state.type);
    if (state.status) params.set('status', state.status);
    if (state.sort) params.set('sort', state.sort);
    if (state.includeArchived) params.set('is_active', '0');

    params.set('per_page', PAGE_SIZE);
    params.set('page', state.page);

    return params;
}

async function load() {
    $('#stock-body').innerHTML = tableMessage(6, 'Counting…');

    try {
        const payload = await auth.call(`/stock?${query()}`);

        render(payload.data, payload.meta);
        state.variants = payload.data;
    } catch (error) {
        // A platform super-admin holds every grant and owns no books. Their
        // request is well formed; there is simply nothing to show them.
        $('#stock-body').innerHTML = error.code === 'NO_WORKSPACE'
            ? tableMessage(6, 'Your account administers the platform rather than a single workshop, so it has no stock of its own.')
            : tableMessage(6, error.message, 'error');
    }
}

function render(rows, meta) {
    const body = $('#stock-body');
    const totals = meta?.totals ?? {};

    $('#stat-value').textContent = formatMoney(totals.value ?? '0.00');
    $('#stat-variants').textContent = totals.variants ?? 0;
    $('#stat-low').textContent = totals.low ?? 0;
    $('#stat-negative').textContent = totals.negative ?? 0;

    if (!rows.length) {
        body.innerHTML = tableMessage(6, state.status || state.search
            ? 'Nothing matches those filters.'
            : 'Nothing is stocked yet. Stock arrives when you record a purchase or a count.');
    } else {
        body.innerHTML = rows.map(renderRow).join('');
    }

    const pagination = meta?.pagination ?? {};

    state.hasMore = Boolean(pagination.has_more);

    $('#stock-summary').textContent = rows.length
        ? `${pagination.total ?? rows.length} variant${(pagination.total ?? rows.length) === 1 ? '' : 's'}, worth ${formatMoney(totals.value ?? '0.00')} in total.`
        : '';

    $('#page-prev').disabled = (pagination.current_page ?? 1) <= 1;
    $('#page-next').disabled = !state.hasMore;
}

function renderRow(row) {
    const unit = row.item?.base_uom_symbol ?? '';

    return `
        <tr class="border-t border-border cursor-pointer transition hover:bg-secondary/60"
            data-variant="${row.variant_id}" tabindex="0" role="link"
            aria-label="Open the stock card for ${esc(row.display_label)}">
            <td class="table-cell">
                <span class="font-medium">${esc(row.display_label)}</span>
                ${row.sku ? `<span class="ml-2 font-mono text-xs text-muted-foreground">${esc(row.sku)}</span>` : ''}
                ${row.is_active ? '' : '<span class="ml-2 text-xs text-muted-foreground">archived</span>'}
            </td>
            <td class="table-cell text-[0.8125rem] text-muted-foreground">
                ${esc(row.item?.name ?? '')}
                <div class="text-xs">${esc(row.item?.type_label ?? '')}</div>
            </td>
            <td class="table-cell w-32 text-right font-mono text-[0.8125rem] ${row.is_negative ? 'font-semibold text-rose-700' : ''}">
                ${esc(trimQuantity(row.quantity))} <span class="text-xs text-muted-foreground">${esc(unit)}</span>
            </td>
            <td class="table-cell w-36 text-right font-mono text-[0.8125rem]">
                ${row.has_stock ? esc(formatMoney(row.average_cost)) : '—'}
            </td>
            <td class="table-cell w-36 text-right font-mono text-[0.8125rem] font-semibold">
                ${esc(formatMoney(row.value))}
            </td>
            <td class="table-cell w-48">${statusBadge(row)}</td>
        </tr>`;
}

/**
 * Quantities read the way the trade says them: "3" for three bearings, "2.5" for
 * two and a half kilograms. The API sends three decimals because the column has
 * three; printing them all would make a shelf of whole motors look measured.
 */
function trimQuantity(value) {
    const amount = String(value ?? '0');

    return amount.includes('.') ? amount.replace(/\.?0+$/, '') : amount;
}

function statusBadge(row) {
    if (row.is_negative) {
        return '<span class="badge bg-rose-100 text-rose-800">More issued than received</span>';
    }

    if (row.is_low) {
        return `<span class="badge bg-amber-100 text-amber-800">At or below ${esc(trimQuantity(row.reorder_level))}</span>`;
    }

    if (!row.has_stock) {
        return '<span class="badge bg-muted text-muted-foreground">Out of stock</span>';
    }

    return '<span class="badge bg-emerald-100 text-emerald-800">In stock</span>';
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
                <span class="font-semibold">Stock and the Inventory account disagree by ${esc(formatMoney(data.difference))}.</span>
                The shelf is worth ${esc(formatMoney(data.value))} and the account stands at
                ${esc(formatMoney(data.inventory_account.balance))}. Every purchase, sale and count writes both
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

async function openCard(variantId) {
    const row = state.variants.find((item) => String(item.variant_id) === String(variantId));

    $('#stock-card-title').textContent = row?.display_label ?? 'Stock card';
    $('#stock-card-subtitle').textContent = row?.item?.name ?? '';
    $('#stock-card-body').innerHTML = tableMessage(6, 'Loading the movements…');

    showModal('#stock-card-modal');

    try {
        const { data } = await auth.call(`/stock/variants/${variantId}?per_page=100`);

        const unit = row?.item?.base_uom_symbol ?? '';

        const opening = `
            <tr class="border-t border-border bg-secondary/20 text-[0.8125rem] italic text-muted-foreground">
                <td class="table-cell" colspan="5">Brought forward</td>
                <td class="table-cell text-right font-mono not-italic">${esc(trimQuantity(data.opening.quantity))} ${esc(unit)}</td>
            </tr>`;

        const rows = data.movements.map((movement) => `
            <tr class="border-t border-border">
                <td class="table-cell w-32 whitespace-nowrap text-[0.8125rem]">${esc(formatDate(movement.date))}</td>
                <td class="table-cell">
                    <span class="font-medium">${esc(movement.type_label)}</span>
                    <div class="mt-0.5 text-[0.8125rem] text-muted-foreground">
                        ${movement.transaction ? `#${movement.transaction.id} · ${esc(movement.transaction.type_label)}` : ''}
                        ${movement.memo ? ` · ${esc(movement.memo)}` : ''}
                    </div>
                </td>
                <td class="table-cell w-28 text-right font-mono text-[0.8125rem] ${movement.quantity.startsWith('-') ? 'text-rose-700' : 'text-emerald-700'}">
                    ${esc(trimQuantity(movement.quantity))}
                </td>
                <td class="table-cell w-28 text-right font-mono text-[0.8125rem]">${esc(formatMoney(movement.unit_cost))}</td>
                <td class="table-cell w-32 text-right font-mono text-[0.8125rem]">${esc(formatMoney(movement.value))}</td>
                <td class="table-cell w-32 text-right font-mono text-[0.8125rem] font-semibold">
                    ${esc(trimQuantity(movement.balance_quantity))} ${esc(unit)}
                </td>
            </tr>`).join('');

        $('#stock-card-body').innerHTML = data.movements.length
            ? opening + rows
            : tableMessage(6, 'Nothing has moved through this variant yet.');
    } catch (error) {
        $('#stock-card-body').innerHTML = tableMessage(6, error.message, 'error');
    }
}

/* -------------------------------------------------------------------------
 | Recording a count
 | ---------------------------------------------------------------------- */

let lineSeq = 0;

function adjustmentLine() {
    const id = ++lineSeq;

    const options = state.variants
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
 | Boot
 | ---------------------------------------------------------------------- */

export default async function initStock() {
    await load();
    await loadReconciliation();

    $('#filter-search').addEventListener('input', debounce((event) => {
        state.search = event.target.value.trim();
        state.page = 1;
        load();
    }));

    ['type', 'status', 'sort'].forEach((field) => {
        $(`#filter-${field}`).addEventListener('change', (event) => {
            state[field] = event.target.value;
            state.page = 1;
            load();
        });
    });

    $('#filter-archived').addEventListener('change', (event) => {
        state.includeArchived = event.target.checked;
        state.page = 1;
        load();
    });

    // The two counting tiles are filters as well as figures: seeing "3 negative"
    // and having no way to ask which three would be a dead end.
    $$('[data-status]').forEach((tile) => {
        tile.addEventListener('click', () => {
            state.status = state.status === tile.dataset.status ? '' : tile.dataset.status;
            state.page = 1;
            $('#filter-status').value = state.status;
            load();
        });
    });

    $('#stock-body').addEventListener('click', (event) => {
        const row = event.target.closest('[data-variant]');
        if (row) openCard(row.dataset.variant);
    });

    $('#stock-body').addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;

        const row = event.target.closest('[data-variant]');
        if (row) openCard(row.dataset.variant);
    });

    $('#page-prev').addEventListener('click', () => {
        if (state.page > 1) {
            state.page -= 1;
            load();
        }
    });

    $('#page-next').addEventListener('click', () => {
        if (state.hasMore) {
            state.page += 1;
            load();
        }
    });

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
}
