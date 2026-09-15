import { $$, esc } from '../ui';

/**
 * The specification fields a category asks for, drawn from the server's own
 * schema.
 *
 * ## Why this is a component and not a page's private helper
 *
 * Because two screens ask the same question about the same vocabulary. The Items
 * create form asks a *product* what its category records — HP, phase, speed —
 * and the Jobs intake form asks the same of the *thing on the bench*, which is a
 * motor most days and a cooler or a fan the rest of the time. Both read
 * `item_categories` / `item_attributes` through an `attributes` map their server
 * published; a second renderer would be a second place the data types, the
 * three-state boolean and the "blank is absent" rule are decided (§4.4, §5.1).
 *
 * **Nothing here is written per category.** The control drawn for each field
 * comes from its declared data type, and the labels, units, options and bounds
 * all come from the payload. Adding "Lumens" to a category is a change to rows
 * in `item_attributes` and to nothing in this file, which is the catalogue
 * module's whole acceptance criterion.
 *
 * The two screens differ in one thing only, and it is not drawn here: what a
 * missing answer means. A product of a category that demands a rating cannot be
 * saved without one; a motor whose plate nobody could read is still on the
 * bench, so the bench passes `requiredMarks: false` and insists on nothing.
 */
const EMPTY_MESSAGE =
    'This category has no specification fields. Add them under Categories if it should have any.';

/**
 * Build the specification inputs from the server's schema.
 *
 * A select where the values are genuinely fixed, a date picker for a date, a
 * numeric box for a rating — decided by the field's declared data type and by
 * nothing else.
 *
 * @param {HTMLElement} host   Where to draw them — a variant block or the variant dialog.
 * @param {object} schema      The category's resolved question set, keyed by field.
 * @param {object} values      Current values, keyed by field.
 * @param {string} prefix      What to build the input ids from. The create form
 *                             draws this set once per variant block, so a fixed
 *                             `attr-hp` would be an id repeated down the page and
 *                             a `<label for>` pointing into somebody else's block.
 * @param {object} options     `empty`, what to say where the category asks nothing;
 *                             `requiredMarks`, whether a field may be labelled
 *                             compulsory — false where the host will accept a
 *                             blank whatever the schema says.
 */
export function renderAttributeFields(host, schema = {}, values = {}, prefix = 'attr', options = {}) {
    const { empty = EMPTY_MESSAGE, requiredMarks = true } = options;

    const keys = Object.keys(schema);

    if (!keys.length) {
        host.innerHTML = `
            <p class="sm:col-span-2 rounded-[10px] border border-border bg-secondary/40 px-3.5 py-2.5
                      text-[0.8125rem] text-secondary-foreground">${esc(empty)}</p>`;

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
                <label class="field-label" for="${esc(prefix)}-${esc(key)}">
                    ${esc(field.label)}${suffix}
                    ${requiredMarks && field.required
                        ? ''
                        : '<span class="font-normal text-muted-foreground">(optional)</span>'}
                </label>
                ${attributeInput(key, field, value, prefix)}
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
export function attributeInput(key, field, value, prefix = 'attr') {
    const id = `${esc(prefix)}-${esc(key)}`;
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

export function collectAttributes(host) {
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

/**
 * The pre-filled values a schema's fields declare.
 *
 * Applied only where a record is being created. Filling defaults into an edit
 * form would quietly rewrite something that had deliberately been left blank.
 */
export function defaultsFor(schema = {}) {
    const values = {};

    Object.keys(schema).forEach((key) => {
        if (schema[key].default !== undefined) values[key] = schema[key].default;
    });

    return values;
}

/** "rating, phase, speed" — what a category asks about, in one line. */
export function describeAttributes(schema = {}, none = 'no specification fields yet') {
    const keys = Object.keys(schema);

    return keys.length
        ? keys.map((key) => (schema[key].label ?? key).toLowerCase()).join(', ')
        : none;
}
