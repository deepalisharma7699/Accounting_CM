/**
 * Add to the catalogue without leaving the bill — the brief's §5.
 *
 * Lifted out of `pages/bills.js` in M20 and otherwise unchanged, because it was
 * already the right shape: it posts to the same two endpoints the Items screen
 * uses, so what it creates is a catalogue item like any other rather than
 * something that only exists on one bill. What it gained is a second and third
 * caller — the bill counter and the job screen — which is the whole reason a
 * flow this good should not have been living inside one page module.
 *
 * The markup is `resources/views/partials/quick-item-modal.blade.php`, included
 * by whichever page wants it. This module attaches to whatever is on the page
 * and does nothing at all when the partial is absent.
 */

import auth from '../auth-client';
import { $, $$, clearFormErrors, esc, hideModal, setSubmitting, showFormErrors, showModal, toast } from '../ui';

const state = {
    meta: null,
    // Which line asked, and — once the family exists — which family the
    // specification is being added to. Held here rather than on the modal so a
    // failed variant post cannot end up creating the item twice.
    context: null,
    itemId: null,
    existing: false,
    onCreated: () => {},
};

/**
 * The vocabulary of the catalogue, fetched once and late.
 *
 * Which fields describe a motor is the server's answer — a copy of that schema
 * here would drift, and the drift shows up as a motor saved without its rating,
 * which nobody can recover afterwards.
 */
async function ensureMeta() {
    if (state.meta) return state.meta;

    try {
        const { data } = await auth.call('/items/meta');
        state.meta = data;
    } catch {
        state.meta = { categories: [], units: [] };
    }

    paintVocabularySelects();

    return state.meta;
}

function typeMeta(value) {
    if (value === '' || value === null || value === undefined) return null;

    // Matched on the category id, sent as a string because that is what a
    // <select> value always is.
    const list = state.meta?.categories ?? [];

    return list.find((category) => String(category.value) === String(value)) ?? null;
}

/**
 * Fill the category and unit selects from the server's answer.
 *
 * Both are tables an admin edits, so rendering them into the Blade partial would
 * be a copy that goes stale the moment one is added.
 */
function paintVocabularySelects() {
    const categories = state.meta?.categories ?? [];
    const units = state.meta?.units ?? [];

    const typeSelect = $('#quick-type');
    const unitSelect = $('#quick-uom');

    if (typeSelect) {
        typeSelect.innerHTML = categories.length
            ? categories.map((c) => `<option value="${c.value}">${c.label}</option>`).join('')
            : '<option value="">No categories yet</option>';
    }

    if (unitSelect) {
        unitSelect.innerHTML = units
            .map((u) => `<option value="${u.value}">${u.label} (${u.symbol})</option>`)
            .join('');
    }
}

/**
 * Reflect the chosen type into the rest of the form.
 *
 * The type decides which word the tax code goes by, which unit it is counted in,
 * whether stock is possible at all, and which specification fields appear.
 * Saying so as it is picked beats refusing the save afterwards.
 */
function applyType() {
    const type = typeMeta($('#quick-type').value);

    if (!type) return;

    $('#quick-hsn-label').textContent = `${type.tax_code_label} code`;
    $('#quick-uom').value = type.default_uom;
    $('#quick-type-hint').textContent = type.description || `Asks for ${describeAttributes(type)}.`;

    /*
    | The category's rate, copied in and said out loud — the same treatment the
    | Items form gives it. An item quick-added from a bill picker used to be
    | created at 0% GST whatever its category charged, and the first sign of it
    | was a purchase line taxed at nothing.
    */
    const gst = $('#quick-gst');

    if (!gst.value.trim() && type.default_gst_rate !== null) gst.value = type.default_gst_rate;

    $('#quick-gst-hint').textContent = type.default_gst_rate === null
        ? `${type.label} has no default rate — enter one, or 0 if this is exempt.`
        : `${type.default_gst_rate}% from ${type.label}. Change it if this item differs.`;

    const checkbox = $('#quick-stock');

    checkbox.disabled = !type.can_hold_stock;
    if (!type.can_hold_stock) checkbox.checked = false;

    $('#quick-stock-hint').textContent = type.can_hold_stock
        ? 'Turn this off for something you buy to order and never hold.'
        : 'A service cannot be held in stock — an hour is produced when it is sold.';

    renderAttributes(type);
}

function describeAttributes(type) {
    const keys = Object.keys(type.attributes ?? {});

    return keys.length
        ? keys.map((key) => type.attributes[key].label.toLowerCase()).join(', ')
        : 'nothing — a service has no variations';
}

/** A select where the values are genuinely fixed, a text box where they are not. */
function renderAttributes(type) {
    const schema = type?.attributes ?? {};
    const keys = Object.keys(schema);
    const host = $('#quick-item-attributes');

    $('#quick-variant-hint').textContent = keys.length
        ? 'Stock is counted per specification, so the bill needs the exact one.'
        : 'Nothing to describe — this goes on the bill as it stands.';

    host.innerHTML = keys.map((key) => {
        const field = schema[key];
        const suffix = field.suffix
            ? ` <span class="font-normal text-muted-foreground">(${esc(field.suffix)})</span>`
            : '';

        const input = field.values
            ? `<select class="field-input" data-attribute="${esc(key)}">
                   <option value="">Choose…</option>
                   ${field.values.map((option) =>
                       `<option value="${esc(option)}">${esc(option)}</option>`).join('')}
               </select>`
            : `<input type="text" class="field-input" data-attribute="${esc(key)}" autocomplete="off">`;

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

    $$('[data-attribute]', $('#quick-item-attributes')).forEach((input) => {
        const value = input.value.trim();

        // Blank is absent, not "". A form submits every field it renders, and an
        // untouched box stored as an empty string is noise every later reader has
        // to filter out.
        if (value !== '') bag[input.dataset.attribute] = value;
    });

    return bag;
}

/** Hidden, not disabled: once the family exists its fields are answered. */
function showFamilyFields(visible) {
    $('#quick-item-fields').classList.toggle('hidden', !visible);
    $('#quick-item-fields').classList.toggle('grid', visible);
}

/**
 * Open the catalogue form.
 *
 * Two ways in, one form. With no item it creates a family and its first
 * specification; with one — reached by picking an item that has no variants yet —
 * it adds only the specification, and the family's own fields are hidden because
 * they are already settled.
 *
 * @param {object} options
 * @param {object|null} [options.item]     An existing family needing a specification.
 * @param {*} [options.context]            Passed back to onCreated, so a caller
 *                                         knows which line asked.
 * @param {(created: object, context: *) => void} [options.onCreated]
 */
export async function openQuickItem({ item = null, context = null, onCreated = () => {} } = {}) {
    if (!$('#quick-item-modal')) {
        toast('This screen cannot create catalogue items.', 'error');

        return;
    }

    await ensureMeta();

    const form = $('#quick-item-form');

    clearFormErrors(form);
    form.reset();

    state.context = context;
    state.itemId = item?.id ?? null;
    state.existing = item !== null;
    state.onCreated = onCreated;

    showFamilyFields(item === null);

    if (item) {
        $('#quick-item-title').textContent = `Which ${item.name}?`;
        $('#quick-item-subtitle').textContent =
            'The family is in your catalogue but nothing says which one is on the shelf. '
            + 'Stock is counted per specification, so a bill needs the exact one.';

        renderAttributes(typeMeta(String(item.category_id ?? '')));
    } else {
        $('#quick-item-title').textContent = 'New item';
        $('#quick-item-subtitle').textContent =
            'Added to your catalogue, so it appears on Items too — not just on this bill.';

        const categories = state.meta?.categories ?? [];
        $('#quick-type').value = categories[0]?.value ?? '';
        applyType();
    }

    showModal('#quick-item-modal');
}

/**
 * Create the item, then its first specification.
 *
 * Two requests, because they are two resources — the same two the Items screen
 * posts, which is what makes this a catalogue item rather than something that
 * only exists on a bill. The item's id is kept the moment it comes back: if the
 * specification is refused, the retry adds one to the family that now exists
 * instead of creating a second family with the same name.
 */
async function submit(event) {
    event.preventDefault();

    const form = event.target;

    clearFormErrors(form);

    /*
    | A rate nobody has stated, on a category that cannot state one for them —
    | the same refusal the Items form makes, for the same reason: 0% applies
    | silently to every line the item ever appears on.
    */
    if (state.itemId === null
        && !$('#quick-gst').value.trim()
        && typeMeta($('#quick-type').value)?.default_gst_rate === null) {
        showFormErrors(form, {
            fields: { gst_rate: ['Enter a GST rate — 0 if this is exempt.'] },
            message: 'This category has no default GST rate, so this item needs one of its own.',
        });

        return;
    }

    setSubmitting(form, true, 'Saving…');

    try {
        if (state.itemId === null) {
            const created = await auth.call('/items', {
                method: 'POST',
                body: {
                    name: $('#quick-name').value.trim(),
                    category_id: Number($('#quick-type').value),
                    hsn_sac: $('#quick-hsn').value.trim() || null,
                    // Null rather than '0', so the server can fall back to the
                    // category's own rate — see the note in pages/items.js.
                    gst_rate: $('#quick-gst').value.trim() || null,
                    base_uom: $('#quick-uom').value,
                    is_stock: $('#quick-stock').checked,
                },
            });

            state.itemId = created.data.id;
            showFamilyFields(false);
        }

        const response = await auth.call(`/items/${state.itemId}/variants`, {
            method: 'POST',
            body: {
                attributes: collectAttributes(),
                sku: $('#quick-sku').value.trim() || null,
                sell_price: $('#quick-price').value.trim() || null,
            },
        });

        hideModal('#quick-item-modal');
        toast(state.existing ? 'Specification added.' : 'Item added to your catalogue.');

        // A second specification matching one that already exists is saved and
        // reported, never refused: two brands at one rating is a real
        // arrangement, and the commoner cause is the same thing entered twice.
        (response.meta?.warnings ?? []).forEach((warning) => toast(warning.message, 'info'));

        state.onCreated(response.data, state.context);
    } catch (error) {
        showFormErrors(form, error);
    } finally {
        setSubmitting(form, false);
    }
}

/**
 * Close without saving.
 *
 * The caller is still told where the family was created before the specification
 * failed — that item exists now, and a picker that did not know about it would
 * invite somebody to create it a second time.
 */
function cancel() {
    const orphaned = state.itemId !== null && !state.existing;

    hideModal('#quick-item-modal');

    const { onCreated, context } = state;

    state.context = null;
    state.itemId = null;
    state.existing = false;

    if (orphaned) onCreated(null, context);
}

/**
 * Wire the shared modal, once per page. Safe to call where the partial is not
 * included — it simply does nothing.
 */
export function initQuickItem() {
    const modal = $('#quick-item-modal');

    if (!modal) return;

    $('#quick-item-form').addEventListener('submit', submit);
    $('#quick-type').addEventListener('change', applyType);

    // Its own close handler rather than data-modal-close: cancelling may still
    // have left a new family in the catalogue.
    modal.addEventListener('click', (event) => {
        if (event.target.closest('[data-quick-cancel]') || event.target.matches('[data-modal]')) {
            cancel();
        }
    });

    // Escape, taken in the capture phase and stopped there, so the shell's own
    // handler does not go on to close whatever is open underneath as well.
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        if (modal.classList.contains('hidden')) return;

        event.stopPropagation();
        cancel();
    }, true);
}
