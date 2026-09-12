/**
 * The global activity indicator — one bar, for every request the application
 * makes.
 *
 * ## Why this is not a per-screen decision
 *
 * There were four loaders before this file and all four were typed by hand at a
 * call site: `setSubmitting()` on a form's button, `tableMessage(…, 'Loading…')`
 * in a list body, a `paintLoading()` skeleton on three panels, and the shell's
 * own `busyState()` while a module's markup arrives. Between them they covered
 * roughly forty of the hundred and seventy-odd places this application asks the
 * server for something — and the forty were, without exception, the ones that
 * either submitted a form or repainted a table.
 *
 * Everything else showed nothing at all. A drawer fetching one record, a picker
 * searching, a component loading its options, the opening-balance tiles, the
 * settings screen: all of them sat there looking finished while a request was in
 * flight, which is exactly what §3.4 forbids — the user must never be left
 * unsure whether something is processing.
 *
 * That is the same failure `data-bus.js` records about writes, and it takes the
 * same answer: a convention that every call site remembers to say "I am busy"
 * fails silently, one call site at a time. So it is not a convention. Every
 * request already goes through `auth.call()`, and that is where this is driven
 * from — there is nowhere else to drive it from, so a call site added next year
 * is covered without its author knowing this file exists.
 *
 * ## The two timers, which are the whole of the design
 *
 * A bar that appears the instant a request starts is worse than no bar. Most of
 * these calls answer in well under a tenth of a second, and something that
 * flashes on and off at the top of the screen on every keystroke-adjacent action
 * reads as a rendering fault rather than as feedback.
 *
 *   DELAY  nothing is painted for the first 250ms. A request that beats that is
 *          not worth reporting, and the screen stays still.
 *   FLOOR  once painted, it stays for at least 400ms. Without this a request
 *          finishing at 260ms would show the bar for ten milliseconds — the
 *          flicker the delay was meant to prevent, arriving from the other side.
 *
 * ## It counts, rather than toggling
 *
 * Several requests overlap constantly — a module's first open fires its meta
 * call and its list together, and a drawer fetches while its parent list is
 * still arriving. A boolean would have the first one to finish switch the bar
 * off with three still running. So it is a count, and the bar is up while the
 * count is above nought.
 *
 * ## What it deliberately does not do
 *
 * It does not block. A full-screen scrim over every request would stop somebody
 * typing the next line of a bill while its price preview is being fetched, which
 * is the single most common thing anybody does in this application. The bar is a
 * statement about the connection, not a lock on the page.
 *
 * And it is never the *only* feedback for a write. `setSubmitting()` still
 * disables the button that was pressed, because "the server is busy" and "the
 * button you just pressed is the reason" are two different things to say, and
 * the second is the one that stops a double post.
 */

/** Nothing is painted for a request that answers faster than this. */
const DELAY = 250;

/** Once painted, it stays at least this long. See the note above. */
const FLOOR = 400;

/** Requests currently in flight. */
let pending = 0;

/** Set while waiting out DELAY; cleared if everything finishes first. */
let showTimer = null;

/** Set while waiting out FLOOR; cleared if a new request starts meanwhile. */
let hideTimer = null;

/** When the bar was actually painted, for the FLOOR calculation. */
let shownAt = 0;

/**
 * The bar itself, resolved once.
 *
 * `undefined` means "not looked for yet"; `null` means "looked for and this
 * document has none" — the sign-in screen and the shopfront use a different
 * layout, and a missing bar there is correct rather than an error. Cached either
 * way, so this is not a `querySelector` on every request.
 */
let bar;

function element() {
    if (bar === undefined) {
        bar = document.getElementById('global-loader') ?? null;
    }

    return bar;
}

function paint() {
    // Cleared before the guard below, not after it. On a document with no bar —
    // the sign-in screen, the shopfront — an early return here would leave a
    // spent timer id in `showTimer`, and `beginRequest` reads it as "a paint is
    // already scheduled" and stops scheduling for the life of the page.
    showTimer = null;

    const el = element();

    if (!el) return;

    shownAt = Date.now();

    el.hidden = false;
    // Next frame, so the transition has an "off" state to start from — setting
    // both on the same frame paints it at full opacity with no fade at all.
    requestAnimationFrame(() => el.classList.add('is-active'));
}

function clear() {
    const el = element();

    hideTimer = null;

    if (!el) return;

    el.classList.remove('is-active');

    // Left in the document, but out of the accessibility tree and out of the
    // way, once the fade has finished. Removing it immediately would cut the
    // fade off halfway.
    setTimeout(() => {
        if (pending === 0) el.hidden = true;
    }, 200);
}

/**
 * A request has started.
 *
 * Exported as well as used by `auth-client`, because the one request that does
 * not go through `auth.call()` — the shell fetching a module's markup — is still
 * a dynamic load and still worth reporting.
 */
export function beginRequest() {
    pending += 1;

    if (pending > 1) return;

    // A request arriving inside the FLOOR window of the last one: the bar is
    // still up, so keep it up rather than letting it drop and re-appear.
    if (hideTimer) {
        clearTimeout(hideTimer);
        hideTimer = null;

        return;
    }

    if (!showTimer) showTimer = setTimeout(paint, DELAY);
}

/** A request has finished — successfully or not. Always call this in a finally. */
export function endRequest() {
    pending = Math.max(0, pending - 1);

    if (pending > 0) return;

    // Finished inside DELAY: nothing was ever painted, so there is nothing to
    // take down and the screen never moved.
    if (showTimer) {
        clearTimeout(showTimer);
        showTimer = null;

        return;
    }

    if (hideTimer) return;

    const remaining = Math.max(0, FLOOR - (Date.now() - shownAt));

    hideTimer = setTimeout(clear, remaining);
}

/**
 * Run a promise under the bar.
 *
 * For the callers outside `auth.call()`. The `finally` is the point: a request
 * that throws must still take the bar down, or one failed fetch leaves the
 * application looking permanently busy.
 */
export async function track(promise) {
    beginRequest();

    try {
        return await promise;
    } finally {
        endRequest();
    }
}
