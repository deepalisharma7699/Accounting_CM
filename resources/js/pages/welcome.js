/**
 * The shopfront — resources/views/welcome.blade.php.
 *
 * The public page, and the only screen a visitor sees before signing in. The
 * sign-in form itself is *not* wired up here: it carries the same ids the
 * standalone /login page did, so resources/js/app.js binds initLogin() to it
 * exactly as before and the credential flow is untouched. All this module does
 * is decide when the modal is on screen.
 *
 * Everything else is presentation — the header's state, the mobile nav, the
 * sections arriving as they are scrolled to, and the counters. All of it is
 * skipped outright when the visitor has asked not to be moved.
 */

import { $, $$, initModals, showModal } from '../ui';

const STILL = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/* -------------------------------------------------------------------------
 | Header
 | ---------------------------------------------------------------------- */

/**
 * Transparent over the hero, solid once the page has moved.
 *
 * The hero is dark and the header sits on top of it, so it needs no background
 * until it is over the light sections below — at which point white-on-white
 * would be unreadable.
 */
function initHeader() {
    const header = $('[data-lp-header]');

    if (!header) return;

    const SOLID = ['bg-slate-950/85', 'backdrop-blur-md', 'border-white/10', 'shadow-lg'];

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
    const button = $('[data-lp-menu]');
    const panel = $('#lp-mobile-nav');

    if (!button || !panel) return;

    const setOpen = (open) => {
        panel.classList.toggle('hidden', !open);
        button.setAttribute('aria-expanded', String(open));
        button.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
        $('[data-lp-menu-open]', button).classList.toggle('hidden', open);
        $('[data-lp-menu-close]', button).classList.toggle('hidden', !open);
    };

    button.addEventListener('click', () => setOpen(panel.classList.contains('hidden')));

    // Following an anchor inside the panel should close it, or the section it
    // jumped to would be underneath the panel that sent you there.
    $$('[data-lp-nav]', panel).forEach((link) => link.addEventListener('click', () => setOpen(false)));
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
 * branch here.
 */
function initReveal() {
    const targets = $$('[data-reveal]');

    if (!targets.length) return;

    // No observer, no motion — but never no content: everything is shown.
    if (STILL || !('IntersectionObserver' in window)) {
        targets.forEach((el) => el.classList.add('lp-in'));

        return;
    }

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;

                entry.target.classList.add('lp-in');
                observer.unobserve(entry.target);
            });
        },
        // Fires a little before the element's edge clears the fold, so it is
        // finished arriving by the time it is properly in view.
        { rootMargin: '0px 0px -12% 0px', threshold: 0.12 },
    );

    targets.forEach((el) => observer.observe(el));
}

/**
 * Count each figure up from zero when its band first appears.
 *
 * Eased out rather than linear, so the number slows into its final value
 * instead of stopping dead on it. The element's text starts at "0" and the
 * target is in the markup, so a visitor without JavaScript sees a zero rather
 * than nothing — which is why every counter's band also carries its label.
 */
function initCounters() {
    const counters = $$('[data-count-to]');

    if (!counters.length) return;

    if (STILL || !('IntersectionObserver' in window)) {
        counters.forEach((el) => {
            el.textContent = el.dataset.countTo;
        });

        return;
    }

    const run = (el) => {
        const target = Number(el.dataset.countTo);
        const started = performance.now();
        const DURATION = 1400;

        const tick = (now) => {
            const progress = Math.min((now - started) / DURATION, 1);
            // easeOutCubic
            const eased = 1 - (1 - progress) ** 3;

            el.textContent = String(Math.round(target * eased));

            if (progress < 1) requestAnimationFrame(tick);
        };

        requestAnimationFrame(tick);
    };

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;

                run(entry.target);
                observer.unobserve(entry.target);
            });
        },
        { threshold: 0.6 },
    );

    counters.forEach((el) => observer.observe(el));
}

/* -------------------------------------------------------------------------
 | Sign in
 | ---------------------------------------------------------------------- */

/**
 * Open the sign-in modal from either of the two buttons that offer it, and on
 * arrival from /login.
 *
 * A session that has expired anywhere in the application redirects to /login,
 * which now lands here with `?login=1` — so somebody who was signed in a moment
 * ago gets the form rather than a marketing page and a hunt for the button. The
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
    initCounters();
    initLoginModal();
}
