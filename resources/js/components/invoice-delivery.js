/**
 * Handing a customer their invoice — previewing it, printing it, sharing it.
 *
 * Lifted whole out of `pages/sales.js`, where it was written and where it had
 * to stay until a second module needed it. Jobs is that module: C4 shipped the
 * half of a workshop bill that posts and deliberately stopped short of the half
 * the customer walks out holding, because the one-sheet rule below cannot be
 * satisfied by a second copy of any of this. So it is one component, mounted by
 * both, and the next screen that hands over a document borrows it rather than
 * building a third (§5.1).
 *
 * ## There is one sheet, and it is borrowed
 *
 * The print rule in app.css keeps whichever child of `<body>` *contains* an
 * invoice and hides every other one, so a second `[data-invoice-document]`
 * anywhere under `<main>` would make `<main>` a thing worth keeping — and every
 * print from then on would carry the whole application around the invoice, with
 * nothing on screen saying so. There is therefore exactly one node, in
 * `#invoice-print`, and the preview drawer *moves* it rather than rendering its
 * own: the `adoptForm()` pattern, for the reason `workspace.js` uses it.
 *
 * That is also why the preview drawer and the share dialog are in the layout
 * rather than in a module's fragment. The shell caches a module's root
 * **detached**, so a drawer declared inside one would carry the application's
 * only invoice sheet off the page the moment that module closed.
 *
 * ## Why the state is module-level and not per host
 *
 * Because there is one of everything it points at — one sheet, one preview, one
 * share dialog — and "which invoice is on screen" has exactly one answer at any
 * moment. Two hosts holding two answers is how a customer is sent somebody
 * else's bill.
 */

import auth from '../auth-client';
import { can } from '../permissions';
import { $, confirmAction, esc, formatDate, showModal, toast } from '../ui';
import { renderInvoice } from './invoice-document';

/*
| The document currently being handed over.
|
| Three surfaces point at it and they are all the same act: a module's drawer
| opened on a document from a list, the preview a freshly posted invoice lands
| on, and the job card's list of invoices raised off one repair. All print
| through the same node, publish through the same endpoint and address the same
| WhatsApp message; the only thing that differs is which of them was on screen
| when somebody asked.
|
| It is a *different payload* from anything a module holds — see
| InvoiceDocumentService for why the customer's document is built from its own
| list of fields rather than by filtering the internal one.
*/
const delivery = {
    /** The document being handed over. */
    id: null,
    /** What to call it before its own copy has arrived — `Invoice INV/26-27/12`. */
    label: '',
    /** The customer's copy, once anything has asked for it. */
    invoice: null,
    /** The live link for it, or null. From the invoice call's meta. */
    share: null,
    /**
     * Whether a link can be minted at all — the server's answer, never a guess.
     *
     * A draft has no number, no priced lines and no tax, and a reversed invoice
     * is one the customer must stop being able to open. Both are refused by
     * `InvoiceShareService`, and painting a Share button that would be refused
     * teaches somebody the product is unreliable.
     */
    shareable: false,
    /** What the host wants doing when the preview closes. Set per open. */
    onClosed: null,
};

/** One request at a time across the whole surface, whichever button started it. */
let busy = false;

/** `bindDelivery()` is idempotent — two modules mount this and both call it. */
let bound = false;

/**
 * Point the delivery at one document, dropping whatever was held for the last.
 *
 * Called when a host's drawer loads a document and when a post lands on the
 * preview — that is, at the two moments the answer to "which invoice" changes.
 * Showing invoice B's link on invoice A is how a customer is sent somebody
 * else's bill.
 *
 * The label tolerates a document that carries no `type_label`: the job card
 * lists the invoices raised off a repair from the job's own resource, which
 * names them but does not repeat what kind of document they are.
 */
export function deliver(bill) {
    delivery.id = bill.id;
    delivery.label = [bill.type_label, bill.doc_no ?? `#${bill.id}`].filter(Boolean).join(' ');
    delivery.invoice = null;
    delivery.share = null;
    delivery.shareable = false;
}

/**
 * The document as the customer would see it, fetched once per document.
 *
 * Its `meta` carries whether it is already shared and where, so opening the
 * share dialog costs nothing extra once Print has been pressed, and the other
 * way round — and the preview, which fetches it on the way up, pays for neither.
 */
export async function loadInvoice() {
    if (delivery.invoice) return delivery.invoice;

    const response = await auth.call(`/transactions/${delivery.id}/invoice`);

    delivery.invoice = response.data;
    delivery.share = response.meta?.share ?? null;
    delivery.shareable = response.meta?.shareable ?? false;

    return delivery.invoice;
}

/** Whether the document in hand can be published at all — for a host's footer. */
export const isShareable = () => delivery.shareable;

/**
 * Run one delivery action with the button that started it disabled while it goes.
 *
 * §3.4 — the user must never be left unsure whether something is processing, and
 * a second press on Share while the first is in flight is how a workshop ends up
 * minting two links for one invoice.
 *
 * An element rather than a selector, because these buttons live in the layout
 * rather than in any module's markup and a lookup scoped to a module root would
 * not find them.
 */
async function run(button, busyLabel, work) {
    if (busy) return;

    busy = true;

    const idle = button?.textContent;

    if (button) {
        button.disabled = true;
        button.textContent = busyLabel;
    }

    try {
        await work();
    } catch (error) {
        toast(error.message, 'error');
    } finally {
        busy = false;

        if (button && button.isConnected) {
            button.disabled = false;
            button.textContent = idle;
        }
    }
}

/* --- the one sheet, and whose it is at any moment ---------------------- */

const sheetHome = () => document.getElementById('invoice-print');
const sheet = () => document.querySelector('[data-invoice-document]');

/** Whether the preview is the surface currently on screen. */
const previewing = () => {
    const drawerNode = document.getElementById('invoice-preview');

    return drawerNode !== null && !drawerNode.classList.contains('hidden');
};

/**
 * Give the sheet back to `#invoice-print`, which is where printing needs it.
 *
 * Once it is gone the preview no longer contains a document, so the print rule
 * hides that drawer along with the rest of the chrome — which is why nothing
 * here has to know the drawer exists.
 */
function releaseSheet() {
    const node = sheet();

    if (node && node.parentElement !== sheetHome()) sheetHome().appendChild(node);
}

/** Borrow it back, if the preview is still the thing on screen. */
function borrowSheet() {
    if (!previewing()) return;

    const node = sheet();
    const host = document.querySelector('[data-invoice-preview-sheet]');

    if (node && host && node.parentElement !== host) host.appendChild(node);
}

/**
 * Print, without leaving the page.
 *
 * The sheet is mounted in the application's layout and hidden; painting it and
 * calling `print()` is the whole of it. No second window, so nothing for a
 * pop-up blocker to swallow and nothing to lose the drawer to — §1.1, and §3.2's
 * rule about never reloading, arrived at from the same direction.
 *
 * It is released first, because the preview may be holding it. `window.print()`
 * blocks in every browser this runs in, so it is back on screen by the time the
 * dialog closes; `afterprint` puts it back as well, for any browser where that
 * stops being true.
 */
export async function printInvoice(button) {
    await run(button, 'Preparing…', async () => {
        const invoice = await loadInvoice();

        releaseSheet();
        renderInvoice(sheet(), invoice);

        window.print();

        borrowSheet();
    });
}

/**
 * Ctrl+P, and any other print this application did not start.
 *
 * Without it a browser print taken while the preview is open finds the document
 * inside `<main>` and puts the whole application on the paper — the exact
 * failure the one-sheet rule exists to prevent, reached through the keyboard
 * instead of through a second copy.
 */
function bindPrintCustody() {
    window.addEventListener('beforeprint', releaseSheet);
    window.addEventListener('afterprint', borrowSheet);

    // Safari fires neither of those. It does report the media change.
    const printing = window.matchMedia?.('print');

    printing?.addEventListener?.('change', (event) => (event.matches ? releaseSheet() : borrowSheet()));
}

/* --- the preview a posted invoice lands on ----------------------------- */

/**
 * The customer's copy, the moment it exists — level 2, over the emptied form.
 *
 * §2A.8 keeps the operator on the form after a post, and that is still what
 * happens: the document is cleared for the next one behind this drawer, so
 * closing it puts the cursor back where the next customer starts. What it adds
 * is the half of raising an invoice that was missing — the customer leaves the
 * counter holding it, and until now the only route to that was to show the list,
 * find the row you had just written and open it again.
 *
 * A drawer rather than a modal because it is one record being viewed, which is
 * what level 2 is for (§2). The share dialog opens over it at level 3.
 *
 * Drafts never come here, and neither does a failed post. A draft has no number,
 * no priced lines and no tax — there is no document *of* it to hand anybody yet.
 *
 * @param {object} bill                 The posted document.
 * @param {object} [options]
 * @param {string} [options.title]      The heading — "Invoice raised" after a post.
 * @param {Function} [options.onClosed] What the host wants doing on the way out.
 */
export function openInvoicePreview(bill, { title = 'Invoice raised', onClosed = null } = {}) {
    deliver(bill);

    delivery.onClosed = onClosed;

    $('[data-invoice-preview-title]').textContent = title;
    $('[data-invoice-preview-subtitle]').textContent = delivery.label;

    showModal('#invoice-preview');

    return loadPreview();
}

/**
 * Fetch the copy and paint it. Also what **Try again** re-runs.
 *
 * Separate from opening so a retry is about the same document rather than about
 * whatever the caller still happens to be holding: `delivery` already knows
 * which invoice this is, and asking a second time must not be a second chance
 * to get that wrong.
 */
async function loadPreview() {
    $('[data-invoice-preview-actions]').innerHTML = '';

    // The sheet stays at home until there is something painted on it. The last
    // customer's invoice sliding in under this one's number is worse than a
    // moment of nothing, and a blank one reads as a document that came out empty.
    releaseSheet();
    previewStatus('Fetching the customer’s copy…');

    try {
        renderInvoice(sheet(), await loadInvoice());

        previewStatus(null);
        borrowSheet();
        paintPreviewActions();
    } catch (error) {
        /*
        | The invoice posted — that is not in doubt, and the toast behind this
        | said so. Only the copy of it failed to arrive. So the wording is about
        | the copy and the offer is to ask again: "something went wrong" over a
        | sale that is already in the books sends somebody looking for a bill
        | that is there.
        */
        previewStatus(error.message, true);

        $('[data-invoice-preview-actions]').innerHTML =
            '<button type="button" class="btn btn-secondary btn-sm" data-preview-retry>Try again</button>'
            + '<button type="button" class="btn btn-ghost btn-sm ml-auto" data-modal-close>Close</button>';
    }
}

function previewStatus(message, failed = false) {
    const slot = $('[data-invoice-preview-status]');

    slot.textContent = message ?? '';
    slot.classList.toggle('hidden', message === null);
    slot.classList.toggle('text-rose-600', failed);
    slot.classList.toggle('text-muted-foreground', !failed);
}

/**
 * What can still be done with the copy on screen.
 *
 * Gated exactly as a host's drawer footer is, and for the same reasons.
 * **Print** asks no more of a grant than reading does, because it draws the
 * document already on the screen. **Share** publishes it outside the workshop
 * and goes with WRITE — which is the grant the endpoint enforces and the grant
 * the person who just raised the invoice holds.
 */
function paintPreviewActions() {
    const buttons = [
        '<button type="button" class="btn btn-primary btn-sm" data-preview-print>Print</button>',
    ];

    if (delivery.shareable && can('WRITE', 'TRANSACTIONS')) {
        buttons.push('<button type="button" class="btn btn-secondary btn-sm" data-preview-share>Share</button>');
    }

    // Named for where it goes rather than for the act of closing, the same rule
    // the workspace's switch control follows (§2A.3): behind this drawer is an
    // empty form with the cursor waiting in it, and that is what somebody is
    // going back to — the next customer in the queue.
    buttons.push('<button type="button" class="btn btn-ghost btn-sm ml-auto" data-modal-close>'
        + 'Done</button>');

    $('[data-invoice-preview-actions]').innerHTML = buttons.join('');
}

/* --- publishing it ----------------------------------------------------- */

/**
 * The share dialog — level 3, over whichever surface asked for it.
 *
 * Every host opens this one, and it reads `delivery` rather than any of them:
 * publishing an invoice is one act, and a second copy of this panel is a second
 * place for "the link is already live" to be got wrong.
 *
 * Opened before the fetch resolves, with the panel saying so. Waiting on a
 * request with nothing on screen is how somebody comes to press Share twice.
 */
export async function openShare() {
    $('[data-share-subtitle]').textContent = delivery.label;

    $('[data-share-body]').innerHTML =
        '<p class="py-6 text-center text-sm text-muted-foreground">Loading…</p>';
    $('[data-share-actions]').innerHTML = '';

    showModal('#invoice-share-modal');

    try {
        await loadInvoice();
        paintShare();
    } catch (error) {
        $('[data-share-body]').innerHTML =
            `<p class="py-6 text-center text-sm text-rose-600">${esc(error.message)}</p>`;
    }
}

/**
 * WhatsApp, addressed to the customer where there is a number for them.
 *
 * `wa.me` rather than the `whatsapp://` scheme, because it works on a desktop
 * browser as WhatsApp Web and on a phone as the app, and the workshop's counter
 * is sometimes one and sometimes the other.
 *
 * Digits only, with 91 assumed for a ten-digit number. That assumption is safe
 * in the only product this is: the ledger is in rupees, the tax is GST and the
 * place of supply is a two-digit Indian state code. A number already carrying a
 * country code is left alone.
 */
function whatsappHref(url) {
    const digits = String(delivery.invoice?.customer?.phone ?? '').replace(/\D/g, '');
    const to = digits.length === 10 ? `91${digits}` : digits;

    const text = `${delivery.invoice.document.heading} ${delivery.invoice.document.doc_no ?? ''} `
        + `from ${delivery.invoice.workshop.name}: ${url}`;

    // With no number it still opens WhatsApp, on the contact chooser — which is
    // the right answer for a walk-in whose number the workshop never took.
    return `https://wa.me/${to}?text=${encodeURIComponent(text.replace(/\s+/g, ' ').trim())}`;
}

function paintShare() {
    const body = $('[data-share-body]');
    const actions = $('[data-share-actions]');

    if (delivery.share === null) {
        body.innerHTML = `
            <p class="text-[0.8125rem] text-secondary-foreground">
                This creates a link anybody holding it can open — no account, no password. Send it to
                <strong class="text-foreground">${esc(delivery.invoice?.customer?.name ?? 'the customer')}</strong>
                and it keeps working until you end it.
            </p>
            <p class="mt-2 text-[0.8125rem] text-muted-foreground">
                The page shows the invoice only: what was sold, the tax and what is owed. It never shows
                what anything cost the workshop.
            </p>`;

        actions.innerHTML = `
            <button type="button" class="btn btn-secondary btn-sm" data-modal-close>Not now</button>
            <button type="button" class="btn btn-primary btn-sm ml-auto" data-share-create>Create the link</button>`;

        return;
    }

    const url = delivery.share.url;

    body.innerHTML = `
        <label class="field-label" for="invoice-share-url">Anybody with this link can read the invoice</label>
        <input id="invoice-share-url" type="text" class="field-input font-mono text-[0.8125rem]"
               value="${esc(url)}" readonly data-share-url>

        <p class="mt-2 text-[0.8125rem] text-muted-foreground">
            Shared ${esc(formatDate(delivery.share.shared_at))}${
                delivery.share.shared_by ? ` by ${esc(delivery.share.shared_by)}` : ''
            }. It works until you end it.
        </p>

        <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" class="btn btn-secondary btn-sm" data-share-copy>Copy link</button>
            <a class="btn btn-secondary btn-sm" href="${esc(whatsappHref(url))}"
               target="_blank" rel="noopener noreferrer">Send on WhatsApp</a>
            <a class="btn btn-ghost btn-sm" href="${esc(url)}" target="_blank" rel="noopener noreferrer">
                Open it
            </a>
        </div>`;

    actions.innerHTML = `
        <button type="button" class="btn btn-ghost btn-sm" data-share-revoke>Stop sharing</button>
        <button type="button" class="btn btn-secondary btn-sm ml-auto" data-modal-close>Done</button>`;
}

async function createLink(button) {
    await run(button, 'Creating…', async () => {
        const response = await auth.call(`/transactions/${delivery.id}/share`, { method: 'POST' });

        delivery.share = response.data;

        paintShare();
        toast(response.message ?? 'Link ready to share.');
    });
}

async function revokeLink() {
    const ok = await confirmAction({
        title: 'Stop sharing this invoice?',
        body: 'The link stops working immediately, for everybody holding it. Sharing it again makes a '
            + 'different link — this one can never be brought back.',
        confirmLabel: 'Stop sharing',
    });

    if (!ok) return;

    await run(null, null, async () => {
        await auth.call(`/transactions/${delivery.id}/share`, { method: 'DELETE' });

        delivery.share = null;

        paintShare();
        toast('The link has stopped working.');
    });
}

async function copyLink() {
    try {
        await navigator.clipboard.writeText(delivery.share.url);
        toast('Link copied.');
    } catch {
        // Clipboard access is refused outright in some browsers and over plain
        // HTTP. The field is already selectable, so say that rather than failing.
        $('[data-share-url]')?.select();
        toast('Could not copy — the link is selected, copy it from there.', 'error');
    }
}

/* --- wiring ------------------------------------------------------------ */

/**
 * Bind the preview, the share dialog and custody of the sheet. Once, ever.
 *
 * Every host calls this on mount and the guard is what makes that safe: the
 * nodes are in the layout and outlive every module, so a second set of listeners
 * would mint two share links per press for as long as the tab is open.
 */
export function bindDelivery() {
    if (bound) return;

    bound = true;

    const preview = document.getElementById('invoice-preview');

    if (preview === null) return;

    // Delegated, because the footer is repainted between a failed fetch and the
    // copy arriving.
    preview.addEventListener('click', (event) => {
        const hit = (hook) => event.target.closest(`[${hook}]`);

        if (hit('data-preview-print')) printInvoice(hit('data-preview-print'));
        else if (hit('data-preview-share')) openShare();
        else if (hit('data-preview-retry')) loadPreview();
    });

    // The share dialog's own controls, delegated for the same reason: the panel
    // is repainted whenever the link is created or ended.
    document.getElementById('invoice-share-modal')?.addEventListener('click', (event) => {
        const hit = (hook) => event.target.closest(`[${hook}]`);

        if (hit('data-share-create')) createLink(hit('data-share-create'));
        else if (hit('data-share-revoke')) revokeLink();
        else if (hit('data-share-copy')) copyLink();
    });

    bindPrintCustody();

    /*
    | What closing the preview does — and it is watched rather than hooked onto
    | the close button, because there are three ways out of a drawer (the button,
    | the backdrop and Escape) and only two of them are clicks. `hideModal`
    | announces nothing, so the class it toggles is the one signal all three
    | share.
    |
    | The sheet goes home first. Left in a closed drawer it would still be out of
    | `#invoice-print`, and the next Ctrl+P anywhere in the application would come
    | out blank — with nothing on the screen to say why. Handing it back on the
    | way out means the borrowing lasts exactly as long as the preview does, and
    | the print events become a second line of defence rather than the only one.
    |
    | Then whatever the host that opened it wanted doing — putting the cursor back
    | where the next document starts (§2A.8).
    */
    new MutationObserver(() => {
        if (previewing()) return;

        releaseSheet();

        const closed = delivery.onClosed;

        delivery.onClosed = null;

        closed?.();
    }).observe(preview, { attributeFilter: ['class'] });
}
