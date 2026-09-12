/**
 * Shared UI primitives for the CRUD modules: escaping, modals, toasts and the
 * form-error plumbing that maps the API's 422 envelope onto fields.
 */

export const $ = (selector, root = document) => root.querySelector(selector);
export const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

/** Anything interpolated into innerHTML goes through here. */
export function esc(value) {
    if (value === null || value === undefined) return '';

    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

export function debounce(fn, wait = 300) {
    let timer;

    const run = (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), wait);
    };

    // For the callers that need to overtake their own wait — a confirmation
    // that must not open on a figure the last keystroke has already changed.
    run.cancel = () => clearTimeout(timer);

    return run;
}

export function formatDate(value) {
    if (!value) return '—';

    return new Date(value).toLocaleDateString(undefined, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

/**
 * How long ago, in words: "15 min ago", "3 hours ago", "yesterday".
 *
 * For the one place a relative time beats an absolute one — a worklist, where
 * the question is which draft has gone stale rather than what day it was
 * started. Everywhere else uses {@see formatDate}, because "12 Jul 2024" is
 * what a voucher says and a voucher's date does not drift as you read it.
 *
 * Falls back to the date past a week, where "23 days ago" has stopped being
 * easier to think about than the day itself.
 */
export function formatRelative(value) {
    if (!value) return '—';

    const then = new Date(value);
    const seconds = Math.round((Date.now() - then.getTime()) / 1000);

    if (Number.isNaN(seconds)) return '—';
    // A clock a few seconds ahead of the server should not read "in 4 seconds".
    if (seconds < 60) return 'just now';

    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes} min ago`;

    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours} hour${hours === 1 ? '' : 's'} ago`;

    const days = Math.floor(hours / 24);
    if (days === 1) return 'yesterday';
    if (days < 7) return `${days} days ago`;

    return formatDate(value);
}

/* -------------------------------------------------------------------------
 | Money
 | ---------------------------------------------------------------------- */

/**
 * Group a decimal-string amount for display: "1234567.5" -> "12,34,567.50".
 *
 * The API sends amounts as strings and they stay strings the whole way here —
 * `Number('0.10')` is a binary float, and a page that adds a column of those
 * shows a total a paisa out from the one the ledger holds. Grouping is done on
 * the digits themselves, so nothing is ever parsed.
 *
 * Indian grouping: the last three digits, then twos — 12,34,567 rather than
 * 1,234,567.
 */
export function formatMoney(amount, { sign = false } = {}) {
    if (amount === null || amount === undefined || amount === '') return '—';

    const text = String(amount).trim();
    const negative = text.startsWith('-');
    const [whole = '0', fraction = ''] = text.replace(/^[-+]/, '').split('.');

    const paise = (fraction + '00').slice(0, 2);

    const last3 = whole.slice(-3);
    const rest = whole.slice(0, -3);
    const grouped = rest
        ? `${rest.replace(/\B(?=(\d{2})+(?!\d))/g, ',')},${last3}`
        : last3;

    const prefix = negative ? '-' : (sign && whole !== '0' ? '+' : '');

    return `${prefix}${grouped}.${paise}`;
}

/** True when a decimal-string amount is zero, without parsing it. */
export function isZeroAmount(amount) {
    return /^-?0+(\.0*)?$/.test(String(amount ?? '0').trim());
}

/* -------------------------------------------------------------------------
 | Toast — the floating alert, top right
 |
 | One convention for saying how something went, and this is half of it. The
 | other half is the inline banner further down: an error is shown in both
 | places, a success only here. See the note above `.form-banner` in
 | shared.css, which is where that split is argued.
 |
 | It is a floating alert rather than a line at the top of the page because a
 | line at the top of the page is not feedback on a form somebody has scrolled
 | to the bottom of — which is where every save in this application is pressed.
 | ---------------------------------------------------------------------- */

/** How long each tone stays up. */
const TOAST_LIFE = { success: 3200, info: 3200, error: 6000 };

const TOAST_TONES = {
    success: 'toast-success',
    error: 'toast-error',
    info: '',
};

/**
 * The mark on the left of an alert.
 *
 * Inline SVG rather than the Blade `x-icon` component, because these are built
 * from JavaScript — the same two paths that component would have emitted.
 */
const TOAST_MARKS = {
    success:
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>',
    error:
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><path d="M12 16.5h.01"/></svg>',
    info:
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 7.5h.01"/></svg>',
};

/**
 * The stack, resolved once and created if the document has none.
 *
 * The application's layout and the customer's invoice page both declare it, so
 * this creates one only for a document that does not — and it carries the same
 * class either way rather than a second set of styles typed here, which is how
 * an alert on the sign-in screen ends up looking like an alert everywhere else.
 */
function toastHost() {
    let host = $('#toast-host');

    if (!host) {
        host = document.createElement('div');
        host.id = 'toast-host';
        host.className = 'toast-host';
        host.setAttribute('aria-live', 'polite');
        document.body.append(host);
    }

    // Delegated once per host: an alert is dismissed by clicking it, which is
    // what somebody does to an error sitting over the thing they want to read.
    if (!host.dataset.bound) {
        host.dataset.bound = '1';
        host.addEventListener('click', (event) => dismissToast(event.target.closest('.toast')));
    }

    return host;
}

function dismissToast(node) {
    if (!node || node.classList.contains('is-going')) return;

    node.classList.add('is-going');
    setTimeout(() => node.remove(), 250);
}

/**
 * Say how something went, top right.
 *
 * @param {string} message
 * @param {'success'|'error'|'info'} tone
 */
export function toast(message, tone = 'success') {
    if (!message) return;

    const host = toastHost();
    const node = document.createElement('div');

    node.className = `toast ${TOAST_TONES[tone] ?? TOAST_TONES.info}`.trim();
    // `alert` interrupts a screen reader and `status` waits its turn. A refusal
    // is worth interrupting for; "Saved" is not.
    node.setAttribute('role', tone === 'error' ? 'alert' : 'status');
    node.innerHTML = TOAST_MARKS[tone] ?? TOAST_MARKS.info;

    const text = document.createElement('span');
    text.textContent = message;
    node.append(text);

    host.append(node);

    setTimeout(() => dismissToast(node), TOAST_LIFE[tone] ?? TOAST_LIFE.info);
}

/* -------------------------------------------------------------------------
 | Modal
 | ---------------------------------------------------------------------- */

/**
 * A stack rather than a single reference, because a modal can legitimately open
 * over another one — the bill form's "create a new item" is a form inside a
 * form. Escape has to close the top one and leave the one underneath open, and a
 * single reference would forget the first dialog the moment the second appeared.
 */
const openModals = [];

export function showModal(id) {
    const modal = typeof id === 'string' ? $(id) : id;
    if (!modal) return;

    modal.classList.remove('hidden');

    if (!openModals.includes(modal)) openModals.push(modal);

    // Focus the first usable control so the dialog is keyboard-ready.
    setTimeout(() => $('input:not([type=hidden]), select, textarea, button', modal)?.focus(), 30);
}

export function hideModal(id) {
    const modal = typeof id === 'string' ? $(id) : id;
    if (!modal) return;

    modal.classList.add('hidden');

    const at = openModals.indexOf(modal);

    if (at !== -1) openModals.splice(at, 1);
}

/** Backdrop click + Escape close whichever modal is open. */
export function initModals() {
    document.addEventListener('click', (event) => {
        // closest(), not matches(): the close button wraps an <svg>, so a click
        // on the icon glyph reports the svg (or its <path>) as event.target.
        const closer = event.target.closest('[data-modal-close]');

        if (closer) {
            hideModal(closer.closest('[data-modal]'));

            return;
        }

        // Only the backdrop itself, never a click inside the panel.
        if (event.target.matches('[data-modal]')) {
            hideModal(event.target);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && openModals.length) {
            hideModal(openModals[openModals.length - 1]);
        }
    });
}

/* -------------------------------------------------------------------------
 | Confirm dialog
 | ---------------------------------------------------------------------- */

/**
 * Promise-based confirm backed by the themed modal in the page shell.
 */
export function confirmAction({ title, body, confirmLabel = 'Delete', tone = 'danger' }) {
    return new Promise((resolve) => {
        const modal = $('#confirm-modal');

        $('[data-confirm-title]', modal).textContent = title;
        $('[data-confirm-body]', modal).textContent = body;

        const button = $('[data-confirm-accept]', modal);
        button.textContent = confirmLabel;
        button.className = `btn ${tone === 'danger' ? 'btn-danger' : 'btn-primary'}`;

        // Replace the node to drop listeners from any previous invocation.
        const fresh = button.cloneNode(true);
        button.replaceWith(fresh);

        const finish = (result) => {
            hideModal(modal);
            resolve(result);
        };

        fresh.addEventListener('click', () => finish(true), { once: true });
        $$('[data-confirm-cancel]', modal).forEach((el) =>
            el.addEventListener('click', () => finish(false), { once: true })
        );

        showModal(modal);
    });
}

/* -------------------------------------------------------------------------
 | Form errors — the inline half of the one convention
 |
 | Every form in this application is refused the same way and in the same two
 | places: the fields the server named are marked where they are, a single
 | banner is painted at the submit control that was pressed, and the same
 | sentence goes to the floating alert.
 |
 | The banner used to be declared at the top of each form, which is the one
 | place it cannot usefully be. Every save in a §2A workspace is pressed at the
 | bottom of a long form — an expense with a payment split, a job card with its
 | specs, a product with three variants — so a refusal appeared a screenful
 | above the button and the screen simply did not move. Somebody presses again.
 |
 | So placement is decided here and is not a thing the markup says. There were
 | nineteen hand-declared banners and they are gone: no form carries a slot, no
 | new form has to remember to, and none of them can drift to a different set
 | of classes. The failure being fixed is a *missing* message, and a convention
 | that each new form remembers to carry a slot fails in exactly that way —
 | silently, one form at a time, which is the argument loader.js makes about
 | the activity bar and data-bus.js about a write announcing itself.
 | ---------------------------------------------------------------------- */

/**
 * The submit control the user can actually see.
 *
 * A form that is moved between a level-1 slot and a dialog carries a footer for
 * each — see `adoptForm()` in workspace.js — and only one of them is on screen.
 */
function visibleSubmit(form) {
    const buttons = $$('[type=submit]', form);

    return buttons.find((el) => el.offsetParent !== null) ?? buttons[0] ?? null;
}

/**
 * Put the banner where the press was.
 *
 * A **footer of nothing but controls** — which is what nearly every form here
 * ends in — takes it *inside*, at the end, under all of them. That is what makes
 * one class enough for every surface: a drawer's footer carries its own
 * `px-5 py-4` and a level-1 footer carries none, so a banner dropped in beside
 * the buttons is inset correctly on both, where a sibling underneath would run
 * edge to edge in the drawer. `.form-banner` takes a whole line of that row
 * (`flex-basis: 100%`) and the row is made to wrap, which is safe precisely
 * because there is nothing in it but buttons — none of them has a width it has
 * to keep. It is also what keeps the message under the *submit* on a level-1
 * footer, where the primary button is written first and a "Clear" follows it.
 *
 * A container holding **more than controls** is the other shape, and it splits
 * on whether it is a flex line. The bill document's Post sits in a plain column
 * above a Save-as-draft and a note about where the figures come from, so the
 * message goes directly under the button that was pressed rather than at the
 * end, where it would arrive under a note it says nothing about. But a *flex*
 * row of an input and its button — Staff's designation adder is the one — must
 * not be made to wrap at all: the input is `width: 100%` and would take the
 * whole line to itself, dropping the button below it. So there the banner clears
 * the line instead, as the row's next sibling.
 */
function place(banner, trigger, fallback) {
    // A surface whose only control was taken out of the DOM by a permission gate
    // has nowhere better than its own end.
    if (!trigger) {
        fallback.append(banner);

        return;
    }

    const row = trigger.parentElement ?? fallback;
    const flex = getComputedStyle(row).display.includes('flex');
    const controlsOnly = [...row.children].every(
        (child) => child === banner || child.tagName === 'BUTTON' || child.tagName === 'A'
    );

    if (controlsOnly) {
        row.append(banner);

        if (flex) row.style.flexWrap = 'wrap';
    } else if (flex && row !== fallback) {
        row.after(banner);
    } else {
        trigger.after(banner);
    }
}

/**
 * Say no, in the two places every refusal in this application is said.
 *
 * `scope` is where an existing banner is looked for and `trigger` is the control
 * that was pressed. They differ for a form, whose banner may be sitting in the
 * footer of the chrome it is *not* currently wearing.
 *
 * It is re-placed on every call, never only on the first: a form adopted into a
 * dialog moves to a second footer (`adoptForm()` in workspace.js), and the
 * message belongs under the button that was actually pressed.
 */
function reportRefusal(scope, trigger, message) {
    let banner = $('[data-form-banner]', scope);

    if (!banner) {
        banner = document.createElement('p');
        banner.className = 'form-banner hidden';
        banner.setAttribute('data-form-banner', '');
        banner.setAttribute('role', 'alert');
    }

    place(banner, trigger, scope);

    banner.textContent = message;
    banner.classList.remove('hidden');

    // A CTA pressed at the very bottom edge of the viewport puts its own message
    // just under the fold, which is the fault this whole convention is fixing —
    // one screenful further up rather than one further down. `nearest` moves the
    // minimum, so a banner already on screen does not shift the page at all, and
    // it scrolls a drawer's own overflow rather than the document behind it.
    banner.scrollIntoView({ block: 'nearest' });

    toast(message, 'error');
}

export function clearFormErrors(form) {
    $$('[data-error-for]', form).forEach((el) => {
        el.textContent = '';
        el.classList.add('hidden');
    });

    $$('[aria-invalid]', form).forEach((el) => el.removeAttribute('aria-invalid'));

    const banner = $('[data-form-banner]', form);
    if (banner) {
        banner.textContent = '';
        banner.classList.add('hidden');
    }
}

/**
 * The slot a field's message belongs in, allowing for a key that names one entry
 * of a repeated group.
 *
 * The server reports nested keys — `permission_ids.0`, `variants.2.opening_cost`
 * — and a form may label them at any depth. The item form gives every variant
 * block its own `variants.2.opening_cost` box *and* a `variants.2` footer for a
 * refusal that named no field of its own, where a form with a plain list of ids
 * labels `permission_ids` and nothing under it.
 *
 * So the key is tried whole and then shortened a segment at a time, landing on
 * the most specific slot the form actually declared. That collapses
 * `permission_ids.0` onto `permission_ids` exactly as this always did, and it is
 * the difference between "one of these five variants is wrong" and "this one".
 */
function errorSlot(form, field) {
    const parts = String(field).split('.');

    while (parts.length) {
        const slot = $(`[data-error-for="${parts.join('.')}"]`, form);

        if (slot) return slot;

        parts.pop();
    }

    return null;
}

/**
 * The control a message is about, so it can be marked as well as described.
 *
 * `form.elements[name]` answers with a RadioNodeList wherever two controls share
 * a name, and a list has no `setAttribute` — so the guard that was here quietly
 * marked nothing at all on exactly the forms that need it most. The expense form
 * is the worked example: its own `amount` shares a name with the amount on every
 * payment row, so a refused expense underlined no field, and the red border that
 * `.field-input[aria-invalid='true']` exists to paint had never once appeared
 * there.
 *
 * Which of the several it is comes from the slot rather than from the order they
 * were written in: the message and the control it is about are in the same field
 * block, and nothing else in the form knows that pairing.
 */
function errorInput(form, field, slot) {
    const found = form.elements?.[field] ?? form.elements?.[field.split('.')[0]];

    if (!found) return null;

    // One control of that name — the ordinary case.
    if (found.setAttribute) return found;

    const block = slot?.closest('label, div');

    return [...found].find((el) => block?.contains(el)) ?? found[0] ?? null;
}

/**
 * The one sentence that goes on the banner and into the alert.
 *
 * A 422's own message is "The given data was invalid.", which tells somebody
 * standing at a counter nothing at all — so a validation failure is summarised
 * from what was actually wrong instead.
 *
 * `stranded` comes first and is the case that matters: a field message the form
 * has no slot for would otherwise be shown nowhere, and the user would be
 * refused with no reason anywhere on the screen.
 */
function summarise(error, marked, stranded) {
    if (stranded.length === 1) return stranded[0];

    if (stranded.length) {
        return `${stranded[0]} (and ${stranded.length - 1} more).`;
    }

    if (marked === 1) return 'One field needs correcting — it is marked above.';

    if (marked > 1) return `${marked} fields need correcting — they are marked above.`;

    return error.message;
}

/**
 * Paint an ApiError onto a form, and say so top right.
 *
 * Both, always. The banner is the answer to "why did nothing happen when I
 * pressed this" and it is still there a minute later; the alert is what catches
 * the eye of somebody looking at the field they were typing in rather than at
 * the button. Neither is enough on its own, which is why this is one function
 * and not a choice made per call site.
 *
 * `form` is usually a `<form>`, and does not have to be: the shared bill
 * document is a mounted region rather than a form element, and it is the longest
 * write surface in the product — exactly the one that must not have a second way
 * of reporting a refusal. Where a surface's CTA is not a `[type=submit]` inside
 * it, or was on a confirmation that has since closed, the caller names the
 * control the message belongs under.
 */
export function showFormErrors(form, error, trigger = visibleSubmit(form)) {
    clearFormErrors(form);

    /** Field messages the form had a slot for. */
    let marked = 0;
    /** Field messages it did not — these have to reach the banner or vanish. */
    const stranded = [];

    if (error.fields) {
        Object.entries(error.fields).forEach(([field, messages]) => {
            const slot = errorSlot(form, field);
            const input = errorInput(form, field, slot);

            if (slot) {
                slot.textContent = messages[0];
                slot.classList.remove('hidden');
                marked += 1;
            } else {
                stranded.push(messages[0]);
            }

            input?.setAttribute('aria-invalid', 'true');
        });
    }

    const message = summarise(error, marked, stranded);

    reportRefusal(form, trigger, message);
}

/**
 * A refusal the form made itself — the handful of checks that cannot be
 * expressed as a field, such as a settlement whose allocation grid has been
 * cleared while the party still has open bills.
 *
 * Same two places as a server refusal, so a form never has a second way of
 * saying no.
 */
export function showFormMessage(form, message, trigger = visibleSubmit(form)) {
    reportRefusal(form, trigger, message);
}

/**
 * The same, for a CTA that is not a form's submit — the drawer buttons that
 * post a payment, raise a return or allocate a receipt.
 *
 * Those are the presses that had nothing but a toast, which is the complaint
 * this whole convention answers: somebody at the bottom of a drawer pressed
 * Record the payment, the request was never sent, and the only sign of it had
 * already faded from the far corner of the screen.
 */
export function showActionError(trigger, message) {
    reportRefusal(trigger.parentElement ?? trigger, trigger, message);
}

/**
 * Disable a submit button and swap its label while a request is in flight.
 *
 * The *visible* one. A form that is moved between a level-1 slot and a dialog
 * carries a footer for each — see `adoptForm()` in workspace.js — and only one
 * of them is on screen. Disabling the hidden one would leave the button the user
 * is looking at live, and clickable a second time — the same judgement the
 * banner makes about which footer to appear under, so it is the same lookup.
 */
export function setSubmitting(form, busy, busyLabel = 'Saving…') {
    const button = visibleSubmit(form);

    if (!button) return;

    if (busy) {
        button.dataset.idleLabel ??= button.textContent.trim();
        button.textContent = busyLabel;
    } else if (button.dataset.idleLabel) {
        button.textContent = button.dataset.idleLabel;
    }

    button.disabled = busy;
}

/* -------------------------------------------------------------------------
 | Table states
 | ---------------------------------------------------------------------- */

export function tableMessage(colspan, message, tone = 'muted') {
    const color = tone === 'error' ? 'text-rose-600' : 'text-muted-foreground';

    return `<tr><td colspan="${colspan}" class="px-4 py-12 text-center text-sm ${color}">${esc(message)}</td></tr>`;
}

/* -------------------------------------------------------------------------
 | Export
 | ---------------------------------------------------------------------- */

/**
 * One cell of a CSV, quoted only when it has to be.
 *
 * Quoting everything would be valid and would also make the file unreadable in
 * a text editor, which is where somebody looks when a spreadsheet has mangled
 * a column.
 */
function csvCell(value) {
    const text = String(value ?? '');

    return /[",\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

/**
 * Hand the browser a CSV of `rows`, the first of which is the header.
 *
 * Shared rather than written per module: it was in accounts.js first, and the
 * BOM below is the sort of detail that gets remembered in one copy and not the
 * other (§5.1).
 *
 * @param {string} filename
 * @param {Array<Array<unknown>>} rows
 */
export function downloadCsv(filename, rows) {
    const csv = rows.map((row) => row.map(csvCell).join(',')).join('\r\n');
    // The BOM is what makes Excel open a UTF-8 CSV as UTF-8 rather than as the
    // system codepage, which is where rupee signs turn into mojibake.
    const blob = new Blob([`\ufeff${csv}`], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = filename;
    link.click();

    URL.revokeObjectURL(url);
}
