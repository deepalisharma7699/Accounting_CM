import auth from './auth-client';
import { applyPermissionGates, setGrants, setWorkspace } from './permissions';
import { initShell } from './shell';
import { $, $$, initModals, toast } from './ui';

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
            $('[data-search]')?.focus();
        }

        // The menu is the innermost thing on screen while it is open, so a press
        // closes it and goes no further. The shell's own Escape handling checks
        // for the same panel and stands down while it is up.
        if (event.key === 'Escape') setOpen(false);
    });

    initLogout();
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
 * Shared plumbing for the two credential forms: field errors, banner, busy
 * state and the password reveal. Only the request and the destination differ.
 */
function initAuthForm(form, { bannerId, idleLabel, busyLabel, submit, redirectTo }) {
    const banner = $(bannerId);
    const button = $('[data-submit]', form);
    const spinner = $('[data-spinner]', form);
    const label = $('[data-submit-label]', form);

    const clearErrors = () => {
        banner.classList.add('hidden');
        banner.classList.remove('flex');
        $$('[data-field-error]', form).forEach((el) => {
            el.textContent = '';
            el.classList.add('hidden');
        });
    };

    const showError = (error) => {
        // 422 comes back with per-field messages; everything else is a single
        // human-readable message on the envelope.
        if (error.fields) {
            Object.entries(error.fields).forEach(([field, messages]) => {
                const el = $(`[data-field-error="${field}"]`, form);
                if (el) {
                    el.textContent = messages[0];
                    el.classList.remove('hidden');
                }
            });
        }

        $('[data-error-message]', banner).textContent = error.message;
        banner.classList.remove('hidden');
        banner.classList.add('flex');
    };

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
        clearErrors();
        setBusy(true);

        try {
            await submit(form);

            window.location.assign(redirectTo);
        } catch (error) {
            showError(error);
            setBusy(false);
        }
    });
}

function initLogin(form) {
    initAuthForm(form, {
        bannerId: '#login-error',
        idleLabel: 'Sign in',
        busyLabel: 'Signing in…',
        redirectTo: '/dashboard',
        submit: () => auth.login($('#email', form).value.trim(), $('#password', form).value),
    });
}

function initRegister(form) {
    initAuthForm(form, {
        bannerId: '#register-error',
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
| The one page shell that still has code of its own.
|
| Every module used to have one. They are cards on the dashboard now, opened in
| the mounted shell — so the lazy-import registry that used to live here moved to
| shell.js, which is what consumes it.
|
| `dashboard` is absent, and that is the point: home is the module grid and
| nothing else, so it is rendered entirely by Blade and hydrates nothing. The
| module that used to paint its figures went with the sections it filled.
|
| `bill-counter` is the counter at /bills/new, still a page: a modal cannot host
| a search-first item picker, a running total, a keyboard flow and a confirmation
| step without becoming a scroll trap.
*/
const SHELLS = {
    'bill-counter': () => import('./pages/bill-counter'),
};

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

    const page = document.body.dataset.page;

    // The level-0/level-1 swap. Only the dashboard carries the two views it
    // moves between; the counter is a page and has neither.
    if (page === 'dashboard') initShell();

    const load = SHELLS[page];

    if (!load) return;

    try {
        const module = await load();
        await module.default();
    } catch (error) {
        toast(error.message ?? 'Something went wrong loading this page.', 'error');
    }
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

document.addEventListener('DOMContentLoaded', () => {
    /*
    | The public page carries the sign-in form in a modal rather than being a
    | sign-in page, so it boots two things: its own behaviour, and — below,
    | through the same branch every credential form takes — the unchanged login
    | handler. Loaded lazily, so none of the marketing page's code is shipped to
    | the screens behind the login.
    */
    if (document.body.dataset.page === 'welcome') {
        import('./pages/welcome').then((module) => module.default());
    }

    const loginForm = $('#login-form');

    if (loginForm) {
        initLogin(loginForm);

        return;
    }

    const registerForm = $('#register-form');

    if (registerForm) {
        initRegister(registerForm);

        return;
    }

    initAuthenticatedPage();
});
