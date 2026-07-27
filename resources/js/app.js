import auth from './auth-client';
import { applyPermissionGates, setGrants } from './permissions';
import { $, $$, initModals, toast } from './ui';

/* -------------------------------------------------------------------------
 | Chrome
 | ---------------------------------------------------------------------- */

function initial(name) {
    return (name || '?').trim().charAt(0).toUpperCase();
}

/** Paint the signed-in user into the sidebar, topbar and greeting. */
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
}

function initChrome() {
    const sidebar = $('[data-sidebar]');
    const scrim = $('[data-sidebar-scrim]');

    const setOpen = (open) => {
        sidebar?.classList.toggle('max-lg:-translate-x-full', !open);
        scrim?.classList.toggle('hidden', !open);
    };

    $('[data-sidebar-open]')?.addEventListener('click', () => setOpen(true));
    $('[data-sidebar-collapse]')?.addEventListener('click', () => setOpen(false));
    scrim?.addEventListener('click', () => setOpen(false));

    document.addEventListener('keydown', (event) => {
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            $('[data-search]')?.focus();
        }

        if (event.key === 'Escape') setOpen(false);
    });

    initLogout();
}

/**
 * Sign-out control in the sidebar footer.
 *
 * Delegated from the document rather than bound to the button, so a click that
 * lands before the session has finished hydrating still works, and so the
 * handler survives any future re-render of the sidebar.
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
 | Login page
 | ---------------------------------------------------------------------- */

function initLogin(form) {
    const banner = $('#login-error');
    const submit = $('[data-submit]', form);
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
        submit.disabled = busy;
        spinner.classList.toggle('hidden', !busy);
        label.textContent = busy ? 'Signing in…' : 'Sign in';
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
            await auth.login($('#email', form).value.trim(), $('#password', form).value);

            window.location.assign('/dashboard');
        } catch (error) {
            showError(error);
            setBusy(false);
        }
    });
}

/* -------------------------------------------------------------------------
 | Authenticated pages
 | ---------------------------------------------------------------------- */

/** Page modules are loaded lazily so the dashboard never ships the CRUD code. */
const PAGES = {
    users: () => import('./pages/users'),
    roles: () => import('./pages/roles'),
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
    applyPermissionGates();

    initChrome();
    initModals();

    const page = document.body.dataset.page;
    const load = PAGES[page];

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
    const loginForm = $('#login-form');

    if (loginForm) {
        initLogin(loginForm);

        return;
    }

    initAuthenticatedPage();
});
