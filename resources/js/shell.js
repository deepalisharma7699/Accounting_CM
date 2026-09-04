/**
 * The shell — CLAUDE.md §1 and §2.
 *
 * One page, one mounted chrome, and one region beneath it that swaps:
 *
 *     level 0   the dashboard's card grid
 *     level 1   a module workspace, mounted in place
 *     level 2   a drawer over it, owned by the module
 *     level 3   a confirm, owned by ui.js
 *
 * Nothing here navigates. Opening a module swaps `#view-module`'s contents and
 * pushes a URL; it never loads a document, and there is no `location.reload()`
 * anywhere in the flow.
 *
 * ## The cache is the whole design
 *
 * `#view-module` holds at most one *attached* module root. The others sit
 * detached in {@link mounted}, and reopening a module re-attaches its node
 * rather than fetching or initialising anything. That one decision does three
 * jobs at once:
 *
 * - **State survives the round trip** (§2A.6, §3.6). The half-typed draft, the
 *   search box's text, the applied filters and the fetched rows are all still
 *   in the live DOM and in the page module's own closure. Returning to a module
 *   refetches nothing because there is nothing to refetch.
 * - **Each page module initialises exactly once.** Every `pages/*.js` binds
 *   listeners to `document`; running one twice would double every handler. It
 *   cannot happen, because `default()` is called on the first open only.
 * - **A module is paid for once** (§2.5, §7.2). Markup, code and data all
 *   arrive on first open. A module never opened costs nothing at all.
 */

import { applyPermissionGates, can, hasWorkspace } from './permissions';
import { $, $$, esc, toast } from './ui';

/** Page modules are loaded lazily so the dashboard never ships the CRUD code. */
const PAGES = {
    accounts: () => import('./pages/accounts'),
    audit: () => import('./pages/audit'),
    bills: () => import('./pages/bills'),
    // Two screens over one `parties` table — both are thin wrappers around
    // ./pages/counterparty, which is where the shared behaviour lives.
    customers: () => import('./pages/customers'),
    // M23 — the insight panels and M12's four statements under one card.
    insights: () => import('./pages/insights'),
    items: () => import('./pages/items'),
    jobs: () => import('./pages/jobs'),
    journal: () => import('./pages/journal'),
    ledger: () => import('./pages/ledger'),
    opening: () => import('./pages/opening'),
    purchase: () => import('./pages/purchase'),
    roles: () => import('./pages/roles'),
    sales: () => import('./pages/sales'),
    // M22 — four §2A workspaces under one card, mounted lazily one tab at a time.
    staff: () => import('./pages/staff'),
    stock: () => import('./pages/stock'),
    tenants: () => import('./pages/tenants'),
    uploads: () => import('./pages/uploads'),
    users: () => import('./pages/users'),
    vendors: () => import('./pages/vendors'),
    workspace: () => import('./pages/workspace'),
};

/** moduleKey -> the mounted root element, attached or detached. */
const mounted = new Map();

/** moduleKey -> a handler that unwinds one level inside that module, or null. */
const escapeHandlers = new Map();

/** The open module's key, or null on the home grid. */
let current = null;

/**
 * The open module's deep-link intent — `?type=sale`, `?new=expense`.
 *
 * Held here rather than read from `location.search`, because a module's URL is
 * now a fragment of the dashboard's: `/dashboard#bills?type=sale`. Modules read
 * it through {@link moduleParams}.
 */
let params = new URLSearchParams();

/** Guards against a second open while the first is still fetching. */
let opening = null;

/* -------------------------------------------------------------------------
 | The card registry, read back off the grid
 |
 | The labels come from the cards themselves rather than from a copy of
 | config/modules.php written out into JavaScript. A second registry is a second
 | thing to keep in step, and the first symptom of the drift would be a
 | breadcrumb calling a module something the card did not.
 | ---------------------------------------------------------------------- */

const labels = new Map();

/** The document's own title, before any module is appended to it. */
let baseTitle = '';

function readRegistry() {
    $$('[data-module-card]').forEach((wrapper) => {
        const key = $('[data-open]', wrapper)?.dataset.open;
        const label = $('.card-title', wrapper)?.textContent.trim();

        if (key && label) labels.set(key, label);
    });
}

function labelFor(key) {
    return labels.get(key) ?? key;
}

/**
 * Say so when the grid comes out empty.
 *
 * A platform super-admin holds every grant but belongs to no workshop, so every
 * workshop-scoped card is stripped for them and home has nothing on it. Only
 * this runs after the gating pass, so only this knows. A blank page with no
 * explanation reads as a broken one.
 */
function paintEmptyHome() {
    const visible = $$('[data-module-card]').some((card) => !card.classList.contains('hidden'));

    $('[data-no-modules]')?.classList.toggle('hidden', visible);
}

/**
 * May this session open that module?
 *
 * The cards are gated already, so this only matters for a URL somebody typed or
 * kept from before their grants changed. Presentation either way — every
 * endpoint behind the module is guarded server-side regardless (§6.1, §6.2).
 */
function permitted(key) {
    const wrapper = $(`[data-module-card] [data-open="${key}"]`)?.closest('[data-module-card]');

    if (!wrapper) return false;

    const grant = wrapper.dataset.requiresPermission;
    const [action, resource] = grant ? grant.split(':') : [];

    return (!grant || can(action, resource))
        && (wrapper.dataset.requiresWorkspace === undefined || hasWorkspace());
}

/* -------------------------------------------------------------------------
 | Chrome
 | ---------------------------------------------------------------------- */

/**
 * Paint the breadcrumb for whichever level is on screen.
 *
 * Two elements, one shown at a time, rather than one whose text is rewritten:
 * the workshop's name stays where app.js paints it from /auth/me, so coming back
 * from a module needs no memory of what it said.
 */
function paintCrumb(key) {
    const inModule = Boolean(key);

    $('[data-crumb-back]').hidden = !inModule;
    $('[data-crumb-home]').hidden = !inModule;
    $('[data-crumb-sep]').hidden = !inModule;
    $('[data-crumb-workspace]').hidden = inModule;

    const module = $('[data-crumb-module]');
    module.hidden = !inModule;
    module.textContent = inModule ? labelFor(key) : '';

    // Captured once, or each open would prepend to the previous open's title.
    document.title = inModule ? `${labelFor(key)} · ${baseTitle}` : baseTitle;
}

/** Restart the entrance animation on a view that is already in the document. */
function animate(view) {
    view.classList.remove('view-in');
    void view.offsetWidth;
    view.classList.add('view-in');
}

/* -------------------------------------------------------------------------
 | Mounting a module
 | ---------------------------------------------------------------------- */

function busyState(key) {
    return `
        <div class="ws-header">
            <div>
                <h1 class="ws-title">${esc(labelFor(key))}</h1>
                <p class="ws-sub">Opening…</p>
            </div>
        </div>
        <div class="surface form-card">
            ${Array.from({ length: 4 }, () => '<span class="skel mb-4 w-2/3"></span>').join('')}
        </div>`;
}

function failedState(key, message) {
    return `
        <div class="ws-header">
            <div>
                <h1 class="ws-title">${esc(labelFor(key))}</h1>
                <p class="ws-sub">This module could not be opened.</p>
            </div>
        </div>
        <div class="surface form-card">
            <p class="text-sm text-muted-foreground">${esc(message)}</p>
            <div class="form-foot">
                <button type="button" class="btn btn-primary" data-open-retry>Try again</button>
                <button type="button" class="btn btn-secondary" data-crumb-home>Back to home</button>
            </div>
        </div>`;
}

/**
 * Fetch a module's markup, mount it, gate it, and run its page module — once.
 *
 * The fragment is markup, not data: every figure inside still arrives from the
 * guarded /api/v1 endpoints once the page module runs.
 */
async function mount(key) {
    const host = $('#view-module');

    if (mounted.has(key)) {
        const root = mounted.get(key);
        host.replaceChildren(root);

        /*
        | A module that is already up cannot read its intent from `default()`,
        | because `default()` ran on the first open and will not run again. So a
        | second deep link — a different row of the attention list, say — is
        | announced instead. A module that has no deep links ignores it.
        */
        if ([...params.keys()].length) {
            root.dispatchEvent(new CustomEvent('module:params', { detail: params }));
        }

        return;
    }

    host.innerHTML = busyState(key);

    const response = await fetch(`/modules/${key}`, {
        headers: { Accept: 'text/html' },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error(
            response.status === 404
                ? 'That module does not exist.'
                : 'The server could not send this module. Please try again.',
        );
    }

    const root = document.createElement('div');
    root.dataset.moduleRoot = key;
    root.innerHTML = await response.text();

    // Before the markup is on screen, so nothing a user may not see is ever
    // painted — even for the frame it would take to strip it afterwards.
    applyPermissionGates(root);

    mounted.set(key, root);
    host.replaceChildren(root);

    const load = PAGES[key];

    if (load) {
        const module = await load();
        await module.default();
    }
}

/* -------------------------------------------------------------------------
 | The swap
 | ---------------------------------------------------------------------- */

/**
 * What a module was opened *for*, when it was opened by a link rather than by
 * its card.
 *
 * The dashboard's attention list points at `/bills?payment=overdue`; the shell
 * turns that into `/dashboard#bills?payment=overdue` and opens the module in
 * place. A module reads its intent here instead of from `location.search`, which
 * belongs to the shell now.
 */
export function moduleParams() {
    return params;
}

/**
 * Forget the intent, and take it out of the URL.
 *
 * Once acted on, it must not survive a refresh or a Back — reopening a form
 * somebody has just closed is worse than not offering the deep link at all.
 */
export function clearModuleParams() {
    if (![...params.keys()].length) return;

    params = new URLSearchParams();

    if (current) history.replaceState({ module: current }, '', `/dashboard#${current}`);
}

export async function openModule(key, { push = true, search = '' } = {}) {
    if (!PAGES[key]) {
        toast('That module is not available.', 'error');

        return;
    }

    if (!permitted(key)) {
        /*
        | A URL that was typed, or kept from before this module was switched off
        | or the session's grants changed. One message covers both on purpose:
        | it is true either way, and "you may not see this" tells somebody
        | probing for screens more than they need to know (§6.3).
        */
        toast('That module is not available.', 'error');
        goHome({ push: true });

        return;
    }

    // A second card clicked while the first is still arriving would race two
    // mounts into the same host.
    if (opening) return;

    current = key;
    params = new URLSearchParams(search.replace(/^\?/, ''));

    const home = $('#view-home');
    const view = $('#view-module');

    home.hidden = true;
    view.hidden = false;
    animate(view);

    paintCrumb(key);

    // §2.4 — the address bar tracks state and never triggers a load.
    const url = `/dashboard#${key}${search ? `?${search.replace(/^\?/, '')}` : ''}`;

    if (push) history.pushState({ module: key }, '', url);

    opening = key;

    try {
        await mount(key);
    } catch (error) {
        view.innerHTML = failedState(key, error.message ?? 'Something went wrong.');
        $('[data-open-retry]', view)?.addEventListener('click', () => {
            opening = null;
            openModule(key, { push: false });
        });
    } finally {
        opening = null;
    }
}

export function goHome({ push = true } = {}) {
    current = null;

    $('#view-module').hidden = true;

    const home = $('#view-home');
    home.hidden = false;
    animate(home);

    paintCrumb(null);

    if (push) history.pushState({ module: null }, '', '/dashboard');
}

/**
 * Let a module unwind one level of its own before Escape leaves it.
 *
 * The workspace registers the list → form step here, so a press on the list
 * returns to the create form rather than dropping the user all the way home
 * (§2A.9).
 */
export function registerEscape(key, handler) {
    escapeHandlers.set(key, handler);
}

export function currentModule() {
    return current;
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

export function initShell() {
    baseTitle = document.title;
    readRegistry();
    paintEmptyHome();

    document.addEventListener('click', (event) => {
        // closest(), not matches(): a card wraps an icon and two spans, so a
        // click reports whichever of those was under the pointer.
        const opener = event.target.closest('[data-open]');

        if (opener) {
            event.preventDefault();
            openModule(opener.dataset.open);

            return;
        }

        if (event.target.closest('[data-crumb-back], [data-crumb-home]')) {
            event.preventDefault();
            goHome();

            return;
        }

        /*
        | A link to where a module used to live.
        |
        | The dashboard's tiles and its attention list point at `/bills?type=sale`
        | and the like, and some of those hrefs come from the API rather than
        | from markup here. Following one would be a document load into a
        | redirect into another document load — so the shell recognises the path
        | and opens the module in place, intent and all (§1.1, §3.2).
        |
        | Modified clicks are left alone: somebody asking for a new tab means it,
        | and the redirect will land them on the right module.
        */
        const link = event.target.closest('a[href]');

        if (!link || event.defaultPrevented) return;
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
        if (link.target && link.target !== '_self') return;

        const url = new URL(link.href, location.href);

        if (url.origin !== location.origin) return;

        const key = url.pathname.replace(/^\/|\/$/g, '');

        if (!PAGES[key]) return;

        event.preventDefault();
        openModule(key, { search: url.search });
    });

    /* Back and forward move between the grid and a module — no document load. */
    window.addEventListener('popstate', (event) => {
        /*
        | The URL is read, not just the history entry.
        |
        | Back and forward between the grid and a module carry `state.module`,
        | but a hash typed into the address bar of a page that is already open is
        | a same-document navigation with no state of its own — and it should
        | still open that module rather than quietly doing nothing.
        |
        | The intent rides in the URL too, so Back onto a filtered module
        | restores the filter with it.
        */
        const [hash = '', search = ''] = location.hash.replace(/^#/, '').split('?');
        const key = hash || event.state?.module || null;

        if (!key) {
            goHome({ push: false });

            return;
        }

        openModule(key, { push: false, search });
    });

    /*
    | Escape unwinds one level per press: modal → drawer → list → module → home.
    |
    | The inner two levels belong to ui.js, which keeps the stack of open dialogs
    | — drawers carry `data-modal` too, so one handler unwinds both. This one
    | takes the outer two.
    |
    | **Capture phase, and that is the whole trick.** ui.js and the account menu
    | listen in the bubble phase, so on a plain listener this would run *after*
    | they had already closed what was open, see a clear screen and unwind a
    | second level — one press closing a drawer and leaving the list as well.
    | Capturing means this looks at the screen as the user saw it when they
    | pressed the key, stands down if anything is open over the workspace, and
    | lets the bubble handlers do the one step.
    */
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || !current) return;

        if ($('[data-modal]:not(.hidden)')) return;
        if ($('[data-user-menu-panel]:not(.hidden)')) return;

        if (escapeHandlers.get(current)?.()) return;

        goHome();
    }, true);

    /*
    | A deep link. `/items` redirects to `/dashboard#items`, and a bookmark of
    | either lands here — so the fragment is read once on boot and opened without
    | a second load. `#bills?payment=overdue` carries an intent with it.
    */
    const [key = '', search = ''] = location.hash.replace(/^#/, '').split('?');

    history.replaceState({ module: key || null }, '', location.href);

    if (key && PAGES[key]) openModule(key, { push: false, search });
    else paintCrumb(null);
}
