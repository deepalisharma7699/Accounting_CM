/**
 * Shared UI primitives for the CRUD modules: escaping, modals, toasts and the
 * form-error plumbing that maps the API's 422 envelope onto fields.
 */

export const $ = (selector, root = document) => root.querySelector(selector);
export const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

/** Anything interpolated into innerHTML goes through here. */
export function esc(value) {
    if (value === null || value === undefined) return '';

    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

export function debounce(fn, wait = 300) {
    let timer;

    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), wait);
    };
}

export function formatDate(value) {
    if (!value) return '—';

    return new Date(value).toLocaleDateString(undefined, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

/* -------------------------------------------------------------------------
 | Toast
 | ---------------------------------------------------------------------- */

const TOAST_TONES = {
    success: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    error: 'border-rose-200 bg-rose-50 text-rose-700',
    info: 'border-border bg-card text-foreground',
};

export function toast(message, tone = 'success') {
    let host = $('#toast-host');

    if (!host) {
        host = document.createElement('div');
        host.id = 'toast-host';
        host.className = 'fixed bottom-5 right-5 z-[60] flex flex-col gap-2';
        host.setAttribute('aria-live', 'polite');
        document.body.append(host);
    }

    const node = document.createElement('div');
    node.className = `surface flex items-center gap-2 px-4 py-3 text-sm font-medium shadow-raised ${TOAST_TONES[tone] ?? TOAST_TONES.info}`;
    node.textContent = message;
    host.append(node);

    setTimeout(() => {
        node.style.opacity = '0';
        node.style.transition = 'opacity .25s';
        setTimeout(() => node.remove(), 250);
    }, 3200);
}

/* -------------------------------------------------------------------------
 | Modal
 | ---------------------------------------------------------------------- */

let openModal = null;

export function showModal(id) {
    const modal = typeof id === 'string' ? $(id) : id;
    if (!modal) return;

    modal.classList.remove('hidden');
    openModal = modal;

    // Focus the first usable control so the dialog is keyboard-ready.
    setTimeout(() => $('input:not([type=hidden]), select, textarea, button', modal)?.focus(), 30);
}

export function hideModal(id) {
    const modal = typeof id === 'string' ? $(id) : id;
    if (!modal) return;

    modal.classList.add('hidden');

    if (openModal === modal) openModal = null;
}

/** Backdrop click + Escape close whichever modal is open. */
export function initModals() {
    document.addEventListener('click', (event) => {
        // closest(), not matches(): the close button wraps an <svg>, so a click
        // on the icon glyph reports the svg (or its <path>) as event.target.
        const closer = event.target.closest('[data-modal-close]');

        if (closer) {
            hideModal(closer.closest('[data-modal]'));

            return;
        }

        // Only the backdrop itself, never a click inside the panel.
        if (event.target.matches('[data-modal]')) {
            hideModal(event.target);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && openModal) hideModal(openModal);
    });
}

/* -------------------------------------------------------------------------
 | Confirm dialog
 | ---------------------------------------------------------------------- */

/**
 * Promise-based confirm backed by the themed modal in the page shell.
 */
export function confirmAction({ title, body, confirmLabel = 'Delete', tone = 'danger' }) {
    return new Promise((resolve) => {
        const modal = $('#confirm-modal');

        $('[data-confirm-title]', modal).textContent = title;
        $('[data-confirm-body]', modal).textContent = body;

        const button = $('[data-confirm-accept]', modal);
        button.textContent = confirmLabel;
        button.className = `btn ${tone === 'danger' ? 'btn-danger' : 'btn-primary'}`;

        // Replace the node to drop listeners from any previous invocation.
        const fresh = button.cloneNode(true);
        button.replaceWith(fresh);

        const finish = (result) => {
            hideModal(modal);
            resolve(result);
        };

        fresh.addEventListener('click', () => finish(true), { once: true });
        $$('[data-confirm-cancel]', modal).forEach((el) =>
            el.addEventListener('click', () => finish(false), { once: true })
        );

        showModal(modal);
    });
}

/* -------------------------------------------------------------------------
 | Form errors
 | ---------------------------------------------------------------------- */

export function clearFormErrors(form) {
    $$('[data-error-for]', form).forEach((el) => {
        el.textContent = '';
        el.classList.add('hidden');
    });

    $$('[aria-invalid]', form).forEach((el) => el.removeAttribute('aria-invalid'));

    const banner = $('[data-form-banner]', form);
    if (banner) {
        banner.textContent = '';
        banner.classList.add('hidden');
    }
}

/**
 * Paint an ApiError onto the form.
 *
 * 422 carries per-field messages under error.details.fields; every other status
 * only has a single message, which goes to the banner.
 */
export function showFormErrors(form, error) {
    clearFormErrors(form);

    let handled = false;

    if (error.fields) {
        Object.entries(error.fields).forEach(([field, messages]) => {
            // Laravel reports nested keys like "permission_ids.0".
            const name = field.split('.')[0];
            const slot = $(`[data-error-for="${name}"]`, form);
            const input = form.elements?.[name];

            if (slot) {
                slot.textContent = messages[0];
                slot.classList.remove('hidden');
                handled = true;
            }

            if (input && input.setAttribute) input.setAttribute('aria-invalid', 'true');
        });
    }

    if (!handled) {
        const banner = $('[data-form-banner]', form);

        if (banner) {
            banner.textContent = error.message;
            banner.classList.remove('hidden');
        } else {
            toast(error.message, 'error');
        }
    }
}

/** Disable a submit button and swap its label while a request is in flight. */
export function setSubmitting(form, busy, busyLabel = 'Saving…') {
    const button = $('[type=submit]', form);
    if (!button) return;

    if (busy) {
        button.dataset.idleLabel ??= button.textContent.trim();
        button.textContent = busyLabel;
    } else if (button.dataset.idleLabel) {
        button.textContent = button.dataset.idleLabel;
    }

    button.disabled = busy;
}

/* -------------------------------------------------------------------------
 | Table states
 | ---------------------------------------------------------------------- */

export function tableMessage(colspan, message, tone = 'muted') {
    const color = tone === 'error' ? 'text-rose-600' : 'text-muted-foreground';

    return `<tr><td colspan="${colspan}" class="px-4 py-12 text-center text-sm ${color}">${esc(message)}</td></tr>`;
}
