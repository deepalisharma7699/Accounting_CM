/**
 * Adding or editing a counterparty, wherever somebody is standing — §4.
 *
 * The behaviour behind `partials/quick-party-modal.blade.php`, and the one
 * implementation of "write a customer or a vendor to the books". Before this it
 * was two: a create-only drawer on the bill counter, and a create-or-edit modal
 * on the Customers and Vendors screens. The two had drifted exactly as far as
 * you would expect — the counterparty copy validated the name length, the role
 * and the GSTIN shape before spending a round trip; the counter's copy validated
 * nothing and had no role checkboxes at all — and that drift is the whole reason
 * for §5.1.
 *
 * ## Two shapes, one form
 *
 * `full: false` is the quick add from a picker. Somebody is mid-purchase, the
 * supplier is not on the books, and the four fields a bill actually needs are
 * the four fields they are shown. The role is not asked: the document already
 * said whether it wanted a customer or a vendor, and offering both would invite
 * a supplier relationship nobody has had.
 *
 * `full: true` is the record screen's form, which also edits. It adds the role
 * checkboxes — so the shop that both buys and sells is one record rather than
 * two halves of a balance that never meet — plus email, notes, and the line
 * saying where opening balances actually belong.
 *
 * Editing is always full. A form that hid half a record's fields while claiming
 * to edit it would silently be a form that could not put them back.
 *
 * ## Two frames, one node
 *
 * Where it is mounted is a separate question from which shape it wears. Pass a
 * `host` and the form is moved into that level-1 slot with its inline footer —
 * which is how a converted Customers or Vendors module opens on its create form
 * (§2A.1) without writing the fields out a second time. Pass none and it opens
 * in the drawer. `adoptForm()` in workspace.js does the moving and shows the
 * chrome that goes with it; an edit always takes the drawer, because one record
 * over a list is what level 2 is for.
 *
 * ## What stays with the caller
 *
 * Everything about what the save *means* to the screen it happened on. The bill
 * counter selects the new party and re-prices; the Vendors screen refreshes its
 * list and says so when a record has just left it. Both arrive through
 * `onSaved`, because neither is this component's business.
 */

import auth from '../auth-client';
import { can } from '../permissions';
import {
    $, $$, clearFormErrors, confirmAction, hideModal, setSubmitting, showFormErrors, showModal, toast,
} from '../ui';
import { adoptForm } from '../workspace';

/**
 * What each open form is writing, and who is waiting on it.
 *
 * Held per form *node* rather than in one module-level record, and that is not
 * tidiness. Customers and Vendors are two modules over this one component, and
 * the shell keeps both mounted once they have been opened — so a single shared
 * context would belong to whichever was opened last, and the next save on the
 * other one would write the wrong noun, tell the wrong list, and move the wrong
 * form node into a detached module. Keyed by the node, each form carries its
 * own.
 *
 * Kept out of the DOM for the same reason `quick-item.js` keeps its context in
 * JS: a failed save must be retryable against the *same* record, and a handle
 * rebuilt from the markup on submit could not tell an edit whose id input was
 * cleared from a create.
 *
 * Per context:
 *
 *   role       what a create writes — decided by where the form was opened
 *   noun       what to call them in the copy
 *   editingId  the record being edited, or null on a create
 *   roles      what the payload sends: the one role on a create, and on an edit
 *              whatever the record already holds, untouched
 *   slot       the level-1 slot it was mounted into, or null when in the drawer.
 *              It decides what a successful save does: an inline create stays on
 *              the form and clears it, a drawer one closes
 *   options    what it was opened with, so it can be reopened exactly as it was
 *              — which is what "Clear" means, and what a save leaves behind for
 *              the next record (§2A.8)
 *   onSaved    what the save means to the screen it happened on
 */
const contexts = new WeakMap();

/**
 * The form node belonging to each drawer, remembered when that drawer is wired.
 *
 * The form *moves* — into a module's level-1 slot, into the drawer for an edit,
 * back again — so `document` can only find it while it happens to be on screen,
 * and a module showing its list has the whole surface detached. The drawer never
 * moves and there is exactly one per module, so it is what the form is looked up
 * by. Anything else is guessing from document order, and the guess is wrong the
 * moment two modules are mounted over this one component.
 */
const forms = new WeakMap();

const cap = (word) => word.charAt(0).toUpperCase() + word.slice(1);

/* -------------------------------------------------------------------------
 | Opening
 | ---------------------------------------------------------------------- */

/**
 * The form this screen writes counterparties with, wherever it is mounted.
 *
 * For a host that has to put it back — a module returning to its create surface
 * — rather than reach for `#quick-party-form` and hope the surface holding it
 * happens to be attached.
 */
export function quickPartyForm() {
    const drawer = $('#quick-party-drawer');

    return drawer ? (forms.get(drawer) ?? $('#quick-party-form', drawer)) : null;
}

/**
 * Open the form.
 *
 * @param {object} options
 * @param {'customer'|'vendor'} [options.role]  What a create writes. Never asked
 *                                              on the form — see the partial.
 * @param {object|null} [options.party]         An existing record to edit.
 * @param {boolean} [options.full]              The record form rather than the
 *                                              quick add. Forced true on an edit.
 * @param {string} [options.noun]               What to call them in the copy.
 * @param {string} [options.nameLabel]          "Vendor Name", where a screen has
 *                                              its own word for the column.
 * @param {string} [options.namePlaceholder]
 * @param {string} [options.name]               What to start the name box on —
 *                                              a picker passes what was typed.
 * @param {HTMLElement|null} [options.slot]     The level-1 slot this screen keeps
 *                                              the form in. A create is mounted
 *                                              there; an edit still takes the
 *                                              drawer, but the slot is where the
 *                                              node is found.
 * @param {(party: object, meta: {editing: boolean}) => void} [options.onSaved]
 */
export function openQuickParty(options = {}) {
    const {
        role = 'customer',
        party = null,
        full = false,
        noun = null,
        nameLabel = null,
        namePlaceholder = '',
        name = '',
        slot = null,
        onSaved = () => {},
    } = options;

    const drawer = $('#quick-party-drawer');

    if (!drawer) {
        toast('This screen cannot add a customer or a vendor.', 'error');

        return;
    }

    const editing = party !== null;

    // The grant is checked here as well as on the control that opened this,
    // because a picker's "+ Add" is not the only way in — and it is checked
    // again server-side, which is the one that counts (§6.2).
    if (!can(editing ? 'UPDATE' : 'WRITE', 'PARTIES')) {
        toast('You do not have permission to do that.', 'error');

        return;
    }

    /*
    | The form node, which is not always on screen.
    |
    | Taken from the drawer that was wired for this screen — see `forms` — rather
    | than from `document`, which can only see it while the surface holding it is
    | attached. The two fallbacks are for a screen that never called
    | `initQuickParty`, and neither can pick another module's form.
    */
    const form = forms.get(drawer)
        ?? $('#quick-party-form', drawer)
        ?? (slot && $('#quick-party-form', slot));

    if (!form) {
        toast('This screen cannot add a customer or a vendor.', 'error');

        return;
    }

    /*
    | One form, two frames (§4.4).
    |
    | Writing a counterparty on a converted module *is* level 1 — the module
    | opens on this form — so the node is moved into that module's slot rather
    | than written out a second time there. An edit is one record read over a
    | list, which is level 2 and so always the drawer: a form that hid half a
    | record's fields inside a level-1 surface somebody landed on by accident
    | would be a form that could not put them back.
    */
    const inline = slot !== null && !editing;

    adoptForm(form, inline ? slot : $('.drawer-panel', drawer), {
        chrome: inline ? 'inline' : 'modal',
    });

    clearFormErrors(form);
    form.reset();

    /*
    | The roles the payload will carry.
    |
    | A create writes the one role the screen or the document is for — nobody is
    | asked to choose. An edit carries whatever the record already holds,
    | untouched: the counterparty who is both is one record with one combined
    | ledger, and editing them from the Vendors screen must not quietly drop the
    | customer half of that.
    */
    const context = {
        role,
        noun: noun ?? role,
        full: full || editing,
        editingId: editing ? party.id : null,
        roles: editing ? (party.roles ?? [role]) : [role],
        slot: inline ? slot : null,
        options,
        onSaved,
    };

    contexts.set(form, context);

    showFullFields(context.full, form);
    paintCopy(party, form, context);

    form.elements.id.value = editing ? party.id : '';
    // A create starts on whatever the caller was searching for, which is what
    // somebody pressing a picker's "+ Add" has just finished typing.
    form.elements.name.value = editing ? party.name : name;
    form.elements.phone.value = editing ? (party.phone ?? '') : '';
    form.elements.gstin.value = editing ? (party.gstin ?? '') : '';
    form.elements.email.value = editing ? (party.email ?? '') : '';
    form.elements.address.value = editing ? (party.address ?? '') : '';
    form.elements.notes.value = editing ? (party.notes ?? '') : '';

    $('#quick-party-name', form).placeholder = namePlaceholder;
    $('#quick-party-name-label', form).textContent = nameLabel ?? `${cap(context.noun)} name`;

    showStateHint(form.elements.gstin.value, form);

    if (!inline) showModal('#quick-party-drawer');

    /*
    | Creates only, and the name having arrived already, the cursor belongs on
    | the next thing to fill in rather than on a box that is done.
    |
    | An edit is left alone: the fields are all populated, so there is no "next
    | thing", and moving the caret into one of them invites a change nobody came
    | to make.
    */
    if (!editing) {
        const landOn = form.elements.name.value.trim() === '' ? form.elements.name : form.elements.phone;

        requestAnimationFrame(() => landOn.focus());
    }
}

/** The parts only the record screens ask for — see the partial. */
function showFullFields(full, form) {
    // Scoped to the form rather than to the drawer: the node is not always in
    // the drawer any more, and a level-1 create is exactly the case that needs
    // these fields shown.
    $$('[data-quick-party-full]', form).forEach((part) => {
        part.classList.toggle('hidden', !full);
    });
}

function paintCopy(party, form, context) {
    const editing = party !== null;
    const { noun, full } = context;

    $('#quick-party-title', form).textContent = editing ? `Edit ${party.name}` : `New ${noun}`;

    $('#quick-party-subtitle', form).textContent = editing
        ? 'Changing the GSTIN moves which columns of a tax return their bills land in.'
        : full
            ? `Enter the ${noun}'s contact and business details.`
            : `Added to your records, so they appear on the ${cap(noun)}s screen too.`;

    const label = editing
        ? 'Save changes'
        // "Save and select" says what happens next, which is what somebody
        // interrupted halfway through a bill needs to know.
        : full ? `Add ${noun}` : 'Save and select';

    /*
    | Both footers, because the form carries one for each frame and only the one
    | matching where it is mounted is on screen — `setSubmitting` finds the
    | visible button, and painting only the drawer's would leave the level-1
    | button saying whatever it said last.
    |
    | `idleLabel` is cleared before the label is set, or a button would come back
    | from a save wearing the label it had the *first* time the form was ever
    | opened: setSubmitting caches the idle text with `??=` and never revisits
    | it. On a form whose label changes per open, that cache has to be
    | invalidated by whoever changes it.
    */
    $$('[data-quick-party-submit]', form).forEach((submit) => {
        delete submit.dataset.idleLabel;
        submit.textContent = label;
    });
}

/** The state code the GSTIN implies, shown as it is typed. */
function showStateHint(gstin, form) {
    const code = String(gstin ?? '').trim().slice(0, 2);

    $('#quick-party-state-hint', form).textContent = /^\d{2}$/.test(code)
        ? `State code ${code}. Bills will be inter-state unless it matches your workshop's.`
        : 'The first two digits set the state, which decides CGST/SGST or IGST.';
}

/* -------------------------------------------------------------------------
 | Saving
 | ---------------------------------------------------------------------- */

/**
 * The shape checks, so the common mistakes are explained without a round trip.
 *
 * The server is still the authority on every one of these and on the things
 * only it can know — whether the name is taken, whether the GSTIN belongs to
 * somebody else (§6.1).
 */
function validate(form) {
    const errors = {};
    const name = form.elements.name.value.trim();

    if (name.length < 2) errors.name = ['The name must be at least 2 characters.'];
    else if (name.length > 150) errors.name = ['The name may not exceed 150 characters.'];

    const gstin = form.elements.gstin.value.trim().toUpperCase();

    if (gstin && !/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/.test(gstin)) {
        errors.gstin = ['That does not look like a GSTIN — 15 characters: 2 digits, a PAN, then 3 more.'];
    }

    return Object.keys(errors).length ? errors : null;
}

async function submit(event) {
    event.preventDefault();

    const form = event.target;
    const context = contexts.get(form);

    // Nothing has opened this form, so there is nothing that says what it would
    // write. Never reached through the UI; cheaper than the bug if it ever is.
    if (!context) return;

    const editing = context.editingId !== null;

    clearFormErrors(form);

    const errors = validate(form);

    if (errors) {
        showFormErrors(form, { fields: errors, message: 'Please correct the highlighted fields.' });

        return;
    }

    const payload = {
        name: form.elements.name.value.trim(),
        // Decided when the form was opened, never asked on it — see the context.
        roles: context.roles,
        gstin: form.elements.gstin.value.trim().toUpperCase() || null,
        phone: form.elements.phone.value.trim() || null,
        email: form.elements.email.value.trim() || null,
        address: form.elements.address.value.trim() || null,
        notes: form.elements.notes.value.trim() || null,
    };

    setSubmitting(form, true, 'Saving…');

    try {
        const response = await auth.call(editing ? `/parties/${context.editingId}` : '/parties', {
            method: editing ? 'PATCH' : 'POST',
            body: payload,
        });

        await settle(form, context, response, { editing });
    } catch (error) {
        // A name already on the books is usually the same counterparty met from
        // the other side, which is an offer to make rather than an error to
        // report. Anything else — and anything the offer is declined for —
        // falls through to the message the server sent.
        if (!editing && error.code === 'PARTY_NAME_TAKEN'
            && await markExistingWithThisRole(form, context, payload.name)) {
            return;
        }

        showFormErrors(form, error);
    } finally {
        setSubmitting(form, false);
    }
}

/** Everything a successful save means, wherever the form is mounted. */
async function settle(form, context, response, { editing = false, merged = false } = {}) {
    // The drawer is what a save closes. A level-1 form is not closed by
    // anything — it is the module — so it is cleared for the next record
    // instead, below.
    if (!context.slot) hideModal('#quick-party-drawer');

    const party = response.data;

    toast(merged
        ? `${party.name} is a ${context.noun} as well now.`
        : `${cap(context.noun)} ${editing ? 'updated' : 'added'}.`);

    // A shared GSTIN is legitimate for a second branch and a duplicate the
    // rest of the time, so it is reported after the save rather than blocking
    // it — the user can still merge the two while it is fresh.
    (response.meta?.warnings ?? []).forEach((warning) => toast(warning.message, 'info'));

    // Awaited: a caller that refreshes a list behind the drawer is still part
    // of this save, and letting it run detached would turn a failed refresh
    // into an unhandled rejection instead of an error somebody sees.
    await context.onSaved(party, { editing, merged });

    /*
    | §2A.8 — a create on a level-1 form stays on the form, clears the fields
    | for the next one and returns focus to the first. Somebody entering the
    | workshop's suppliers writes several in a row, and dropping them onto a
    | list after each would cost a click back every time.
    |
    | Reopened rather than reset, because half of what makes this form blank is
    | not in the markup: the labels, which fields the full shape shows, and the
    | role the next record will carry.
    */
    if (context.slot) openQuickParty(context.options);
}

/**
 * The name is already on the books — offer what is almost always meant.
 *
 * The shop that sells you scrap copper is the shop you rewound a motor for, and
 * meeting them from the second side is the commonest reason a name collides. A
 * second record would split one balance into two halves that are never netted
 * or even looked at together, which is the mistake the parties table exists to
 * prevent — so what is offered is the record that already exists, marked with
 * this role as well. One record, one combined ledger, on both lists.
 *
 * Asked here rather than on the form, because here is where the question means
 * something. Nobody adding a supplier should have to answer it in advance.
 *
 * @returns {Promise<boolean>} true when this handled the save.
 */
async function markExistingWithThisRole(form, context, name) {
    // Marking an existing record is an edit of it, and that is a grant of its
    // own — a data-entry clerk may add a party but not change one. Without it
    // there is nothing to offer, so the server's message stands.
    if (!can('UPDATE', 'PARTIES')) return false;

    const existing = await findByName(name);

    // Gone, renamed, or a name this caller cannot see: the server's own message
    // is the better one.
    if (!existing) return false;

    const roles = existing.roles ?? [];

    // Genuinely a second record wanting one name, which is what the server said
    // and exactly what it means.
    if (roles.includes(context.role)) return false;

    const confirmed = await confirmAction({
        title: `${existing.name} is already on the books`,
        body: `They are recorded as a ${otherNoun(context.role)}. Rather than a second record — which would `
            + `split one balance in two — the record that exists is marked as a ${context.noun} as well, so `
            + 'they appear on both screens with one combined ledger. Their saved details are kept; anything '
            + 'typed here is not.',
        confirmLabel: `Also a ${context.noun}`,
        // Nothing is destroyed here — a record gains a role and a list gains a
        // row. The red treatment is for the ones that cannot be undone.
        tone: 'primary',
    });

    if (!confirmed) return false;

    const response = await auth.call(`/parties/${existing.id}`, {
        method: 'PATCH',
        body: { roles: [...roles, context.role] },
    });

    await settle(form, context, response, { merged: true });

    return true;
}

/** The record that already holds this name, exactly. */
async function findByName(name) {
    const needle = name.trim().toLowerCase();

    try {
        // Unfiltered by role and by archived state on purpose: the record that
        // took the name is the one to offer, whichever list it is on and
        // whether or not it is still in use.
        const payload = await auth.call(`/parties?search=${encodeURIComponent(name)}&per_page=25`);

        return (payload.data ?? [])
            .find((row) => String(row.name).trim().toLowerCase() === needle) ?? null;
    } catch {
        // The lookup is a courtesy on top of a save that has already failed.
        return null;
    }
}

const otherNoun = (role) => (role === 'vendor' ? 'customer' : 'vendor');

/* -------------------------------------------------------------------------
 | Mounting
 | ---------------------------------------------------------------------- */

/**
 * Wire the shared drawer, once per page. Safe to call where the partial is not
 * included — it simply does nothing.
 *
 * Closing needs no handler of its own: the drawer carries `data-modal`, so
 * ui.js's backdrop click and Escape already reach it, and the shell defers to an
 * open dialog before unwinding a level of its own (§2A.9). Cancelling here has
 * no side effect to undo, which is the one thing `quick-item.js` has to take
 * Escape for.
 */
export function initQuickParty() {
    const drawer = $('#quick-party-drawer');

    if (!drawer) return;

    const form = $('#quick-party-form', drawer);

    if (!form) return;

    // Wired once, and remembered by the drawer it belongs to: from here on it is
    // found by identity, wherever it has been moved to since.
    forms.set(drawer, form);

    form.addEventListener('submit', submit);

    /*
    | "Clear" on the level-1 footer.
    |
    | Not a `type="reset"`, which empties the boxes and nothing else: the
    | labels, the fields the full shape shows and any error still on screen are
    | all set when the form is opened. Reopening the same create restores the
    | lot. Delegated, because the button travels with the form between its two
    | frames.
    */
    form.addEventListener('click', (event) => {
        if (!event.target.closest('[data-quick-party-clear]')) return;

        const context = contexts.get(form);

        if (context) openQuickParty(context.options);
    });

    $('#quick-party-gstin', form).addEventListener('input',
        (event) => showStateHint(event.target.value, form));
}
