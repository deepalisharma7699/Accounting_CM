import { renderInvoice } from './components/invoice-document';
import { toast } from './ui';

/**
 * The customer's copy — the whole of the JavaScript at `/i/{token}`.
 *
 * Its own Vite entry rather than a page inside `app.js`, and for the reason the
 * shopfront has its own stylesheet: nothing behind the sign-in belongs on a page
 * anybody may open. `app.js` carries the auth client, the permission gating and
 * the module shell, and its very first act on an unrecognised page is to redeem
 * a refresh cookie and redirect to /login — which is precisely what must not
 * happen to a customer who has no account and is not trying to get one.
 *
 * So this ships the renderer and four buttons. The document itself is already in
 * the page: the controller embeds it, so a phone on a shop's wifi makes one
 * request and the invoice is either there or the page did not load. There is no
 * second fetch to fail halfway.
 */

function payload() {
    const node = document.getElementById('invoice-payload');

    if (!node) return null;

    try {
        return JSON.parse(node.textContent);
    } catch {
        return null;
    }
}

/**
 * What the customer would send on, in words rather than as a URL.
 *
 * The URL goes in the share sheet's `url` field where the platform can render
 * it as a link; repeating it in the text as well produces a message with the
 * address in it twice, which is what most hand-rolled share buttons do.
 */
function shareText(invoice) {
    const doc = invoice.document;

    return `${doc.heading} ${doc.doc_no ?? ''} from ${invoice.workshop.name}`.replace(/\s+/g, ' ').trim();
}

export default function initPublicInvoice() {
    const invoice = payload();
    const root = document.querySelector('[data-invoice-document]');

    if (!invoice || !root) return;

    renderInvoice(root, invoice);

    document.title = `${invoice.document.doc_no ?? invoice.document.heading} · ${invoice.workshop.name}`;

    document.querySelector('[data-invoice-print]')?.addEventListener('click', () => window.print());

    /*
    | The platform's own share sheet where there is one — which on the phone
    | this page is opened on means WhatsApp, the contact list, and everything
    | else the customer already uses, rather than a row of icons guessing at it.
    |
    | Hidden entirely where `navigator.share` does not exist, rather than shown
    | and made to fall back: on a desktop browser the copy button beside it is
    | the honest answer, and it is already there.
    */
    const share = document.querySelector('[data-invoice-share]');

    if (share && typeof navigator.share === 'function') {
        share.hidden = false;

        share.addEventListener('click', async () => {
            try {
                await navigator.share({
                    title: shareText(invoice),
                    text: shareText(invoice),
                    url: window.location.href,
                });
            } catch {
                // A cancelled share sheet rejects exactly like a failed one, and
                // "Could not share" over a sheet somebody deliberately dismissed
                // is the product arguing with them.
            }
        });
    }

    document.querySelector('[data-invoice-copy]')?.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(window.location.href);
            toast('Link copied.');
        } catch {
            toast('Could not copy the link — you can copy it from the address bar.', 'error');
        }
    });
}

document.addEventListener('DOMContentLoaded', initPublicInvoice);
