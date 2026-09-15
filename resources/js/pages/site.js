/**
 * The shopfront — resources/views/site/*.
 *
 * The public site, and the only screen a visitor sees before signing in. One
 * module for both pages: the home page and a service page share a header, a
 * nameplate guide and an action bar, and every function below returns early
 * when the thing it drives is not in the document.
 *
 * The sign-in form is *not* wired up here. It carries the same ids the
 * standalone /login page did, so initLogin() in resources/js/app.js binds to it
 * exactly as before and the credential flow is untouched. All this module does
 * with it is decide when the dialog is on screen.
 *
 * Everything else is presentation, and all of it is additive: the page is
 * complete, readable and navigable with this file never loading. Sections are
 * visible (the <noscript> block in the layout sees to that), the reference
 * tables are all three in the markup, and the questions are <details> that open
 * on their own. Nothing here is load-bearing, which is the right shape for a
 * page whose visitor is on a phone with one bar of signal.
 */

import { $, $$, initModals, showModal } from '../ui';

const STILL = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/* -------------------------------------------------------------------------
 | Header
 | ---------------------------------------------------------------------- */

/**
 * Transparent over a dark hero, solid once the page has moved under it.
 *
 * Both pages open on an ink band with the header sitting on top of it, so it
 * needs no background until it is over the paper sections below — at which
 * point white-on-white would be unreadable.
 */
function initHeader() {
    const header = $('[data-site-header]');

    if (!header) return;

    const SOLID = ['bg-ink-950/85', 'backdrop-blur-md', 'border-white/10', 'shadow-lg'];

    const sync = () => {
        const scrolled = window.scrollY > 24;

        SOLID.forEach((cls) => header.classList.toggle(cls, scrolled));
        header.classList.toggle('border-transparent', !scrolled);
    };

    sync();
    window.addEventListener('scroll', sync, { passive: true });
}

/** The hamburger, and the panel it reveals. */
function initMobileNav() {
    const button = $('[data-site-menu]');
    const panel = $('#site-mobile-nav');

    if (!button || !panel) return;

    const setOpen = (open) => {
        panel.classList.toggle('hidden', !open);
        button.setAttribute('aria-expanded', String(open));
        $('[data-site-menu-open]', button).classList.toggle('hidden', open);
        $('[data-site-menu-close]', button).classList.toggle('hidden', !open);
    };

    button.addEventListener('click', () => setOpen(panel.classList.contains('hidden')));

    // Following an anchor inside the panel should close it, or the section it
    // jumped to would be underneath the panel that sent you there. The same
    // goes for the sign-in button: it opens a dialog over the page, and the
    // panel would otherwise still be standing behind it when the dialog closes.
    $$('[data-site-nav], [data-login-open]', panel).forEach((el) => el.addEventListener('click', () => setOpen(false)));
}

/* -------------------------------------------------------------------------
 | Arriving
 | ---------------------------------------------------------------------- */

/**
 * Reveal each marked element the first time it is scrolled into view.
 *
 * Observed rather than driven off a scroll handler, and unobserved once it has
 * fired: an element only ever arrives once, and there are a few dozen of them.
 *
 * The offsets and delays live in the markup as CSS custom properties, so a card
 * that should come in from the left is a `style` attribute rather than another
 * branch in here.
 */
function initReveal() {
    const targets = $$('[data-reveal]');

    if (!targets.length) return;

    // No observer, no motion — but never no content: everything is shown.
    if (STILL || !('IntersectionObserver' in window)) {
        targets.forEach((el) => el.classList.add('s-in'));

        return;
    }

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;

                entry.target.classList.add('s-in');
                observer.unobserve(entry.target);
            });
        },
        // Fires a little before the element's edge clears the fold, so it has
        // finished arriving by the time it is properly in view.
        { rootMargin: '0px 0px -12% 0px', threshold: 0.12 },
    );

    targets.forEach((el) => observer.observe(el));
}

/* -------------------------------------------------------------------------
 | The nameplate guide
 | ---------------------------------------------------------------------- */

/**
 * Tie each explanation to its field on the plate.
 *
 * Pointing at "RPM" in the list lights up the RPM box on the plate, and
 * hovering the box lights up the row. Both directions, because a visitor
 * reading the list and a visitor studying the plate are two different people
 * and the section has to teach either of them where to look.
 *
 * Bound on focus as well as hover so it works from a keyboard, and on
 * `pointerenter` rather than `mouseenter` so a tap on a phone lights the pair
 * up too — on a touch screen there is no hover, and without this the whole
 * section would be inert on the device most of these visitors are holding.
 */
function initNameplate() {
    const plate = $('[data-plate]');

    if (!plate) return;

    const fields = $$('[data-plate-field]');
    const rows = $$('[data-plate-row]');

    if (!fields.length || !rows.length) return;

    const light = (key) => {
        fields.forEach((el) => el.classList.toggle('is-lit', el.dataset.plateField === key));
        rows.forEach((el) => el.classList.toggle('is-lit', el.dataset.plateRow === key));
    };

    const clear = () => light(null);

    [
        [rows, 'plateRow'],
        [fields, 'plateField'],
    ].forEach(([elements, key]) => {
        elements.forEach((el) => {
            el.addEventListener('pointerenter', () => light(el.dataset[key]));
            el.addEventListener('focus', () => light(el.dataset[key]));
            el.addEventListener('pointerleave', clear);
            el.addEventListener('blur', clear);
        });
    });
}

/* -------------------------------------------------------------------------
 | Reference tables
 | ---------------------------------------------------------------------- */

/**
 * Show one of the three reference tables at a time.
 *
 * All three panels are rendered — they are content, and a crawler and a
 * visitor without JavaScript should have every row. So the first thing this
 * does is hide the two that are not selected: the markup ships them open, and
 * the script is what makes them tabs. Doing it the other way round would mean a
 * failed script left sixty rows of table stacked on the page.
 */
function initReferenceTabs() {
    const root = $('[data-ref-tabs]');

    if (!root) return;

    const tabs = $$('[data-ref-tab]', root);
    const panels = $$('[data-ref-panel]', root);

    if (!tabs.length || !panels.length) return;

    const ON = ['border-ink-950', 'bg-ink-950', 'text-white'];
    const OFF = ['border-ink-200', 'bg-white', 'text-ink-600', 'hover:border-ink-400'];

    const select = (key) => {
        panels.forEach((panel) => {
            panel.hidden = panel.dataset.refPanel !== key;
        });

        tabs.forEach((tab) => {
            const on = tab.dataset.refTab === key;

            tab.setAttribute('aria-selected', String(on));
            ON.forEach((cls) => tab.classList.toggle(cls, on));
            OFF.forEach((cls) => tab.classList.toggle(cls, !on));
        });
    };

    tabs.forEach((tab) => tab.addEventListener('click', () => select(tab.dataset.refTab)));

    select(tabs[0].dataset.refTab);
}

/* -------------------------------------------------------------------------
 | Questions
 | ---------------------------------------------------------------------- */

/**
 * One answer open at a time.
 *
 * The markup is plain <details>, which already opens and closes on its own —
 * this only closes the others, so the list does not grow to three screens as
 * somebody works down it. Nothing here is required for an answer to be
 * readable, which is the point: these answers are also the page's FAQ
 * structured data.
 */
function initFaq() {
    const items = $$('[data-faq] details');

    if (items.length < 2) return;

    items.forEach((item) => {
        item.addEventListener('toggle', () => {
            if (!item.open) return;

            items.forEach((other) => {
                if (other !== item) other.open = false;
            });
        });
    });
}

/* -------------------------------------------------------------------------
 | The bar at the bottom of a phone
 | ---------------------------------------------------------------------- */

/**
 * Reveal the call/WhatsApp bar once the hero's own buttons have gone by.
 *
 * Two calls to action on screen at once is neither of them, so the bar stays
 * off-screen while the hero is in view and slides up afterwards. Watching the
 * hero's button rather than a scroll offset means it behaves the same on a
 * short phone and a tall desktop without a breakpoint anywhere.
 */
function initActionBar() {
    const bar = $('[data-site-actionbar]');
    const anchor = $('[data-site-hero-cta]');

    if (!bar) return;

    // Nothing to watch, or nothing to watch with: show it and be done. An
    // always-visible bar is a small cost; a bar that never appears is the
    // page's main call to action missing on a phone.
    if (!anchor || !('IntersectionObserver' in window)) {
        bar.classList.remove('is-hidden');

        return;
    }

    const observer = new IntersectionObserver(
        ([entry]) => bar.classList.toggle('is-hidden', entry.isIntersecting),
        { threshold: 0 },
    );

    observer.observe(anchor);
}

/* -------------------------------------------------------------------------
 | Sign in
 | ---------------------------------------------------------------------- */

/**
 * Open the sign-in dialog from the button that offers it, and on arrival from
 * /login.
 *
 * A session that has expired anywhere in the application redirects to /login,
 * which lands here with `?login=1` — so somebody who was signed in a moment ago
 * gets the form rather than a marketing page and a hunt for the button. The
 * parameter is then dropped from the address bar, because it describes how this
 * page was reached and not what it is.
 */
function initLoginModal() {
    const modal = $('#login-modal');

    if (!modal) return;

    // Backdrop clicks, the close button and Escape, shared with the rest of
    // the application's modals.
    initModals();

    $$('[data-login-open]').forEach((button) => {
        button.addEventListener('click', () => showModal(modal));
    });

    const params = new URLSearchParams(window.location.search);

    if (!params.has('login')) return;

    showModal(modal);

    params.delete('login');

    const query = params.toString();

    window.history.replaceState({}, '', window.location.pathname + (query ? `?${query}` : '') + window.location.hash);
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

export default function init() {
    initHeader();
    initMobileNav();
    initReveal();
    initNameplate();
    initReferenceTabs();
    initFaq();
    initActionBar();
    initLoginModal();
}
