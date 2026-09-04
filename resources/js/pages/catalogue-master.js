/**
 * The Category, Brand and Unit masters — the catalogue's vocabulary.
 *
 * This is the screen that made the inventory generic. An admin adds a category
 * here, gives it fields, and the universal create form asks for those fields the
 * next time it is opened. No migration, no new API, no new component, no
 * deployment — which is the module's whole acceptance criterion.
 *
 * ## What it is, structurally
 *
 * A drawer over the Items workspace (§2, level 2) with two levels of its own: the
 * master list, and one category's fields. Moving between them *swaps the drawer's
 * body* rather than stacking a second surface, because a list with add, edit,
 * reorder and delete inside a modal is the scroll trap §2.1 forbids. Only the
 * confirmations are level 3, and nothing opens over those.
 *
 * ## Why almost everything here is a refusal the server makes
 *
 * The values products have recorded live in `item_variants.attributes`, and
 * nothing rewrites them. So deleting a field products have answered, narrowing a
 * dropdown below what they hold, and making a field compulsory when some are
 * blank are all things an admin can reasonably want and all things that quietly
 * break records. The server refuses each with the remedy in the message; this
 * screen's job is to *show* the counts beforehand, so the refusal is rarely the
 * first time anybody hears about it.
 */

import auth from '../auth-client';
import {
    $, $$, clearFormErrors, confirmAction, esc, hideModal,
    setSubmitting, showFormErrors, showModal, toast,
} from '../ui';

const state = {
    categories: [],
    brands: [],
    units: [],
    templates: [],
    meta: null,

    tab: 'categories',
    // The category being drilled into, or null at the master list.
    open: null,

    // Called after any write, so the Items form's own vocabulary is refreshed
    // rather than left describing a category that has since changed. It is told
    // *what* changed, so the form can act on a brand somebody has just created
    // rather than merely offering it.
    onChange: null,
};

/* -------------------------------------------------------------------------
 | Fetching
 | ---------------------------------------------------------------------- */

async function loadCategories() {
    const response = await auth.call('/item-categories');

    state.categories = response.data ?? [];
    state.templates = response.meta?.templates ?? [];
}

/**
 * Every brand, archived ones included.
 *
 * `is_active` is deliberately not sent: the master is where somebody archives
 * and restores, and a list that hid what it had just archived would look like a
 * delete. The *create form* asks /items/meta, which sends active only.
 */
async function loadBrands() {
    const response = await auth.call('/item-brands');

    state.brands = response.data ?? [];
}

async function loadUnits() {
    // `with_usage` costs four counting queries per unit, and this is the one
    // screen where somebody is deciding what to remove — so it is worth it here
    // and nowhere else.
    const response = await auth.call('/units?with_usage=1');

    state.units = response.data ?? [];
}

async function loadMeta() {
    if (state.meta) return state.meta;

    const { data } = await auth.call('/items/meta');
    state.meta = data;

    return state.meta;
}

/* -------------------------------------------------------------------------
 | The drawer
 | ---------------------------------------------------------------------- */

/**
 * Open the master.
 *
 * @param {object}   options
 * @param {string}   options.tab        'categories', 'brands' or 'units'.
 * @param {number}   options.categoryId Drill straight into one category's fields.
 * @param {Function} options.onChange   Run after any write.
 */
export async function openCatalogueMaster({ tab = 'categories', categoryId = null, onChange = null } = {}) {
    state.tab = tab;
    state.open = null;
    state.onChange = onChange;

    showModal('#catalogue-drawer');
    renderLoading();

    await loadMeta();
    await Promise.all([loadCategories(), loadBrands(), loadUnits()]);

    if (categoryId) {
        const found = state.categories.find((category) => category.id === Number(categoryId));
        if (found) state.open = found;
    }

    render();
}

function renderLoading() {
    $('#catalogue-body').innerHTML =
        '<p class="py-8 text-center text-sm text-muted-foreground">Loading…</p>';
    $('#catalogue-foot').innerHTML = '';
}

function render() {
    syncTabs();

    if (state.open) {
        renderCategoryFields();

        return;
    }

    if (state.tab === 'units') renderUnits();
    else if (state.tab === 'brands') renderBrands();
    else renderCategories();
}

const SUBTITLES = {
    categories: 'What each kind of product records, and how it is counted.',
    brands: "Whose the shop's products are.",
    units: 'How the shop counts what it holds.',
};

function syncTabs() {
    const drilled = state.open !== null;

    // Hidden on the drill-down: inside one category there is nothing to switch
    // to, and leaving the strip up would offer a jump that silently abandoned
    // what was being edited.
    $('#catalogue-tabs').classList.toggle('hidden', drilled);
    $('#catalogue-back').classList.toggle('hidden', !drilled);

    $$('[data-catalogue-tab]').forEach((button) => {
        button.classList.toggle('is-active', button.dataset.catalogueTab === state.tab);
        button.setAttribute('aria-selected', String(button.dataset.catalogueTab === state.tab));
    });

    $('#catalogue-drawer-title').textContent = drilled ? state.open.name : 'Catalogue setup';
    $('#catalogue-drawer-subtitle').textContent = drilled
        ? 'What the create form asks for when this category is chosen.'
        : SUBTITLES[state.tab] ?? SUBTITLES.categories;
}

/* -------------------------------------------------------------------------
 | Categories
 | ---------------------------------------------------------------------- */

function renderCategories() {
    const rows = state.categories.map((category) => {
        const fields = Object.keys(category.schema ?? {});
        const parent = category.parent_id
            ? state.categories.find((candidate) => candidate.id === category.parent_id)
            : null;

        return `
            <button type="button" data-open-category="${category.id}"
                    class="w-full rounded-[10px] border border-border px-3.5 py-3 text-left transition
                           hover:border-primary/40 hover:bg-secondary/40">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-foreground">
                            ${esc(category.name)}
                            ${parent ? `<span class="font-normal text-muted-foreground">under ${esc(parent.name)}</span>` : ''}
                        </p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            ${fields.length
                                ? `Asks for ${fields.length} field${fields.length === 1 ? '' : 's'}: ${
                                    esc(fields.map((key) => category.schema[key].label).join(', '))}`
                                : 'No specification fields yet'}
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-1.5">
                        ${category.is_active ? '' : '<span class="badge badge-muted">Archived</span>'}
                        ${category.holds_stock ? '' : '<span class="badge badge-muted">No stock</span>'}
                        <span class="text-xs text-muted-foreground">${category.item_count ?? 0}</span>
                    </div>
                </div>
            </button>`;
    }).join('');

    $('#catalogue-body').innerHTML = `
        <div class="space-y-2">
            ${rows || '<p class="py-8 text-center text-sm text-muted-foreground">No categories yet.</p>'}
        </div>
        ${renderTemplates()}`;

    $('#catalogue-foot').innerHTML = `
        <button type="button" class="btn btn-primary btn-sm" data-new-category
                data-requires-permission="UPDATE:ITEMS">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 5v14M5 12h14"/>
            </svg>
            New category
        </button>
        <p class="ml-auto text-xs text-muted-foreground">The number is how many products are filed under it.</p>`;
}

/**
 * The ready-made definitions still worth offering.
 *
 * Offered rather than seeded: a garment shop should not find "Capacitor" in its
 * catalogue because the product was written for a motor workshop. What applying
 * one produces is an ordinary category, editable and deletable a minute later.
 */
function renderTemplates() {
    if (!state.templates.length) return '';

    return `
        <div class="mt-6 border-t border-muted pt-5">
            <p class="text-[0.8125rem] font-semibold text-foreground">Start from a ready-made one</p>
            <p class="mt-0.5 text-xs text-muted-foreground">
                Creates an ordinary category with its fields already set up. Edit or delete it like any other.
            </p>
            <div class="mt-3 flex flex-wrap gap-2">
                ${state.templates.map((template) => `
                    <button type="button" class="btn btn-secondary btn-sm" data-template="${esc(template.name)}"
                            title="${esc(template.fields.join(', '))}"
                            data-requires-permission="UPDATE:ITEMS">
                        ${esc(template.name)}
                        <span class="text-muted-foreground">${template.field_count}</span>
                    </button>`).join('')}
            </div>
        </div>`;
}

/* -------------------------------------------------------------------------
 | One category's fields
 | ---------------------------------------------------------------------- */

function renderCategoryFields() {
    const category = state.open;
    const own = category.attributes ?? [];

    // Inherited fields are shown and not editable here: they belong to the
    // parent, and editing them there changes every category under it at once —
    // which is the point of putting them there.
    const inherited = Object.keys(category.schema ?? {})
        .filter((key) => !own.some((field) => field.key === key))
        .map((key) => ({ key, ...category.schema[key] }));

    $('#catalogue-body').innerHTML = `
        ${category.description
            ? `<p class="mb-4 text-[0.8125rem] text-secondary-foreground">${esc(category.description)}</p>`
            : ''}

        <div class="mb-4 flex flex-wrap gap-1.5">
            <span class="badge badge-muted">${category.holds_stock ? 'Kept in stock' : 'No stock'}</span>
            <span class="badge badge-muted">${esc(category.tax_code_label)} code</span>
            ${category.default_unit_code ? `<span class="badge badge-muted">Counted in ${esc(category.default_unit_code)}</span>` : ''}
            ${category.default_gst_rate !== null ? `<span class="badge badge-muted">GST ${esc(category.default_gst_rate)}%</span>` : ''}
            <span class="badge badge-muted">${category.item_count ?? 0} product${(category.item_count ?? 0) === 1 ? '' : 's'}</span>
        </div>

        ${inherited.length ? `
            <p class="field-label">Inherited</p>
            <div class="mb-4 space-y-2">
                ${inherited.map((field) => `
                    <div class="rounded-[10px] border border-dashed border-border px-3.5 py-2.5">
                        <p class="text-[0.8125rem] font-medium text-foreground">
                            ${esc(field.label)}
                            ${field.suffix ? `<span class="font-normal text-muted-foreground">(${esc(field.suffix)})</span>` : ''}
                        </p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            From a parent category — edit it there and every category under it changes with it.
                        </p>
                    </div>`).join('')}
            </div>` : ''}

        <p class="field-label">Its own fields</p>
        <div class="space-y-2" id="attribute-list">
            ${own.length ? own.map(renderFieldRow).join('')
                : `<p class="rounded-[10px] border border-dashed border-border px-3.5 py-6 text-center text-sm text-muted-foreground">
                       No fields yet. Add one and the create form will ask for it.
                   </p>`}
        </div>`;

    $('#catalogue-foot').innerHTML = `
        <button type="button" class="btn btn-primary btn-sm" data-new-attribute
                data-requires-permission="UPDATE:ITEMS">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 5v14M5 12h14"/>
            </svg>
            Add field
        </button>
        <button type="button" class="btn btn-secondary btn-sm" data-edit-category
                data-requires-permission="UPDATE:ITEMS">Edit category</button>
        ${category.is_system ? '' : `
            <button type="button" class="btn btn-ghost btn-sm ml-auto text-rose-600" data-delete-category
                    data-requires-permission="DELETE:ITEMS">Delete</button>`}`;
}

function renderFieldRow(field, index, all) {
    return `
        <div class="rounded-[10px] border border-border px-3.5 py-2.5 ${field.is_active ? '' : 'opacity-60'}">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="truncate text-[0.8125rem] font-medium text-foreground">
                        ${esc(field.label)}
                        ${field.unit_symbol ? `<span class="font-normal text-muted-foreground">(${esc(field.unit_symbol)})</span>` : ''}
                        ${field.is_required ? '<span class="badge badge-muted ml-1">Required</span>' : ''}
                        ${field.is_active ? '' : '<span class="badge badge-muted ml-1">Off</span>'}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        ${esc(field.data_type_label)}
                        ${field.options?.length ? `— ${esc(field.options.join(', '))}` : ''}
                        <span class="font-mono opacity-60">· ${esc(field.key)}</span>
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-0.5">
                    <button type="button" class="btn btn-ghost btn-icon btn-sm" data-move-field="${field.id}"
                            data-direction="up" ${index === 0 ? 'disabled' : ''} aria-label="Move up"
                            data-requires-permission="UPDATE:ITEMS">↑</button>
                    <button type="button" class="btn btn-ghost btn-icon btn-sm" data-move-field="${field.id}"
                            data-direction="down" ${index === all.length - 1 ? 'disabled' : ''} aria-label="Move down"
                            data-requires-permission="UPDATE:ITEMS">↓</button>
                    <button type="button" class="btn btn-ghost btn-icon btn-sm" data-edit-field="${field.id}"
                            aria-label="Edit" data-requires-permission="UPDATE:ITEMS">✎</button>
                    <button type="button" class="btn btn-ghost btn-icon btn-sm text-rose-600" data-delete-field="${field.id}"
                            aria-label="Delete" data-requires-permission="DELETE:ITEMS">✕</button>
                </div>
            </div>
        </div>`;
}

/* -------------------------------------------------------------------------
 | Brands
 | ---------------------------------------------------------------------- */

/**
 * The Brand Master — a flat list, and deliberately.
 *
 * There is no drill-down here because there is nothing under a brand: it carries
 * no fields, no defaults and no children, so edit and delete sit on the row
 * itself the way they do on a unit. The count on the right is how many products
 * carry it, and it is what decides whether delete is offered at all — a brand
 * products carry is archived, never deleted.
 */
function renderBrands() {
    $('#catalogue-body').innerHTML = state.brands.length
        ? `<div class="space-y-1.5">${state.brands.map(renderBrandRow).join('')}</div>`
        : `<p class="rounded-[10px] border border-dashed border-border px-3.5 py-8 text-center text-sm text-muted-foreground">
               No brands yet. Add one and the create form will offer it.
           </p>`;

    $('#catalogue-foot').innerHTML = `
        <button type="button" class="btn btn-primary btn-sm" data-new-brand
                data-requires-permission="UPDATE:ITEMS">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 5v14M5 12h14"/>
            </svg>
            New brand
        </button>
        <p class="ml-auto text-xs text-muted-foreground">A brand in use can be archived, never deleted.</p>`;
}

function renderBrandRow(brand) {
    const used = brand.item_count ?? 0;

    return `
        <div class="flex items-center gap-3 rounded-[10px] border border-border px-3.5 py-2.5 ${brand.is_active ? '' : 'opacity-60'}">
            <div class="min-w-0 flex-1">
                <p class="truncate text-[0.8125rem] font-medium text-foreground">
                    ${esc(brand.name)}
                    ${brand.code ? `<span class="font-mono font-normal text-muted-foreground">${esc(brand.code)}</span>` : ''}
                    ${brand.is_active ? '' : '<span class="badge badge-muted ml-1">Archived</span>'}
                </p>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    ${used ? `On ${used} product${used === 1 ? '' : 's'}` : 'Not used yet'}
                    ${brand.description ? ` · ${esc(brand.description)}` : ''}
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-0.5">
                <button type="button" class="btn btn-ghost btn-icon btn-sm" data-edit-brand="${brand.id}"
                        aria-label="Edit ${esc(brand.name)}" data-requires-permission="UPDATE:ITEMS">✎</button>
                ${used ? '' : `
                    <button type="button" class="btn btn-ghost btn-icon btn-sm text-rose-600" data-delete-brand="${brand.id}"
                            aria-label="Delete ${esc(brand.name)}" data-requires-permission="DELETE:ITEMS">✕</button>`}
            </div>
        </div>`;
}

/* -------------------------------------------------------------------------
 | Units
 | ---------------------------------------------------------------------- */

function renderUnits() {
    const groups = {};

    state.units.forEach((unit) => {
        (groups[unit.kind] ??= []).push(unit);
    });

    const labels = {
        count: 'Counted', weight: 'Weight', length: 'Length', volume: 'Volume',
        time: 'Time', electrical: 'Electrical', other: 'Other',
    };

    $('#catalogue-body').innerHTML = Object.keys(groups).map((kind) => `
        <p class="field-label ${kind === Object.keys(groups)[0] ? '' : 'mt-5'}">${esc(labels[kind] ?? kind)}</p>
        <div class="space-y-1.5">
            ${groups[kind].map(renderUnitRow).join('')}
        </div>`).join('')
        || '<p class="py-8 text-center text-sm text-muted-foreground">No units yet.</p>';

    $('#catalogue-foot').innerHTML = `
        <button type="button" class="btn btn-primary btn-sm" data-new-unit
                data-requires-permission="UPDATE:ITEMS">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 5v14M5 12h14"/>
            </svg>
            New unit
        </button>
        <p class="ml-auto text-xs text-muted-foreground">A unit in use can be switched off, never deleted.</p>`;
}

function renderUnitRow(unit) {
    const used = unit.usage?.total ?? 0;

    return `
        <div class="flex items-center gap-3 rounded-[10px] border border-border px-3.5 py-2.5 ${unit.is_active ? '' : 'opacity-60'}">
            <div class="min-w-0 flex-1">
                <p class="truncate text-[0.8125rem] font-medium text-foreground">
                    ${esc(unit.label)}
                    <span class="font-normal text-muted-foreground">${esc(unit.symbol)}</span>
                    ${unit.is_active ? '' : '<span class="badge badge-muted ml-1">Off</span>'}
                </p>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    ${unit.is_fractional ? `Fractions to ${unit.decimals} places` : 'Whole numbers only'}
                    ${used ? ` · used by ${used}` : ''}
                    ${unit.is_system ? ' · built in' : ''}
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-0.5">
                <button type="button" class="btn btn-ghost btn-icon btn-sm" data-edit-unit="${unit.id}"
                        aria-label="Edit" data-requires-permission="UPDATE:ITEMS">✎</button>
                ${unit.is_system || used ? '' : `
                    <button type="button" class="btn btn-ghost btn-icon btn-sm text-rose-600" data-delete-unit="${unit.id}"
                            aria-label="Delete" data-requires-permission="DELETE:ITEMS">✕</button>`}
            </div>
        </div>`;
}

/* -------------------------------------------------------------------------
 | Forms
 | ---------------------------------------------------------------------- */

function openCategoryForm(category = null) {
    const form = $('#category-form');

    clearFormErrors(form);
    form.reset();

    const editing = category !== null;

    $('#category-modal-title').textContent = editing ? `Edit ${category.name}` : 'New category';
    form.elements.id.value = editing ? category.id : '';

    // The parent picker cannot offer the category itself or anything under it —
    // the server refuses the cycle, and offering it would be offering a choice
    // that can only be refused.
    const forbidden = editing ? descendantIds(category.id) : [];

    $('#category-parent').innerHTML = `<option value="">Top level</option>${
        state.categories
            .filter((candidate) => !forbidden.includes(candidate.id))
            .map((candidate) => `<option value="${candidate.id}">${esc(candidate.name)}</option>`)
            .join('')}`;

    $('#category-unit').innerHTML = `<option value="">—</option>${
        (state.meta?.units ?? [])
            .map((unit) => `<option value="${esc(unit.value)}">${esc(unit.label)} (${esc(unit.symbol)})</option>`)
            .join('')}`;

    if (editing) {
        $('#category-name').value = category.name;
        $('#category-parent').value = category.parent_id ?? '';
        $('#category-description').value = category.description ?? '';
        $('#category-unit').value = category.default_unit_code ?? '';
        $('#category-gst').value = category.default_gst_rate ?? '';
        $('#category-hsn').value = category.default_hsn_sac ?? '';
        $('#category-holds-stock').checked = category.holds_stock;
        $('#category-sac').checked = category.uses_sac_code;
    }

    showModal('#category-modal');
    $('#category-name').focus();
}

function descendantIds(id) {
    const found = [id];
    let frontier = [id];

    while (frontier.length) {
        const next = state.categories
            .filter((category) => frontier.includes(category.parent_id) && !found.includes(category.id))
            .map((category) => category.id);

        if (!next.length) break;

        found.push(...next);
        frontier = next;
    }

    return found;
}

async function submitCategory(event) {
    event.preventDefault();

    const form = event.target;
    const id = form.elements.id.value;

    clearFormErrors(form);

    const body = {
        name: $('#category-name').value.trim(),
        parent_id: $('#category-parent').value ? Number($('#category-parent').value) : null,
        description: $('#category-description').value.trim() || null,
        default_unit_code: $('#category-unit').value || null,
        default_gst_rate: $('#category-gst').value.trim() || null,
        default_hsn_sac: $('#category-hsn').value.trim() || null,
        holds_stock: $('#category-holds-stock').checked,
        uses_sac_code: $('#category-sac').checked,
    };

    setSubmitting(form, true);

    try {
        const saved = id
            ? await auth.call(`/item-categories/${id}`, { method: 'PATCH', body })
            : await auth.call('/item-categories', { method: 'POST', body });

        hideModal('#category-modal');
        toast(saved.message ?? 'Category saved.');

        await refreshAll();

        // Stay where the user was: editing from inside a category should land
        // back inside it, not at the top of the list.
        if (state.open) state.open = state.categories.find((c) => c.id === state.open.id) ?? null;
        else if (!id) state.open = state.categories.find((c) => c.id === saved.data.id) ?? null;

        render();
    } catch (error) {
        showFormErrors(form, error);
    } finally {
        setSubmitting(form, false);
    }
}

function openAttributeForm(field = null) {
    const form = $('#attribute-form');

    clearFormErrors(form);
    form.reset();

    const editing = field !== null;

    $('#attribute-modal-title').textContent = editing ? `Edit ${field.label}` : 'New field';
    form.elements.id.value = editing ? field.id : '';
    form.elements.category_id.value = state.open.id;

    $('#attribute-type').innerHTML = (state.meta?.attribute_types ?? [])
        .map((type) => `<option value="${esc(type.value)}">${esc(type.label)}</option>`)
        .join('');

    $('#attribute-unit').innerHTML = `<option value="">—</option>${
        (state.meta?.units ?? [])
            .map((unit) => `<option value="${esc(unit.value)}">${esc(unit.label)} (${esc(unit.symbol)})</option>`)
            .join('')}`;

    $('#attribute-key-row').classList.toggle('hidden', !editing);

    if (editing) {
        $('#attribute-key').value = field.key;
        $('#attribute-label').value = field.label;
        $('#attribute-type').value = field.data_type;
        $('#attribute-unit').value = field.unit_code ?? '';
        $('#attribute-options').value = (field.options ?? []).join('\n');
        $('#attribute-min').value = field.min_value ?? '';
        $('#attribute-max').value = field.max_value ?? '';
        $('#attribute-default').value = field.default_value ?? '';
        $('#attribute-help').value = field.help_text ?? '';
        $('#attribute-required').checked = field.is_required;
    }

    applyAttributeType();
    showModal('#attribute-modal');
    $('#attribute-label').focus();
}

/**
 * Show only the settings the chosen kind of value actually has.
 *
 * A dropdown has choices and no range; a number has a range and no choices; a
 * yes/no and a date have neither and take no unit. Leaving them all on screen
 * would be four boxes that silently do nothing.
 */
function applyAttributeType() {
    const chosen = $('#attribute-type').value;
    const meta = (state.meta?.attribute_types ?? []).find((type) => type.value === chosen);

    if (!meta) return;

    $('#attribute-type-hint').textContent = meta.hint;
    $$('[data-attribute-options]').forEach((node) => node.classList.toggle('hidden', !meta.has_options));
    $$('[data-attribute-unit]').forEach((node) => node.classList.toggle('hidden', !meta.accepts_unit));
    $$('[data-attribute-range]').forEach((node) => {
        node.classList.toggle('hidden', !meta.accepts_range);
        node.classList.toggle('grid', meta.accepts_range);
    });
}

async function submitAttribute(event) {
    event.preventDefault();

    const form = event.target;
    const id = form.elements.id.value;
    const categoryId = form.elements.category_id.value;

    clearFormErrors(form);

    const body = {
        label: $('#attribute-label').value.trim(),
        data_type: $('#attribute-type').value,
        unit_code: $('#attribute-unit').value || null,
        options: $('#attribute-options').value.split('\n').map((line) => line.trim()).filter(Boolean),
        min_value: $('#attribute-min').value.trim() || null,
        max_value: $('#attribute-max').value.trim() || null,
        default_value: $('#attribute-default').value.trim() || null,
        help_text: $('#attribute-help').value.trim() || null,
        is_required: $('#attribute-required').checked,
    };

    setSubmitting(form, true);

    try {
        const saved = id
            ? await auth.call(`/item-categories/${categoryId}/attributes/${id}`, { method: 'PATCH', body })
            : await auth.call(`/item-categories/${categoryId}/attributes`, { method: 'POST', body });

        hideModal('#attribute-modal');
        toast(saved.message ?? 'Field saved.');

        await refreshAll();
        state.open = state.categories.find((c) => c.id === Number(categoryId)) ?? null;
        render();
    } catch (error) {
        showFormErrors(form, error);
    } finally {
        setSubmitting(form, false);
    }
}

/**
 * Create or edit a brand.
 *
 * The archive control is on the edit only: a brand being created is by
 * definition one the shop wants offered, and a checkbox saying so on a new
 * record is a question with one sensible answer.
 */
function openBrandForm(brand = null) {
    const form = $('#brand-form');

    clearFormErrors(form);
    form.reset();

    const editing = brand !== null;

    $('#brand-modal-title').textContent = editing ? `Edit ${brand.name}` : 'New brand';
    form.elements.id.value = editing ? brand.id : '';
    $('#brand-active-row').classList.toggle('hidden', !editing);

    if (editing) {
        $('#brand-name').value = brand.name;
        $('#brand-code').value = brand.code ?? '';
        $('#brand-description').value = brand.description ?? '';
        $('#brand-active').checked = brand.is_active;
    }

    showModal('#brand-modal');
    $('#brand-name').focus();
}

async function submitBrand(event) {
    event.preventDefault();

    const form = event.target;
    const id = form.elements.id.value;

    clearFormErrors(form);

    const body = {
        name: $('#brand-name').value.trim(),
        code: $('#brand-code').value.trim() || null,
        description: $('#brand-description').value.trim() || null,
    };

    // Only on an edit — see openBrandForm(). Sending it on a create would be
    // sending the default back as though somebody had chosen it.
    if (id) body.is_active = $('#brand-active').checked;

    setSubmitting(form, true);

    try {
        const saved = id
            ? await auth.call(`/item-brands/${id}`, { method: 'PATCH', body })
            : await auth.call('/item-brands', { method: 'POST', body });

        hideModal('#brand-modal');
        toast(saved.message ?? 'Brand saved.');

        // `refreshAll` reloads /items/meta too, which is what makes a brand
        // created here appear in the create form's dropdown without a reload
        // (§3.2). Saying which brand it was lets the form go one better and
        // select it — somebody who opened this from "Add brand" was answering
        // that field, not administering a list.
        await refreshAll({
            resource: 'brand',
            action: id ? 'updated' : 'created',
            id: saved.data?.id ?? Number(id) ?? null,
        });
        state.tab = 'brands';
        render();
    } catch (error) {
        showFormErrors(form, error);
    } finally {
        setSubmitting(form, false);
    }
}

function openUnitForm(unit = null) {
    const form = $('#unit-form');

    clearFormErrors(form);
    form.reset();

    const editing = unit !== null;

    $('#unit-modal-title').textContent = editing ? `Edit ${unit.label}` : 'New unit';
    form.elements.id.value = editing ? unit.id : '';
    $('#unit-code-row').classList.toggle('hidden', !editing);

    if (editing) {
        $('#unit-code').value = unit.code;
        $('#unit-label').value = unit.label;
        $('#unit-symbol').value = unit.symbol;
        $('#unit-kind').value = unit.kind;
        $('#unit-decimals').value = String(unit.decimals);
    }

    showModal('#unit-modal');
    $('#unit-label').focus();
}

async function submitUnit(event) {
    event.preventDefault();

    const form = event.target;
    const id = form.elements.id.value;

    clearFormErrors(form);

    const body = {
        label: $('#unit-label').value.trim(),
        symbol: $('#unit-symbol').value.trim() || null,
        kind: $('#unit-kind').value,
        decimals: Number($('#unit-decimals').value),
    };

    setSubmitting(form, true);

    try {
        const saved = id
            ? await auth.call(`/units/${id}`, { method: 'PATCH', body })
            : await auth.call('/units', { method: 'POST', body });

        hideModal('#unit-modal');
        toast(saved.message ?? 'Unit saved.');

        await refreshAll();
        render();
    } catch (error) {
        showFormErrors(form, error);
    } finally {
        setSubmitting(form, false);
    }
}

/* -------------------------------------------------------------------------
 | Deletes
 | ---------------------------------------------------------------------- */

async function deleteCategory(category) {
    const count = category.item_count ?? 0;

    const confirmed = await confirmAction({
        title: `Delete ${category.name}?`,
        body: count
            ? `${count} product${count === 1 ? ' is' : 's are'} filed under it, so this will be refused — `
              + 'archive it instead to take it off the create form.'
            : 'It has no products under it, so nothing will lose the template that explains it.',
        confirmLabel: 'Delete',
    });

    if (!confirmed) return;

    try {
        await auth.call(`/item-categories/${category.id}`, { method: 'DELETE' });
        toast('Category deleted.');

        state.open = null;
        await refreshAll();
        render();
    } catch (error) {
        toast(error?.message ?? 'Could not delete the category.', 'error');
    }
}

async function deleteField(field) {
    const confirmed = await confirmAction({
        title: `Delete ${field.label}?`,
        body: 'Products that already answered it keep their value, but nothing will explain what it means. '
            + 'Switching the field off instead keeps the explanation.',
        confirmLabel: 'Delete',
    });

    if (!confirmed) return;

    try {
        await auth.call(`/item-categories/${state.open.id}/attributes/${field.id}`, { method: 'DELETE' });
        toast('Field deleted.');

        const id = state.open.id;

        await refreshAll();
        state.open = state.categories.find((c) => c.id === id) ?? null;
        render();
    } catch (error) {
        toast(error?.message ?? 'Could not delete the field.', 'error');
    }
}

async function deleteBrand(brand) {
    const count = brand.item_count ?? 0;

    const confirmed = await confirmAction({
        title: `Delete ${brand.name}?`,
        body: count
            ? `${count} product${count === 1 ? '' : 's'} carry it, so this will be refused — `
              + 'archive it instead to take it off the create form.'
            : 'No product carries it, so nothing will lose the name that says whose it is.',
        confirmLabel: 'Delete',
    });

    if (!confirmed) return;

    try {
        await auth.call(`/item-brands/${brand.id}`, { method: 'DELETE' });
        toast('Brand deleted.');

        await refreshAll();
        render();
    } catch (error) {
        toast(error?.message ?? 'Could not delete the brand.', 'error');
    }
}

async function deleteUnit(unit) {
    const confirmed = await confirmAction({
        title: `Delete ${unit.label}?`,
        body: 'Nothing is counted in it, so nothing will lose the word that explains its quantities.',
        confirmLabel: 'Delete',
    });

    if (!confirmed) return;

    try {
        await auth.call(`/units/${unit.id}`, { method: 'DELETE' });
        toast('Unit deleted.');

        await refreshAll();
        render();
    } catch (error) {
        toast(error?.message ?? 'Could not delete the unit.', 'error');
    }
}

/* -------------------------------------------------------------------------
 | Wiring
 | ---------------------------------------------------------------------- */

/**
 * Refetch everything, and tell the Items screen its vocabulary moved.
 *
 * The create form is built from `/items/meta`, so a category added here has to
 * invalidate it — otherwise the dropdown keeps offering yesterday's list until
 * the page is reloaded, which §3.2 forbids anyway.
 */
async function refreshAll(change = null) {
    state.meta = null;

    await loadMeta();
    await Promise.all([loadCategories(), loadBrands(), loadUnits()]);

    await state.onChange?.(change);
}

function fieldById(id) {
    return (state.open?.attributes ?? []).find((field) => field.id === Number(id)) ?? null;
}

function brandById(id) {
    return state.brands.find((brand) => brand.id === Number(id)) ?? null;
}

/**
 * Move one field up or down, and save the whole order.
 *
 * The order is what the universal form draws them in, and it matters more than
 * it looks: a specification reads the way somebody reciting it would say it —
 * 5 HP, 3 phase, 1440 RPM — and an alphabetical form makes every product take a
 * moment longer to read.
 */
async function moveField(id, direction) {
    const fields = [...(state.open.attributes ?? [])];
    const index = fields.findIndex((field) => field.id === Number(id));
    const target = direction === 'up' ? index - 1 : index + 1;

    if (index < 0 || target < 0 || target >= fields.length) return;

    [fields[index], fields[target]] = [fields[target], fields[index]];

    const categoryId = state.open.id;

    try {
        await auth.call(`/item-categories/${categoryId}/attributes/order`, {
            method: 'PUT',
            body: { ids: fields.map((field) => field.id) },
        });

        await refreshAll();
        state.open = state.categories.find((c) => c.id === categoryId) ?? null;
        render();
    } catch (error) {
        toast(error?.message ?? 'Could not save the order.', 'error');
    }
}

async function applyTemplate(name) {
    try {
        const saved = await auth.call('/item-categories/templates', {
            method: 'POST',
            body: { name },
        });

        toast(saved.message ?? 'Category created.');

        await refreshAll();
        state.open = state.categories.find((c) => c.id === saved.data.id) ?? null;
        render();
    } catch (error) {
        toast(error?.message ?? 'Could not apply the template.', 'error');
    }
}

let wired = false;

/**
 * Bind the drawer once.
 *
 * Delegated from the drawer root rather than bound per row, because the body is
 * re-rendered on every change and per-row handlers would be re-attached — or
 * worse, leak — each time.
 */
export function initCatalogueMaster() {
    if (wired) return;

    wired = true;

    const drawer = $('#catalogue-drawer');

    if (!drawer) return;

    drawer.addEventListener('click', async (event) => {
        const target = event.target.closest('[data-catalogue-tab], [data-open-category], [data-new-category], '
            + '[data-edit-category], [data-delete-category], [data-new-attribute], [data-edit-field], '
            + '[data-delete-field], [data-move-field], [data-new-unit], [data-edit-unit], [data-delete-unit], '
            + '[data-new-brand], [data-edit-brand], [data-delete-brand], '
            + '[data-template], #catalogue-back');

        if (!target || target.disabled) return;

        const data = target.dataset;

        if (target.id === 'catalogue-back') {
            state.open = null;
            render();

            return;
        }

        if (data.catalogueTab) {
            state.tab = data.catalogueTab;
            state.open = null;
            render();

            return;
        }

        if (data.openCategory) {
            state.open = state.categories.find((c) => c.id === Number(data.openCategory)) ?? null;
            render();

            return;
        }

        if ('newCategory' in data) return openCategoryForm();
        if ('editCategory' in data) return openCategoryForm(state.open);
        if ('deleteCategory' in data) return deleteCategory(state.open);
        if ('newAttribute' in data) return openAttributeForm();
        if ('newUnit' in data) return openUnitForm();
        if ('newBrand' in data) return openBrandForm();

        if (data.template) return applyTemplate(data.template);
        if (data.editField) return openAttributeForm(fieldById(data.editField));
        if (data.deleteField) return deleteField(fieldById(data.deleteField));
        if (data.moveField) return moveField(data.moveField, data.direction);

        if (data.editUnit) {
            return openUnitForm(state.units.find((unit) => unit.id === Number(data.editUnit)) ?? null);
        }

        if (data.deleteUnit) {
            return deleteUnit(state.units.find((unit) => unit.id === Number(data.deleteUnit)) ?? null);
        }

        if (data.editBrand) {
            return openBrandForm(brandById(data.editBrand));
        }

        if (data.deleteBrand) {
            return deleteBrand(brandById(data.deleteBrand));
        }

        return undefined;
    });

    $('#category-form').addEventListener('submit', submitCategory);
    $('#brand-form').addEventListener('submit', submitBrand);
    $('#attribute-form').addEventListener('submit', submitAttribute);
    $('#unit-form').addEventListener('submit', submitUnit);
    $('#attribute-type').addEventListener('change', applyAttributeType);
}
