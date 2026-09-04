import auth from '../auth-client';
import { can } from '../permissions';
import { clearModuleParams, moduleParams } from '../shell';
import {
    $, clearFormErrors, esc, setSubmitting, showFormErrors, toast,
} from '../ui';

let loaded = null;

/* -------------------------------------------------------------------------
 | Data
 | ---------------------------------------------------------------------- */

async function load() {
    const { data } = await auth.call('/workspace');

    loaded = data;
    paint(data);
}

function paint(workspace) {
    const form = $('#workspace-form');

    form.elements.name.value = workspace.name ?? '';
    form.elements.gstin.value = workspace.gstin ?? '';
    form.elements.state_code.value = workspace.state_code ?? '';
    form.elements.address.value = workspace.address ?? '';

    form.elements.financial_year_start_month.value = workspace.settings.financial_year_start_month;
    form.elements.timezone.value = workspace.settings.timezone;
    form.elements.books_start_date.value = workspace.settings.books_start_date ?? '';

    $('[data-ws-slug]').textContent = workspace.slug;
    $('[data-ws-currency]').textContent = workspace.settings.currency;

    paintFinancialYear(workspace.current_financial_year);

    // A viewer without UPDATE:WORKSPACE reads but cannot change.
    if (!can('UPDATE', 'WORKSPACE')) {
        Array.from(form.elements).forEach((el) => { el.disabled = true; });
        $('[data-ws-status]').textContent = 'You have read-only access to these settings.';
    }
}

function paintFinancialYear(year) {
    $('[data-ws-fy-range]').textContent = year
        ? `Current year: ${formatDay(year.start)} – ${formatDay(year.end)}`
        : '—';
}

function formatDay(iso) {
    return new Date(`${iso}T00:00:00`).toLocaleDateString(undefined, {
        day: '2-digit', month: 'short', year: 'numeric',
    });
}

/* -------------------------------------------------------------------------
 | Saving
 | ---------------------------------------------------------------------- */

function validate(form) {
    const errors = {};
    const name = form.elements.name.value.trim();

    if (name.length < 2) errors.name = ['The workshop name must be at least 2 characters.'];
    else if (name.length > 160) errors.name = ['The workshop name may not exceed 160 characters.'];

    const gstin = form.elements.gstin.value.trim().toUpperCase();
    if (gstin && !/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/.test(gstin)) {
        errors.gstin = ['That does not look like a valid GSTIN.'];
    }

    const stateCode = form.elements.state_code.value.trim();
    if (stateCode && !/^[0-9]{2}$/.test(stateCode)) {
        errors.state_code = ['A state code is two digits.'];
    }

    return Object.keys(errors).length ? errors : null;
}

async function save(event) {
    event.preventDefault();

    const form = event.target;

    clearFormErrors(form);

    const errors = validate(form);

    if (errors) {
        showFormErrors(form, { fields: errors, message: 'Please correct the highlighted fields.' });

        return;
    }

    const payload = {
        name: form.elements.name.value.trim(),
        gstin: form.elements.gstin.value.trim().toUpperCase() || null,
        state_code: form.elements.state_code.value.trim() || null,
        address: form.elements.address.value.trim() || null,
        financial_year_start_month: Number(form.elements.financial_year_start_month.value),
        timezone: form.elements.timezone.value,
        books_start_date: form.elements.books_start_date.value || null,
    };

    setSubmitting(form, true);

    try {
        const { data } = await auth.call('/workspace', { method: 'PATCH', body: payload });

        loaded = data;
        paint(data);
        toast('Workshop settings saved.');

        // Once saved, the welcome prompt has served its purpose.
        $('#welcome-banner')?.classList.add('hidden');

        // The sidebar shows the workshop name, so a rename must be reflected
        // without a page reload.
        document.querySelectorAll('[data-workspace-name]').forEach((el) => {
            el.textContent = data.name;
        });
    } catch (error) {
        showFormErrors(form, error);
    } finally {
        setSubmitting(form, false);
    }
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

export default async function initWorkspace() {
    const form = $('#workspace-form');

    try {
        await load();
    } catch (error) {
        // A platform super-admin has no workshop of their own — that is a
        // situation, not a failure, so say what it is rather than painting the
        // page red.
        form.innerHTML = error.code === 'NO_WORKSPACE'
            ? `<div class="surface px-6 py-12 text-center">
                   <p class="text-sm font-semibold text-foreground">No workshop to configure</p>
                   <p class="mx-auto mt-1.5 max-w-md text-[0.8125rem] text-muted-foreground">
                       Your account administers the platform rather than a single workshop. Manage workshops from
                       the Workshops screen instead.
                   </p>
               </div>`
            : `<p class="surface px-6 py-12 text-center text-sm text-rose-600">${esc(error.message)}</p>`;

        return;
    }

    // Sign-up lands here as `#workspace?welcome=1`. The intent comes from the
    // shell: a module's URL is a fragment of the dashboard's now.
    if (moduleParams().get('welcome') === '1') {
        $('#welcome-banner').classList.remove('hidden');
        $('#welcome-banner').classList.add('flex');
        clearModuleParams();
    }

    form.addEventListener('submit', save);

    // A GSTIN carries its state code in the first two digits; the server
    // re-derives it on save, so mirroring it here just avoids showing the user
    // two values that disagree.
    $('#ws-gstin').addEventListener('input', (event) => {
        const gstin = event.target.value.trim().toUpperCase();

        if (/^[0-9]{2}/.test(gstin)) $('#ws-state-code').value = gstin.slice(0, 2);
    });

    // The year range is server-truth, so show it as stale until saved rather
    // than recomputing the April off-by-one in the browser.
    $('#ws-fy').addEventListener('change', () => {
        $('[data-ws-fy-range]').textContent =
            Number($('#ws-fy').value) === loaded?.settings.financial_year_start_month
                ? `Current year: ${formatDay(loaded.current_financial_year.start)} – ${formatDay(loaded.current_financial_year.end)}`
                : 'Save to apply the new financial year.';
    });
}
