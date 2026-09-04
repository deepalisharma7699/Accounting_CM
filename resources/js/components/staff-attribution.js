import { $$, esc } from '../ui';

/**
 * Who did the work — M22, wherever it is asked or shown.
 *
 * A row of pickers, one per trade the workshop records on a sale: "Fitter:
 * Ramesh, Winder: Sunil". The sale form asks it while the invoice is being
 * written, and the invoice drawer asks it again when somebody notices the wrong
 * name on a posted one.
 *
 * ## Why this is a component and not two bits of markup
 *
 * Because those two surfaces are the same question, and the parts that go wrong
 * in a second copy are not the obvious ones. Both have to keep a *departed*
 * employee selectable on an old document, both have to keep a slot the workshop
 * has since stopped tracking, and both have to send an emptied box as `null`
 * rather than dropping it — because dropping it is how a mis-picked name becomes
 * permanent. Retyping the `<select>` gets the first case wrong and looks right.
 *
 * ## The vocabulary is data
 *
 * Nothing here knows what a fitter is. The slots arrive from
 * `GET /transactions/meta` — `staff_slots`, built from the designations the
 * workshop ticked — and a workshop that tracks Varnishers gets three boxes
 * without a line of this file changing. Writing "Fitter" and "Winder" into a
 * template is the hard-coded vocabulary the catalogue module was rebuilt to
 * remove, and CLAUDE.md forbids it in as many words.
 *
 * ## Absent, empty and unasked are three different things
 *
 *   no slots          the workshop records none of this. Paint nothing at all —
 *                     not an empty panel, and not a heading over nothing.
 *   slot, no name     the box is on screen and empty. Sent as null, which is
 *                     what lets a correction *remove* a name.
 *   `staff` absent    nobody asked the server for it. A reader must not be told
 *                     "not recorded", which is a different claim.
 */

/**
 * The pickers, mounted into a host element.
 *
 * @param {HTMLElement} host
 * @param {object}   options
 * @param {Array}    options.slots     from `staff_slots` — `{designation_id, designation, employees[]}`
 * @param {Array}    options.value     current pairs — `{designation_id, designation, employee_id, employee}`
 * @param {Function} options.onChange  called after any pick, for the autosaved draft
 * @param {string}   options.hint      the line under the boxes, or '' for none
 */
export function mountStaffAttribution(host, {
    slots = [],
    value = [],
    onChange = () => {},
    hint = 'Optional. It records who did the job, and never appears on the customer’s invoice.',
} = {}) {
    /** designation_id -> employee_id, the live state. Ids are numbers throughout. */
    let picked = new Map();

    /*
    | The slots actually painted, which is not always the ones that were passed.
    |
    | A document can carry a trade the workshop has since un-ticked or archived —
    | last year's Varnisher. Dropping it would hide a name that is on the record
    | and, worse, would silently clear it on the next save, because a slot that
    | is not painted contributes nothing to `value()`. So anything already on the
    | document is merged in, after the live ones.
    */
    let painted = [];

    function reconcile() {
        const bySlot = new Map(
            slots.map((slot) => [Number(slot.designation_id), {
                designation_id: Number(slot.designation_id),
                designation: slot.designation,
                employees: slot.employees ?? [],
            }]),
        );

        (value ?? []).forEach((pair) => {
            const id = Number(pair.designation_id);

            if (bySlot.has(id)) return;

            bySlot.set(id, {
                designation_id: id,
                // The name off the document, because the slot list no longer
                // carries one — the alternative is a box labelled "undefined".
                designation: pair.designation ?? 'Other',
                employees: [],
                retired: true,
            });
        });

        painted = Array.from(bySlot.values());

        picked = new Map();

        (value ?? []).forEach((pair) => {
            if (pair.employee_id) picked.set(Number(pair.designation_id), Number(pair.employee_id));
        });
    }

    /**
     * The options for one box.
     *
     * The chosen employee is added to the list when the roster does not hold
     * them, which is the departed-staff case: the roster is active staff only,
     * and correcting an invoice from three months ago must not silently blank
     * the person who did the work. They are marked so nobody picks them by
     * accident on a new document.
     */
    function optionsFor(slot) {
        const chosen = picked.get(slot.designation_id) ?? null;
        const roster = (slot.employees ?? []).map((employee) => ({
            id: Number(employee.id),
            name: employee.name,
            gone: false,
        }));

        if (chosen !== null && !roster.some((employee) => employee.id === chosen)) {
            const named = (value ?? []).find(
                (pair) => Number(pair.designation_id) === slot.designation_id,
            );

            roster.unshift({
                id: chosen,
                name: named?.employee ?? 'Someone who has left',
                gone: true,
            });
        }

        return [
            `<option value="">— nobody recorded —</option>`,
            ...roster.map((employee) => `
                <option value="${employee.id}" ${employee.id === chosen ? 'selected' : ''}>
                    ${esc(employee.name)}${employee.gone ? ' (no longer on the staff list)' : ''}
                </option>
            `),
        ].join('');
    }

    function render() {
        // Nothing ticked, nothing on the document: the whole section is absent
        // rather than empty. A workshop that does not track this never learns
        // that it could have.
        if (painted.length === 0) {
            host.innerHTML = '';
            host.classList.add('hidden');

            return;
        }

        host.classList.remove('hidden');

        host.innerHTML = `
            <div class="grid gap-3 sm:grid-cols-2">
                ${painted.map((slot) => `
                    <label class="field">
                        <span class="field-label">
                            ${esc(slot.designation)}
                            <span class="font-normal text-muted-foreground">(optional)</span>
                        </span>

                        <select class="field-input" data-staff-slot="${slot.designation_id}">
                            ${optionsFor(slot)}
                        </select>
                    </label>
                `).join('')}
            </div>

            ${hint ? `<p class="mt-1.5 text-xs text-muted-foreground">${esc(hint)}</p>` : ''}

            <p class="field-error hidden" data-error-for="staff"></p>
        `;

        $$('[data-staff-slot]', host).forEach((select) => {
            select.addEventListener('change', () => {
                const designationId = Number(select.dataset.staffSlot);

                if (select.value === '') {
                    picked.delete(designationId);
                } else {
                    picked.set(designationId, Number(select.value));
                }

                onChange();
            });
        });
    }

    reconcile();
    render();

    return {
        /**
         * Every painted slot, including the empty ones.
         *
         * The empty ones are the point: `{designation_id, employee_id: null}` is
         * what removes a name, and omitting them would make a correction able to
         * replace but never to clear. See UpdateTransactionStaffRequest.
         */
        value: () => painted.map((slot) => ({
            designation_id: slot.designation_id,
            employee_id: picked.get(slot.designation_id) ?? null,
        })),

        /** Put a document's attribution into the boxes. */
        set(pairs) {
            value = pairs ?? [];
            reconcile();
            render();
        },

        /** Empty every box, for the next document (§2A.8). */
        clear() {
            value = [];
            reconcile();
            render();
        },
    };
}

/**
 * "Fitter · Ramesh, Winder · Sunil" — the read-only line on a drawer or a row.
 *
 * Null where nobody asked. That is not the same as nobody being recorded, and a
 * caller must be able to tell them apart: a listing that did not request the
 * attribution should print nothing, where a document that genuinely has none has
 * something worth saying. See TransactionResource, which omits the key entirely
 * in the first case.
 */
export function describeAttribution(staff) {
    if (!Array.isArray(staff)) return null;

    const named = staff.filter((pair) => pair.employee_id && pair.employee);

    if (named.length === 0) return { recorded: false, text: 'Not recorded' };

    return {
        recorded: true,
        text: named.map((pair) => `${pair.designation ?? 'Staff'} · ${pair.employee}`).join(', '),
    };
}
