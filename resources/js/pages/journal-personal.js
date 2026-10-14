import auth from '../auth-client';
import { can } from '../permissions';
import {
    $, clearFormErrors, confirmAction, debounce, esc, formatDate, formatMoney, hideModal,
    setSubmitting, showActionError, showFormErrors, showModal, tableMessage, toast,
} from '../ui';
import { adoptForm, mountWorkspace } from '../workspace';

/**
 * Personal — the owner's own diary. One tab of Money in & out.
 *
 * ```
 * tab → ENTRY FORM                    ← date, what for, how much, how paid
 *     → "Show list (42)"              → this month's spending, and where it went
 *     → row → drawer (level 2)        → edit (the form, adopted) · delete → confirm (level 3)
 *     → "Categories" → drawer (level 2) → add · rename · archive · delete
 * ```
 *
 * ## Not the workshop's money
 *
 * Every entry is money out of the owner's own pocket, so nothing here touches
 * the books — no voucher, no ledger line, no report. That is why an entry is
 * simply edited and deleted, where everything else on this card is reversed:
 * a diary line moved no account, so there is no trail of postings to keep.
 *
 * ## Who sees it
 *
 * PERSONAL_EXPENSES, which only OWNER holds. The tab is not drawn for anybody
 * else, and the server refuses them anyway (§6.1). One diary per workshop:
 * two owners see one list, and each line says who wrote it.
 */

const PAGE_SIZE = 25;
const COLUMNS = 5;

const state = {
    categories: [],
    methods: [],

    search: '',
    period: 'this-month',
    category: '',
    paidBy: '',
    page: 1,
    total: null,
    rows: [],

    /** The entry the drawer is showing, and whether it is being edited. */
    current: null,
    editing: false,

    /** The create surface's half-typed entry, held while the form is in the drawer. */
    draft: null,
};

let workspace = null;
let formRoot = null;
let listRoot = null;
let form = null;
let entryDrawer = null;
let categoryDrawer = null;

const inForm = (selector) => $(selector, formRoot);
const inList = (selector) => $(selector, listRoot);
const inDrawer = (selector) => $(selector, entryDrawer);
const inCategories = (selector) => $(selector, categoryDrawer);

function today() {
    return new Date().toISOString().slice(0, 10);
}

/* -------------------------------------------------------------------------
 | The vocabulary — categories and ways of paying
 | ---------------------------------------------------------------------- */

async function loadMeta() {
    const { data } = await auth.call('/personal-expenses/meta');

    state.categories = data.categories ?? [];
    state.methods = data.methods ?? [];

    paintCategoryOptions();
    paintMethodOptions();
}

/**
 * The form offers active categories only; an entry being edited keeps the one
 * it is filed under even if that has since been archived, or the select would
 * quietly show the first choice and save it.
 */
function paintCategoryOptions() {
    const select = form.elements.category_id;
    const keep = select.value;
    const current = state.editing ? String(state.current?.category_id ?? '') : '';

    const usable = state.categories.filter((row) => row.is_active || String(row.id) === current);

    select.innerHTML = ['<option value="">Choose…</option>'].concat(usable.map((row) => `
        <option value="${row.id}">${esc(row.name)}${row.is_active ? '' : ' (archived)'}</option>`)).join('');

    select.value = keep;

    const filter = inList('[data-personal-filter="category"]');
    const held = filter.value;

    filter.innerHTML = ['<option value="">Every category</option>'].concat(state.categories.map((row) => `
        <option value="${row.id}">${esc(row.name)}</option>`)).join('');

    filter.value = held;
}

function paintMethodOptions() {
    const options = state.methods.map((row) => `<option value="${esc(row.value)}">${esc(row.label)}</option>`).join('');

    const select = form.elements.paid_by;
    const keep = select.value || 'upi';

    select.innerHTML = options;
    select.value = state.methods.some((row) => row.value === keep) ? keep : (state.methods[0]?.value ?? '');

    const filter = inList('[data-personal-filter="paid_by"]');
    const held = filter.value;

    filter.innerHTML = `<option value="">Any way of paying</option>${options}`;
    filter.value = held;
}

function methodLabel(value) {
    return state.methods.find((row) => row.value === value)?.label ?? value;
}

/* -------------------------------------------------------------------------
 | The list
 | ---------------------------------------------------------------------- */

/** The period select, as the from/to pair the API takes. */
function periodRange(period) {
    const now = new Date();
    const y = now.getFullYear();
    const m = now.getMonth();
    const iso = (date) => [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0'),
    ].join('-');

    switch (period) {
        case 'this-month': return [iso(new Date(y, m, 1)), iso(new Date(y, m + 1, 0))];
        case 'last-month': return [iso(new Date(y, m - 1, 1)), iso(new Date(y, m, 0))];
        case 'last-3-months': return [iso(new Date(y, m - 2, 1)), iso(new Date(y, m + 1, 0))];
        case 'this-year': return [iso(new Date(y, 0, 1)), iso(new Date(y, 11, 31))];
        default: return [null, null];
    }
}

function query() {
    const params = new URLSearchParams();
    const [from, to] = periodRange(state.period);

    if (state.search) params.set('search', state.search);
    if (state.category) params.set('category_id', state.category);
    if (state.paidBy) params.set('paid_by', state.paidBy);
    if (from) params.set('from', from);
    if (to) params.set('to', to);

    params.set('per_page', PAGE_SIZE);
    params.set('page', state.page);

    return params;
}

async function load() {
    inList('[data-personal-body]').innerHTML = tableMessage(COLUMNS, 'Loading…');

    try {
        const payload = await auth.call(`/personal-expenses?${query()}`);

        state.rows = payload.data ?? [];

        render(payload.meta ?? {});
    } catch (error) {
        state.total = null;
        inList('[data-personal-body]').innerHTML = tableMessage(COLUMNS, error.message, 'error');
        inList('[data-personal-summary]').innerHTML = '';
    }
}

const refetch = debounce(async () => {
    state.page = 1;
    await load();
}, 250);

function render(meta) {
    const pagination = meta.pagination ?? {};

    state.total = pagination.total ?? null;

    inList('[data-personal-body]').innerHTML = state.rows.length
        ? state.rows.map(renderRow).join('')
        : tableMessage(COLUMNS, filtered()
            ? 'Nothing matches. Clear the filters, or pick a longer period.'
            : 'Nothing recorded for this period yet.');

    renderSummary(meta.summary);

    inList('[data-personal-pager-summary]').textContent = pagination.total
        ? `${state.rows.length} of ${pagination.total}.`
        : '';

    inList('[data-personal-page="prev"]').disabled = (pagination.current_page ?? 1) <= 1;
    inList('[data-personal-page="next"]').disabled = !pagination.has_more;

    workspace?.refresh();
}

function filtered() {
    return Boolean(state.search || state.category || state.paidBy);
}

function renderRow(row) {
    const flash = workspace?.isNew(row.id) ? ' row-new' : '';

    return `
        <tr class="cursor-pointer border-t border-border transition hover:bg-secondary/60${flash}"
            data-personal-row="${row.id}" tabindex="0" role="link"
            aria-label="Open ${esc(formatMoney(row.amount))} on ${esc(formatDate(row.date))}">
            <td class="table-cell w-28 whitespace-nowrap text-[0.8125rem]">${esc(formatDate(row.date))}</td>
            <td class="table-cell text-[0.8125rem] font-medium">${esc(row.category ?? '—')}</td>
            <td class="table-cell text-[0.8125rem]">
                ${row.notes ? esc(row.notes) : '<span class="text-muted-foreground">No note</span>'}
            </td>
            <td class="table-cell w-32 text-[0.8125rem] text-muted-foreground">${esc(row.paid_by_label ?? '')}</td>
            <td class="table-cell w-32 text-right font-mono text-[0.8125rem] font-semibold">
                ${esc(formatMoney(row.amount))}
            </td>
        </tr>`;
}

/**
 * What the filter adds up to, and where it went. Across every matching entry,
 * not the page — the server sums it. A chip narrows the list to its category.
 */
function renderSummary(summary) {
    const host = inList('[data-personal-summary]');

    if (!summary) {
        host.innerHTML = '';

        return;
    }

    const label = inList('[data-personal-filter="period"]').selectedOptions[0]?.textContent ?? '';

    const chips = (summary.by_category ?? []).map((row) => `
        <button type="button" data-personal-chip="${row.category_id}"
                class="rounded-full border px-3 py-1 text-xs transition hover:bg-secondary
                       ${String(row.category_id) === state.category
                           ? 'border-primary bg-primary/5 font-semibold text-primary'
                           : 'border-border text-foreground'}"
                aria-pressed="${String(row.category_id) === state.category}">
            ${esc(row.name)} · <span class="font-mono">${esc(formatMoney(row.total))}</span>
        </button>`).join('');

    host.innerHTML = `
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <p class="text-[0.8125rem] text-muted-foreground">
                ${esc(label)}${filtered() ? ', filtered' : ''} ·
                ${summary.count} entr${summary.count === 1 ? 'y' : 'ies'}
            </p>
            <p class="font-mono text-xl font-bold text-foreground">${esc(formatMoney(summary.total))}</p>
        </div>
        ${chips ? `<div class="mt-3 flex flex-wrap gap-2">${chips}</div>` : ''}`;
}

/* -------------------------------------------------------------------------
 | Writing
 | ---------------------------------------------------------------------- */

function validate() {
    const errors = {};
    const amount = form.elements.amount.value.trim();

    if (!form.elements.date.value) errors.date = ['Give the date.'];
    if (!form.elements.category_id.value) errors.category_id = ['Choose what it was for.'];
    if (!amount || !(Number(amount) > 0)) errors.amount = ['Enter an amount greater than zero.'];
    if (!form.elements.paid_by.value) errors.paid_by = ['Say how it was paid.'];

    return Object.keys(errors).length ? errors : null;
}

function payload() {
    return {
        date: form.elements.date.value,
        category_id: Number(form.elements.category_id.value),
        amount: form.elements.amount.value.trim(),
        paid_by: form.elements.paid_by.value,
        notes: form.elements.notes.value.trim() || null,
    };
}

/**
 * Cleared for the next entry, keeping the date and the way of paying — a
 * receipt pile from one day is usually paid one way.
 */
function resetForm() {
    clearFormErrors(form);

    form.elements.category_id.value = '';
    form.elements.amount.value = '';
    form.elements.notes.value = '';
}

async function submit(event) {
    event.preventDefault();

    clearFormErrors(form);

    const errors = validate();

    if (errors) {
        showFormErrors(form, { fields: errors, message: 'Check the highlighted fields.' });

        return;
    }

    if (state.editing) {
        await saveEdit();

        return;
    }

    setSubmitting(form, true, 'Recording…');

    try {
        const response = await auth.call('/personal-expenses', { method: 'POST', body: payload() });

        toast(response.message ?? 'Recorded.');

        // §2A.8 — stay on the form, cleared, focus back where the next entry
        // starts; the new row flashes whenever the list is next looked at.
        if (response.data?.id) workspace?.flagNew(response.data.id);

        resetForm();
        form.elements.category_id.focus();

        if (workspace?.hasList()) refetch();
        else workspace?.refresh();
    } catch (error) {
        showFormErrors(form, error);
    } finally {
        setSubmitting(form, false, 'Record it');
    }
}

/* -------------------------------------------------------------------------
 | One entry — level 2
 | ---------------------------------------------------------------------- */

async function openEntry(id) {
    state.current = null;

    inDrawer('[data-drawer-title]').textContent = 'Loading…';
    inDrawer('[data-drawer-subtitle]').textContent = '';
    inDrawer('[data-drawer-actions]').innerHTML = '';
    inDrawer('[data-drawer-body]').innerHTML =
        '<p class="py-8 text-center text-sm text-muted-foreground">Loading…</p>';

    showModal(entryDrawer);

    try {
        const { data } = await auth.call(`/personal-expenses/${id}`);

        state.current = data;

        paintEntry();
    } catch (error) {
        inDrawer('[data-drawer-title]').textContent = 'Personal expense';
        inDrawer('[data-drawer-body]').innerHTML =
            `<p class="py-8 text-center text-sm text-rose-600">${esc(error.message)}</p>`;
        inDrawer('[data-drawer-actions]').innerHTML =
            '<button type="button" class="btn btn-secondary btn-sm ml-auto" data-modal-close>Close</button>';
    }
}

function paintEntry() {
    const entry = state.current;

    inDrawer('[data-drawer-title]').textContent = formatMoney(entry.amount);
    inDrawer('[data-drawer-subtitle]').textContent = [entry.category, formatDate(entry.date)]
        .filter(Boolean).join(' · ');

    const row = (label, value) => `
        <div class="flex justify-between gap-4 border-b border-muted py-2.5 last:border-0">
            <dt class="text-[0.8125rem] text-muted-foreground">${label}</dt>
            <dd class="text-right text-[0.8125rem] font-medium text-foreground">${value}</dd>
        </div>`;

    inDrawer('[data-drawer-body]').innerHTML = `
        <dl>
            ${row('Amount', `<span class="font-mono">${esc(formatMoney(entry.amount))}</span>`)}
            ${row('What it was for', esc(entry.category ?? '—'))}
            ${row('Date', esc(formatDate(entry.date)))}
            ${row('Paid by', esc(entry.paid_by_label ?? methodLabel(entry.paid_by)))}
            ${row('Note', entry.notes ? esc(entry.notes) : '<span class="text-muted-foreground">None</span>')}
        </dl>
        <p class="mt-4 text-xs text-muted-foreground">
            Recorded${entry.created_by ? ` by ${esc(entry.created_by)}` : ''}${
                entry.created_at ? ` on ${esc(formatDate(entry.created_at.slice(0, 10)))}` : ''
            }. Personal — not in the shop's books.
        </p>`;

    paintEntryActions();
}

function paintEntryActions() {
    const buttons = [];

    if (can('UPDATE', 'PERSONAL_EXPENSES')) {
        buttons.push('<button type="button" class="btn btn-secondary btn-sm" data-personal-edit>Edit</button>');
    }

    if (can('DELETE', 'PERSONAL_EXPENSES')) {
        buttons.push('<button type="button" class="btn btn-ghost btn-sm hover:!text-rose-600" '
            + 'data-personal-delete>Delete</button>');
    }

    inDrawer('[data-drawer-actions]').innerHTML = buttons.join('')
        + '<button type="button" class="btn btn-secondary btn-sm ml-auto" data-modal-close>Close</button>';
}

/**
 * Edit is a state of the drawer with the one form adopted into it — never a
 * second copy of the fields.
 */
function startEdit() {
    const entry = state.current;
    if (!entry) return;

    state.editing = true;

    // Held so the create surface gets its half-typed entry back afterwards.
    state.draft = {
        date: form.elements.date.value,
        category_id: form.elements.category_id.value,
        amount: form.elements.amount.value,
        paid_by: form.elements.paid_by.value,
        notes: form.elements.notes.value,
    };

    clearFormErrors(form);
    paintCategoryOptions();

    form.elements.date.value = entry.date;
    form.elements.category_id.value = String(entry.category_id);
    form.elements.amount.value = entry.amount;
    form.elements.paid_by.value = entry.paid_by;
    form.elements.notes.value = entry.notes ?? '';

    adoptForm(form, inDrawer('[data-personal-edit-slot]'), { chrome: 'modal' });

    inDrawer('[data-personal-edit-slot]').classList.remove('hidden');
    inDrawer('[data-drawer-body]').classList.add('hidden');
    inDrawer('[data-drawer-actions]').innerHTML =
        '<button type="button" class="btn btn-secondary btn-sm ml-auto" data-modal-close>Close</button>';

    form.elements.amount.focus();
}

/** Give the form back to the create surface. Idempotent. */
function closeEdit() {
    if (!state.editing) return;

    state.editing = false;

    setSubmitting(form, false);
    clearFormErrors(form);

    adoptForm(form, inForm('[data-personal-form-slot]'), { chrome: 'inline' });

    inDrawer('[data-personal-edit-slot]').classList.add('hidden');
    inDrawer('[data-drawer-body]').classList.remove('hidden');

    paintCategoryOptions();

    const draft = state.draft ?? {};

    form.elements.date.value = draft.date || today();
    form.elements.category_id.value = draft.category_id ?? '';
    form.elements.amount.value = draft.amount ?? '';
    form.elements.paid_by.value = draft.paid_by || form.elements.paid_by.value;
    form.elements.notes.value = draft.notes ?? '';

    state.draft = null;

    if (state.current) paintEntryActions();
}

async function saveEdit() {
    setSubmitting(form, true, 'Saving…');

    try {
        const response = await auth.call(`/personal-expenses/${state.current.id}`, {
            method: 'PATCH',
            body: payload(),
        });

        toast(response.message ?? 'Updated.');

        state.current = response.data;

        closeEdit();
        paintEntry();

        if (workspace?.hasList()) await load();
    } catch (error) {
        showFormErrors(form, error);
    } finally {
        setSubmitting(form, false, 'Save changes');
    }
}

async function deleteEntry(trigger) {
    const entry = state.current;
    if (!entry) return;

    const ok = await confirmAction({
        title: `Delete ${formatMoney(entry.amount)} on ${formatDate(entry.date)}?`,
        body: 'It comes off your personal diary for good. Nothing in the shop\'s books changes — it was never in them.',
        confirmLabel: 'Delete it',
    });

    if (!ok) return;

    try {
        const response = await auth.call(`/personal-expenses/${entry.id}`, { method: 'DELETE' });

        toast(response.message ?? 'Deleted.');

        state.current = null;
        hideModal(entryDrawer);

        if (workspace?.hasList()) await load();
        else workspace?.refresh();
    } catch (error) {
        showActionError(trigger, error.message);
    }
}

/* -------------------------------------------------------------------------
 | Categories — level 2
 | ---------------------------------------------------------------------- */

async function openCategories() {
    inCategories('[data-personal-category-list]').innerHTML =
        '<p class="py-6 text-center text-sm text-muted-foreground">Loading…</p>';

    showModal(categoryDrawer);

    await reloadCategories();

    inCategories('#personal-category-name').focus();
}

async function reloadCategories() {
    try {
        const { data } = await auth.call('/personal-expenses/categories');

        state.categories = data ?? [];

        renderCategories();
        paintCategoryOptions();
    } catch (error) {
        inCategories('[data-personal-category-list]').innerHTML =
            `<p class="py-6 text-center text-sm text-rose-600">${esc(error.message)}</p>`;
    }
}

function renderCategories() {
    const host = inCategories('[data-personal-category-list]');

    if (!state.categories.length) {
        host.innerHTML = '<p class="text-[0.8125rem] text-muted-foreground">No categories yet. Add the first above.</p>';

        return;
    }

    const mayUpdate = can('UPDATE', 'PERSONAL_EXPENSES');
    const mayDelete = can('DELETE', 'PERSONAL_EXPENSES');

    host.innerHTML = state.categories.map((row) => `
        <div class="flex items-center gap-2 border-b border-muted py-2.5 last:border-0 ${row.is_active ? '' : 'opacity-60'}"
             data-category-row="${row.id}">
            <div class="min-w-0 flex-1" data-category-view>
                <div class="truncate text-[0.875rem] font-medium text-foreground">
                    ${esc(row.name)}
                    ${row.is_active ? '' : '<span class="badge bg-muted text-secondary-foreground">Archived</span>'}
                </div>
                <div class="text-[0.75rem] text-muted-foreground">
                    ${row.expense_count === 0
                        ? 'Nothing filed under it'
                        : `${row.expense_count} entr${row.expense_count === 1 ? 'y' : 'ies'}`}
                </div>
            </div>
            ${mayUpdate ? `
                <button type="button" class="btn btn-ghost btn-sm" data-category-rename="${row.id}">Rename</button>
                <button type="button" class="btn btn-ghost btn-sm" data-category-archive="${row.id}"
                        data-active="${row.is_active}">${row.is_active ? 'Archive' : 'Restore'}</button>` : ''}
            ${mayDelete && row.expense_count === 0
                ? `<button type="button" class="btn btn-ghost btn-sm hover:!text-rose-600"
                           data-category-delete="${row.id}" aria-label="Delete ${esc(row.name)}">Delete</button>`
                : ''}
        </div>`).join('');
}

async function addCategory(event) {
    event.preventDefault();

    const categoryForm = event.currentTarget;
    const input = categoryForm.elements.name;

    clearFormErrors(categoryForm);

    if (input.value.trim().length < 2) {
        showFormErrors(categoryForm, {
            fields: { name: ['Give the category a name.'] },
            message: 'Give the category a name.',
        });

        return;
    }

    setSubmitting(categoryForm, true, 'Adding…');

    try {
        const response = await auth.call('/personal-expenses/categories', {
            method: 'POST',
            body: { name: input.value.trim() },
        });

        toast(response.message ?? 'Category added.');
        input.value = '';

        await reloadCategories();
        input.focus();
    } catch (error) {
        showFormErrors(categoryForm, error);
    } finally {
        setSubmitting(categoryForm, false, 'Add');
    }
}

/** Rename in place: the row's text becomes an input with Save and Cancel. */
function startRename(id) {
    const category = state.categories.find((row) => String(row.id) === String(id));
    const rowNode = inCategories(`[data-category-row="${id}"]`);
    if (!category || !rowNode) return;

    rowNode.innerHTML = `
        <form class="flex w-full flex-wrap items-start gap-2" data-category-rename-form="${id}" novalidate>
            <input name="name" type="text" class="field-input min-w-0 flex-1" maxlength="80"
                   value="${esc(category.name)}" aria-label="New name for ${esc(category.name)}">
            <button type="submit" class="btn btn-primary btn-sm">Save</button>
            <button type="button" class="btn btn-ghost btn-sm" data-category-rename-cancel>Cancel</button>
            <p class="field-error hidden basis-full" data-error-for="name"></p>
        </form>`;

    const input = $('input', rowNode);
    input.focus();
    input.select();
}

async function saveRename(renameForm) {
    const id = renameForm.dataset.categoryRenameForm;
    const name = renameForm.elements.name.value.trim();

    clearFormErrors(renameForm);

    if (name.length < 2) {
        showFormErrors(renameForm, { fields: { name: ['Give the category a name.'] }, message: 'Give the category a name.' });

        return;
    }

    setSubmitting(renameForm, true, 'Saving…');

    try {
        const response = await auth.call(`/personal-expenses/categories/${id}`, { method: 'PATCH', body: { name } });

        toast(response.message ?? 'Category renamed.');

        await reloadCategories();

        // Rows already fetched carry the old name; the list is current again on
        // its next load, which is now if it is held.
        if (workspace?.hasList()) await load();
    } catch (error) {
        showFormErrors(renameForm, error);
        setSubmitting(renameForm, false, 'Save');
    }
}

async function toggleArchive(button) {
    const id = button.dataset.categoryArchive;
    const active = button.dataset.active === 'true';

    try {
        const response = await auth.call(`/personal-expenses/categories/${id}`, {
            method: 'PATCH',
            body: { is_active: !active },
        });

        toast(response.message ?? (active ? 'Archived.' : 'Restored.'));

        await reloadCategories();
    } catch (error) {
        showActionError(button, error.message);
    }
}

async function deleteCategory(button) {
    const id = button.dataset.categoryDelete;
    const category = state.categories.find((row) => String(row.id) === String(id));
    if (!category) return;

    const ok = await confirmAction({
        title: `Delete "${category.name}"?`,
        body: 'Nothing is filed under it, so nothing else changes.',
        confirmLabel: 'Delete',
    });

    if (!ok) return;

    try {
        const response = await auth.call(`/personal-expenses/categories/${id}`, { method: 'DELETE' });

        toast(response.message ?? 'Category deleted.');

        await reloadCategories();
    } catch (error) {
        showActionError(button, error.message);
    }
}

/* -------------------------------------------------------------------------
 | Wiring
 | ---------------------------------------------------------------------- */

function bindList() {
    inList('[data-personal-filter="search"]').addEventListener('input', (event) => {
        state.search = event.target.value.trim();
        refetch();
    });

    [['period', 'period'], ['category', 'category'], ['paid_by', 'paidBy']].forEach(([hook, key]) => {
        inList(`[data-personal-filter="${hook}"]`).addEventListener('change', (event) => {
            state[key] = event.target.value;
            refetch();
        });
    });

    inList('[data-personal-clear]').addEventListener('click', () => {
        Object.assign(state, { search: '', period: 'this-month', category: '', paidBy: '', page: 1 });

        inList('[data-personal-filter="search"]').value = '';
        inList('[data-personal-filter="period"]').value = 'this-month';
        inList('[data-personal-filter="category"]').value = '';
        inList('[data-personal-filter="paid_by"]').value = '';

        load();
    });

    inList('[data-personal-summary]').addEventListener('click', (event) => {
        const chip = event.target.closest('[data-personal-chip]');
        if (!chip) return;

        // A second press on the chosen chip widens back out.
        state.category = state.category === chip.dataset.personalChip ? '' : chip.dataset.personalChip;
        inList('[data-personal-filter="category"]').value = state.category;
        refetch();
    });

    const open = (event) => {
        const row = event.target.closest('[data-personal-row]');

        if (row) openEntry(row.dataset.personalRow);
    };

    inList('[data-personal-body]').addEventListener('click', open);
    inList('[data-personal-body]').addEventListener('keydown', (event) => {
        if (event.key === 'Enter') open(event);
    });

    inList('[data-personal-page="prev"]').addEventListener('click', () => {
        if (state.page > 1) {
            state.page -= 1;
            load();
        }
    });

    inList('[data-personal-page="next"]').addEventListener('click', (event) => {
        if (!event.currentTarget.disabled) {
            state.page += 1;
            load();
        }
    });

    inList('[data-personal-manage-categories]').addEventListener('click', openCategories);
}

function bindDrawers() {
    entryDrawer.addEventListener('click', (event) => {
        if (event.target.closest('[data-personal-edit]')) {
            startEdit();

            return;
        }

        const remove = event.target.closest('[data-personal-delete]');

        if (remove) {
            deleteEntry(remove);

            return;
        }

        if (event.target.closest('[data-personal-edit-cancel]')) {
            closeEdit();

            return;
        }

        // Beside ui.js's own handler, which hides the drawer: this takes the
        // form back out of it first.
        if (event.target.closest('[data-modal-close]') || event.target.matches('[data-modal]')) {
            closeEdit();
        }
    });

    // Escape closes the drawer through ui.js, which knows nothing about the
    // form standing inside it.
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && state.editing) closeEdit();
    });

    $('[data-personal-category-form]', categoryDrawer).addEventListener('submit', addCategory);

    categoryDrawer.addEventListener('click', (event) => {
        const rename = event.target.closest('[data-category-rename]');
        if (rename) {
            startRename(rename.dataset.categoryRename);

            return;
        }

        if (event.target.closest('[data-category-rename-cancel]')) {
            renderCategories();

            return;
        }

        const archive = event.target.closest('[data-category-archive]');
        if (archive) {
            toggleArchive(archive);

            return;
        }

        const remove = event.target.closest('[data-category-delete]');
        if (remove) deleteCategory(remove);
    });

    categoryDrawer.addEventListener('submit', (event) => {
        const renameForm = event.target.closest('[data-category-rename-form]');
        if (!renameForm) return;

        event.preventDefault();
        saveRename(renameForm);
    });
}

/**
 * Build the section on its own root. Called once, by `pages/journal.js`, the
 * first time the Personal tab is shown.
 *
 * @param {HTMLElement} sectionRoot the `[data-txn-section="personal"]` node
 * @param {{ entry: HTMLElement, categories: HTMLElement }} drawers
 */
export async function mountPersonalSection(sectionRoot, drawers) {
    entryDrawer = drawers.entry;
    categoryDrawer = drawers.categories;

    // Held before `mountWorkspace` detaches one of them.
    formRoot = $('[data-ws-form]', sectionRoot);
    listRoot = $('[data-ws-list]', sectionRoot);
    form = $('#personal-form', formRoot);

    form.elements.date.value = today();

    await loadMeta();

    form.addEventListener('submit', submit);
    inForm('[data-personal-manage-categories]').addEventListener('click', openCategories);

    bindList();
    bindDrawers();

    workspace = mountWorkspace(sectionRoot, {
        key: 'journal:personal',
        title: 'Personal expenses',
        formSubtitle: 'Your own money, kept for your own record. Owners only — never in the shop\'s books.',
        listSubtitle: (count) => (count === null
            ? 'What you have spent, newest first.'
            : `${count} entr${count === 1 ? 'y' : 'ies'} in this view, newest first.`),
        createLabel: 'Record a personal expense',
        count: () => state.total,
        canCreate: can('WRITE', 'PERSONAL_EXPENSES'),
        onShowList: load,
        onShowForm: () => form.elements.category_id.focus(),
    });

    return { workspace };
}
