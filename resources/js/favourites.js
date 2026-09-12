/**
 * Favourites — the cards a workshop opens every day, lifted to the top of home.
 *
 * ## The problem
 *
 * §1.3 gives home one card per module that exists, and there are nineteen of
 * them. That is right — a hand-picked few is exactly what the card grid replaced
 * — but it no longer fits a laptop viewport, so the three cards a counter
 * actually uses sit below the fold behind sixteen it does not.
 *
 * This is the smallest thing that fixes it without becoming a second navigation
 * (§1.2): one more group, at the top, made of the *same* cards.
 *
 * ## Moved, not copied
 *
 * A favourite's card node is relocated into the favourites grid, and relocated
 * back when it is unstarred. There is still exactly one node per module, which
 * matters in three separate places:
 *
 * - `shell.js` reads its label registry and answers `permitted()` off
 *   `[data-module-card]`. Two nodes per key would be two answers to one question.
 * - `PagesRenderTest` asserts one card per enabled module, and a card appearing
 *   twice on one screen reads as a bug rather than as a feature.
 * - the permission pass has already run on the node by the time anything moves,
 *   so a favourite this reader may not open is already `hidden` and is not
 *   lifted. The stored list belongs to the workshop; what each person sees of it
 *   narrows to their own grants without being stored per person.
 *
 * Moving a node out of a group can empty that group, so a group heading with
 * nothing visible left under it is hidden — otherwise an owner who stars both of
 * the cards in a band gets a "People" heading with a rule and nothing under it.
 *
 * ## Per workshop, and written by whoever configures the workshop
 *
 * The list lives on the tenant and arrives in the `tenant` block of `/auth/me`,
 * which the dashboard already fetches before it paints anything — so reading it
 * costs no request and needs no grant. Writing it is `UPDATE:WORKSPACE`, enforced
 * on the route and reflected here only by the stars being gated in the markup.
 *
 * The write is optimistic: the card moves on the click and is put back if the
 * request fails. A card that sat still for a round trip would be the wrong
 * trade-off on the one control somebody uses while arranging a screen.
 */

import auth from './auth-client';
import { $, $$, toast } from './ui';

/**
 * How many cards may sit above the grid.
 *
 * The server holds the same figure in `UpdateFavouriteModulesRequest::MAX` and
 * that is the one that is enforced; this copy exists so the refusal arrives
 * before the request rather than as a 422 on a click.
 */
const MAX = 8;

/** The starred keys, in the order they are shown. */
let keys = [];

/**
 * The list the server last confirmed.
 *
 * What a failed write falls back to — not the state from immediately before that
 * write, which on the second of two fast clicks would be a list the server never
 * agreed to either.
 */
let saved = [];

/** The in-flight write, and whether the list moved again while it ran. */
let writing = false;
let again = false;

/**
 * Each group's grid and the order it was rendered in.
 *
 * Recorded before anything moves, because a card that has been lifted no longer
 * knows which group it came from — and the order is needed as well as the grid:
 * appending an unstarred card back would leave it at the end of its group rather
 * than where it has always been.
 *
 * @type {Map<Element, string[]>}
 */
const groups = new Map();

function cardFor(key) {
    return $(`[data-module-card="${key}"]`);
}

/* -------------------------------------------------------------------------
 | Painting
 | ---------------------------------------------------------------------- */

/**
 * Put the cards where the list says they go, and say so on the stars.
 *
 * Idempotent and driven entirely by {@link keys}, so every caller — the first
 * paint, a star, an unstar, a failed write rolled back — takes one code path
 * rather than four that have to agree with each other.
 */
function paint() {
    const section = $('[data-favourites]');
    const grid = $('[data-favourites-grid]');

    if (!section || !grid) return;

    /*
    | A hidden card is not lifted. The gating pass has already run, so this is
    | how the workshop's one list narrows to each reader's own grants: a starred
    | module they hold no grant for stays where it is, invisible, instead of
    | heading a row they cannot use.
    */
    const lifted = keys
        .map((key) => cardFor(key))
        .filter((card) => card !== null && !card.classList.contains('hidden'));

    const favourites = new Set(lifted.map((card) => card.dataset.moduleCard));

    /*
    | Moving a node takes the focus off whatever was inside it, so a keyboard
    | user pressing Enter on a star was dropped onto the document — on the one
    | control whose whole job is to move the thing it sits on. Noted here and put
    | back after the moves.
    */
    const focused = document.activeElement?.dataset?.star ?? null;

    // In list order, so the row reads the way the owner arranged it rather than
    // the way config/modules.php happens to be written.
    lifted.forEach((card) => grid.append(card));

    /*
    | And everything else back into its own group, in the order it was declared.
    |
    | Every non-favourite is re-appended rather than only the one just unstarred:
    | `append` on a node that is already there moves it to the end, so walking the
    | recorded order restores the whole group every time. One unstarred card
    | appended on its own would land at the end of its group instead.
    */
    groups.forEach((ordered, groupGrid) => {
        ordered
            .filter((key) => !favourites.has(key))
            .forEach((key) => groupGrid.append(cardFor(key)));
    });

    $$('[data-star]').forEach((star) => {
        const card = star.closest('[data-module-card]');
        const on = keys.includes(star.dataset.star);
        const label = $('.card-title', card)?.textContent.trim() ?? 'this card';

        star.classList.toggle('is-on', on);
        star.setAttribute('aria-pressed', String(on));
        star.setAttribute('aria-label', on ? `Unstar ${label}` : `Star ${label}`);
    });

    /*
    | The section is shown when it holds a card, and also when it holds none but
    | this reader could put one there — that second case is the only thing that
    | teaches the star exists. For everybody else an empty favourites row would
    | be a heading over nothing, so it stays out of the document.
    */
    const hint = $('[data-favourites-empty]', section);
    const canEdit = hint !== null && !hint.classList.contains('hidden');

    hint?.toggleAttribute('hidden', lifted.length > 0);
    section.hidden = lifted.length === 0 && !canEdit;

    paintEmptyGroups();

    // Last, because the section it is in may only just have become visible —
    // focus() on a node inside a `hidden` element does nothing at all.
    if (focused) $(`[data-star="${focused}"]`)?.focus();
}

/**
 * Hide a group heading whose cards have all been lifted out of it.
 *
 * Separate from the permission pass that hides a group's individual cards, and it
 * has to be: that runs once at boot, and this changes every time somebody stars
 * something.
 */
function paintEmptyGroups() {
    $$('[data-module-group]').forEach((group) => {
        const visible = $$('[data-module-card]', group)
            .some((card) => !card.classList.contains('hidden'));

        group.hidden = !visible;
    });
}

/* -------------------------------------------------------------------------
 | Writing
 | ---------------------------------------------------------------------- */

/**
 * Send the list, and keep sending it while it keeps changing.
 *
 * Serialised rather than concurrent, because two PUTs racing would leave the
 * column holding whichever arrived last rather than whichever was clicked last.
 * But a click during a write is *queued* rather than dropped: somebody arranging
 * this screen stars three cards in a row, and on a slow connection a guard that
 * simply returned would silently save only the first.
 */
async function persist() {
    if (writing) {
        again = true;

        return;
    }

    writing = true;

    try {
        do {
            again = false;

            const attempt = [...keys];

            await auth.call('/workspace/favourites', {
                method: 'PUT',
                body: { favourite_modules: attempt },
            });

            saved = attempt;
        } while (again);
    } catch (error) {
        keys = [...saved];
        paint();
        toast(error.message ?? 'That could not be saved. Please try again.', 'error');
    } finally {
        writing = false;
        again = false;
    }
}

/** Star or unstar one card. The screen moves first; the request follows. */
function toggle(key) {
    const starred = keys.includes(key);

    if (!starred && keys.length >= MAX) {
        toast(`Up to ${MAX} cards can be starred. Unstar one first.`, 'error');

        return;
    }

    keys = starred ? keys.filter((other) => other !== key) : [...keys, key];
    paint();
    persist();
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

/**
 * Called from app.js once the session is known and the permission pass has run.
 *
 * Both are prerequisites rather than preferences: the list comes off the user's
 * workshop, and only a visible card can be lifted.
 *
 * @param {{tenant?: {favourite_modules?: string[]}|null}} user
 */
export function initFavourites(user) {
    if (!$('[data-favourites]')) return;

    $$('[data-module-card]').forEach((card) => {
        const grid = card.closest('.card-grid');

        if (!grid) return;

        if (!groups.has(grid)) groups.set(grid, []);

        groups.get(grid).push(card.dataset.moduleCard);
    });

    /*
    | A key with no card is dropped rather than held. The server already filters
    | out a module that has been switched off, so this is the case where one was
    | removed from the registry altogether — and keeping it would mean the next
    | write sent it back, spending one of the eight on a card that cannot appear.
    */
    keys = (user?.tenant?.favourite_modules ?? []).filter((key) => cardFor(key) !== null);
    saved = [...keys];

    paint();

    /*
    | Delegated, and `closest()` rather than `matches()` because the button wraps
    | an <svg>. The star sits outside `[data-open]`, so shell.js's own delegated
    | handler does not read this click as a card opening — and because it is the
    | card's sibling rather than its child, nothing here has to stop propagation
    | to keep it that way.
    */
    document.addEventListener('click', (event) => {
        const star = event.target.closest('[data-star]');

        if (!star) return;

        event.preventDefault();
        toggle(star.dataset.star);
    });
}
