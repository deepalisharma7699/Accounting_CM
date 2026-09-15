import auth from './auth-client';
import { mountPasskeyManager } from './components/passkey-manager';
import { initFavourites } from './favourites';
import passkeys from './passkeys';
import { applyPermissionGates, setGrants, setWorkspace } from './permissions';
import { focusSearch, initSearch } from './search';
import { initShell } from './shell';
import { $, $$, clearFormErrors, initModals, showFormErrors, showModal, toast } from './ui';

/* -------------------------------------------------------------------------
 | Chrome
 | ---------------------------------------------------------------------- */

function initial(name) {
    return (name || '?').trim().charAt(0).toUpperCase();
}

/** Paint the signed-in user into the topbar and the greeting. */
function renderUser(user) {
    $$('[data-user-initial]').forEach((el) => {
        el.textContent = initial(user.name);
    });

    $$('[data-user-name]').forEach((el) => {
        el.textContent = user.name;
    });

    $$('[data-user-role]').forEach((el) => {
        el.textContent = user.role?.name ?? 'No role assigned';
    });

    $$('[data-user-firstname]').forEach((el) => {
        el.textContent = user.name.split(' ')[0];
        // Drop the loading skeleton once there is real text to show.
        el.classList.remove('min-w-24', 'rounded', 'bg-muted', 'text-transparent');
    });

    renderWorkspace(user);
}

/**
 * Which workshop's books this session is looking at — or that it is looking at
 * none, for a platform super-admin.
 *
 * Worth showing plainly: a platform admin holds every permission, so without
 * this the only signal that they are outside any workshop is a failed request.
 */
function renderWorkspace(user) {
    const workshop = user.tenant ?? null;

    $$('[data-workspace-name]').forEach((el) => {
        el.textContent = workshop?.name ?? 'Platform administration';
    });

    $$('[data-workspace-scope]').forEach((el) => {
        el.textContent = workshop ? 'Workshop' : 'No workshop · manages tenants';
    });
}

/**
 * The topbar's account menu, and the search shortcut.
 *
 * This is all that is left of `initChrome()` now the sidebar has gone (§1.2):
 * there is no drawer to slide, no scrim and no collapse. Identity and sign-out
 * moved into this menu, which is also where somebody checks which workshop they
 * are signed in to before signing out of it.
 */
function initChrome() {
    const menu = $('[data-user-menu]');

    if (!menu) {
        initLogout();

        return;
    }

    const toggle = $('[data-user-menu-toggle]', menu);
    const panel = $('[data-user-menu-panel]', menu);

    const setOpen = (open) => {
        panel.classList.toggle('hidden', !open);
        toggle.setAttribute('aria-expanded', String(open));
    };

    toggle.addEventListener('click', (event) => {
        event.stopPropagation();
        setOpen(panel.classList.contains('hidden'));
    });

    // Anywhere else closes it — including inside the panel, where every control
    // either signs out or is a label.
    document.addEventListener('click', () => setOpen(false));

    document.addEventListener('keydown', (event) => {
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            // Focus *and* select, so a second press retypes the last term rather
            // than dropping the caret into the middle of it — see search.js.
            focusSearch();
        }

        // The menu is the innermost thing on screen while it is open, so a press
        // closes it and goes no further. The shell's own Escape handling checks
        // for the same panel and stands down while it is up.
        if (event.key === 'Escape') setOpen(false);
    });

    initLogout();
    initSecurityDrawer();
    initSearch();
}

/**
 * "Sign-in security" in the account menu, and the drawer behind it.
 *
 * The manager is mounted once — the drawer lives in the layout and never
 * unmounts — and its `open()` is what fetches the list, so a session that never
 * opens this screen never asks for it (§7.2).
 *
 * Delegated from the document for the same reason initLogout() is: the chrome
 * hydrates after the markup lands, and a click that arrives first should still
 * work.
 */
function initSecurityDrawer() {
    const manager = mountPasskeyManager(document);

    if (!manager) return;

    document.addEventListener('click', (event) => {
        // closest(), not matches(): the control wraps an <svg>.
        if (!event.target.closest('[data-security-open]')) return;

        manager.open();
        showModal('#security-drawer');
    });
}

/**
 * Sign-out control in the topbar's account menu.
 *
 * Delegated from the document rather than bound to the button, so a click that
 * lands before the session has finished hydrating still works, and so the
 * handler survives any future re-render of the chrome.
 */
function initLogout() {
    document.addEventListener('click', async (event) => {
        // closest(), not matches(): the button wraps an <svg> icon, so a click
        // on the glyph reports the svg (or its <path>) as event.target.
        const button = event.target.closest('[data-logout]');

        if (!button || button.dataset.busy === '1') return;

        event.preventDefault();
        button.dataset.busy = '1';
        button.disabled = true;

        try {
            await auth.logout();
        } catch {
            // A network failure must not strand the user on a page whose token
            // is already gone — the redirect happens either way. The refresh
            // token is revoked server-side on the next presentation regardless.
        } finally {
            window.location.replace('/login');
        }
    });
}

/* -------------------------------------------------------------------------
 | Unauthenticated forms: sign in, sign up
 | ---------------------------------------------------------------------- */

/**
 * Shared plumbing for the two credential forms: field errors, busy state and
 * the password reveal. Only the request and the destination differ.
 *
 * The refusal itself is `showFormErrors()` from ui.js, the same call every form
 * behind the sign-in makes — so a wrong password is reported where a rejected
 * expense is: marked on the field, stated under the button that was pressed,
 * and repeated in the alert top right. These two forms used to carry a banner
 * of their own at the top of the form and a second set of field-error hooks,
 * which is two conventions for one thing (§4.4).
 *
 * The *busy* state stays local. `setSubmitting()` swaps a button's text, and
 * these two buttons hold a spinner and a label element rather than text.
 */
function initAuthForm(form, { idleLabel, busyLabel, submit, redirectTo }) {
    const button = $('[data-submit]', form);
    const spinner = $('[data-spinner]', form);
    const label = $('[data-submit-label]', form);

    const setBusy = (busy) => {
        button.disabled = busy;
        spinner.classList.toggle('hidden', !busy);
        label.textContent = busy ? busyLabel : idleLabel;
    };

    const toggle = $('[data-toggle-password]', form);
    toggle?.addEventListener('click', () => {
        const input = $('#password', form);
        const hidden = input.type === 'password';

        input.type = hidden ? 'text' : 'password';
        toggle.setAttribute('aria-label', hidden ? 'Hide password' : 'Show password');
        $('[data-icon-show]', toggle).classList.toggle('hidden', hidden);
        $('[data-icon-hide]', toggle).classList.toggle('hidden', !hidden);
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearFormErrors(form);
        setBusy(true);

        try {
            await submit(form);

            window.location.assign(redirectTo);
        } catch (error) {
            showFormErrors(form, error);
            setBusy(false);
        }
    });
}

/* -------------------------------------------------------------------------
 | Signing in with a passkey
 | ---------------------------------------------------------------------- */

/**
 * The passkey half of the sign-in dialog.
 *
 * Two ways in, both ending in the same two API calls: a challenge, and the
 * assertion the device signs it with.
 *
 *   the button    — an explicit tap, which opens the browser's account picker.
 *   autofill      — `mediation: 'conditional'`, which puts the same passkeys
 *                   inside the email field's own autofill list. It is armed on
 *                   load and waits, invisibly, until somebody focuses the
 *                   field. This is the smoothest path there is: no button, no
 *                   dialog, and nothing typed.
 *
 * The block starts hidden and is revealed only once the browser has confirmed
 * it can perform a ceremony at all. A fingerprint button on a machine with no
 * authenticator is a dead end on the one screen nobody can get past.
 *
 * Only one WebAuthn request may be in flight at a time, so the conditional one
 * is aborted before the explicit one starts — without that, tapping the button
 * while autofill is armed rejects with an unhelpful InvalidStateError.
 */
async function initPasskeySignIn(root) {
    const panel = $('#passkey-signin', root);
    const button = $('[data-passkey-signin]', root);

    if (!panel || !button || !passkeys.isSupported()) return;

    const spinner = $('[data-passkey-spinner]', button);
    const icon = $('[data-passkey-icon]', button);
    const label = $('[data-passkey-label]', button);
    const error = $('[data-passkey-error]', root);
    const idleLabel = label.textContent;
    const busyLabel = label.dataset.busy || 'Waiting for your device…';

    let conditional = null;

    const setBusy = (busy) => {
        button.disabled = busy;
        spinner.classList.toggle('hidden', !busy);
        icon.classList.toggle('hidden', busy);
        label.textContent = busy ? busyLabel : idleLabel;
    };

    // Beneath the button, and in the alert top right — the same two places a
    // form's refusal is shown. It is deliberately not the password form's
    // banner: 'that did not work' under a password field, to somebody who never
    // typed one, reads as 'your password is wrong'.
    const showError = (message) => {
        error.textContent = message;
        error.classList.toggle('hidden', !message);

        if (message) toast(message, 'error');
    };

    /** One ceremony, from challenge to session. */
    const signIn = async (options) => {
        const { state, options: publicKey } = await auth.passkeyLoginOptions();

        const credential = await passkeys.get(publicKey, options);

        await auth.passkeyLogin(state, credential);

        window.location.assign('/dashboard');
    };

    button.addEventListener('click', async () => {
        showError('');

        // Stand the autofill request down first: the browser allows one.
        conditional?.abort();
        conditional = null;

        setBusy(true);

        try {
            await signIn();
        } catch (err) {
            // Closing the sheet is a decision, not a failure. Saying "that
            // passkey could not be verified" to somebody who chose to type
            // their password instead is answering a question they did not ask.
            if (!passkeys.wasDismissed(err)) {
                showError(err.message || 'That did not work. Try your password instead.');
            }

            setBusy(false);
        }
    });

    // Reveal it now the browser has agreed it can do this at all.
    panel.classList.remove('hidden');

    if (!(await passkeys.hasConditionalMediation())) return;

    conditional = new AbortController();

    try {
        await signIn({ signal: conditional.signal, mediation: 'conditional' });
    } catch {
        /*
         * Silent by design. This request was never asked for — it sits waiting
         * on the off-chance the field is focused — so every way it can end
         * (aborted for the button, no passkey chosen, the dialog dismissed, the
         * page closed) is ordinary. An error here would be the page complaining
         * about something nobody did.
         */
    }
}

function initLogin(form) {
    initAuthForm(form, {
        idleLabel: 'Sign in',
        busyLabel: 'Signing in…',
        redirectTo: '/dashboard',
        submit: () => auth.login($('#email', form).value.trim(), $('#password', form).value),
    });
}

function initRegister(form) {
    initAuthForm(form, {
        idleLabel: 'Create workshop',
        busyLabel: 'Creating…',
        // Straight to the workspace settings, which is where a new owner has
        // something left to do: confirm the GSTIN and the financial year before
        // anything is posted. The module opens inside the shell, so this is the
        // dashboard with that fragment rather than a page of its own.
        redirectTo: '/dashboard#workspace?welcome=1',
        submit: () => auth.register({
            workshop_name: $('#workshop_name', form).value.trim(),
            gstin: $('#gstin', form).value.trim().toUpperCase() || null,
            name: $('#name', form).value.trim(),
            email: $('#email', form).value.trim(),
            password: $('#password', form).value,
            password_confirmation: $('#password_confirmation', form).value,
        }),
    });
}

/* -------------------------------------------------------------------------
 | Authenticated pages
 | ---------------------------------------------------------------------- */

/*
| There is no per-page registry any more, and there is nothing left for one to
| hold.
|
| Every module used to be a page with its own entry point. They are cards on the
| dashboard now, opened in the mounted shell — so the lazy-import table moved to
| shell.js, which is what consumes it. The counter at /bills/new was the last
| page with code of its own, and C4 retired it along with the route: a workshop
| bill is raised from the job it came off.
|
| `dashboard` hydrates nothing of its own either. Home is the module grid and
| nothing else, rendered entirely by Blade.
*/

async function initAuthenticatedPage() {
    // A full page load starts with no token in memory, so the HttpOnly refresh
    // cookie is redeemed for one before anything is rendered.
    const user = await auth.bootstrapSession();

    if (!user) {
        window.location.replace('/login');

        return;
    }

    renderUser(user);
    setGrants(user.permissions ?? []);
    // Tenancy gates independently of permissions: a platform super-admin holds
    // every grant but belongs to no workshop, so the workshop nav must stay
    // hidden for them.
    setWorkspace(user.tenant_id ?? null);
    applyPermissionGates();

    initChrome();
    initModals();

    // The level-0/level-1 swap, and the only authenticated document there is.
    if (document.body.dataset.page === 'dashboard') {
        /*
        | Before initShell(), and after applyPermissionGates() above — both
        | matter. The gating pass decides which cards are visible, and only a
        | visible card can be lifted into the favourites row; initShell() then
        | reads its label registry and paints the empty-home hint off whatever
        | the grid has ended up looking like.
        */
        initFavourites(user);
        initShell();
    }
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

document.addEventListener('DOMContentLoaded', () => {
    /*
    | The public site carries the sign-in form in a modal rather than being a
    | sign-in page, so it boots two things: its own behaviour, and — below,
    | through the same branch every credential form takes — the unchanged login
    | handler. Loaded lazily, so none of the public site's code is shipped to
    | the screens behind the login.
    |
    | One key for every public page: the home page and the service pages share
    | a header, a nameplate guide and an action bar, and resources/js/pages/site
    | returns early for whatever is not in the document it landed on.
    */
    if (document.body.dataset.page === 'site') {
        import('./pages/site').then((module) => module.default());
    }

    const loginForm = $('#login-form');

    if (loginForm) {
        initLogin(loginForm);

        // Deliberately not awaited: it ends in a request that waits for the
        // person to focus the email field, which may be never. Awaiting it
        // here would hold up everything after this line for the life of the
        // page.
        initPasskeySignIn(document);

        return;
    }

    const registerForm = $('#register-form');

    if (registerForm) {
        initRegister(registerForm);

        return;
    }

    initAuthenticatedPage();
});
