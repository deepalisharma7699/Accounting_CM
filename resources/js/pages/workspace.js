import auth from '../auth-client';
import { can } from '../permissions';
import { clearModuleParams, moduleParams } from '../shell';
import {
    $, clearFormErrors, esc, setSubmitting, showFormErrors, toast,
} from '../ui';
import { mountWorkspace } from '../workspace';

/**
 * Workshop settings — the workshop's own record.
 *
 * ## One record, so one surface
 *
 * There is nothing to create here, so the module declares only `data-ws-list`
 * and mounts with `canCreate: false`. The workspace lands straight on the form
 * and paints no switch control — §2A.10's judgement applied to a module with a
 * single record rather than to a read-mostly one. The frame is still the shared
 * one: the heading, Escape and the URL sync are not this module's to reinvent,
 * and `workspace.js` deliberately grew no single-surface mode for this.
 *
 * ## The three rules
 *
 * `payment_due_days`, `allow_negative_stock` and `round_off_invoices` have
 * always been accepted by UpdateWorkspaceRequest and read by `Tenant`,
 * `StockLedgerService`, `RoundOff` and the Insights ageing panel — and no screen
 * offered any of them. Re-flowing the seven fields that existed and leaving
 * these behind would have made this the module that looks converted and is not.
 *
 * ## Everything is scoped to the surface
 *
 * The shell caches a module's root *detached*, so `document.querySelector`
 * finds nothing in a module that is not the one on screen. Every lookup here is
 * scoped to the held nodes, which work detached.
 */

let loaded = null;

/** The module's own nodes, held so they can be read while detached. */
let listRoot = null;
let form = null;

/* -------------------------------------------------------------------------
 | Data
 | ---------------------------------------------------------------------- */

async function load() {
    const { data } = await auth.call('/workspace');

    loaded = data;
    paint(data);
}

function paint(workspace) {
    form.elements.name.value = workspace.name ?? '';
    form.elements.gstin.value = workspace.gstin ?? '';
    form.elements.state_code.value = workspace.state_code ?? '';
    form.elements.address.value = workspace.address ?? '';

    form.elements.financial_year_start_month.value = workspace.settings.financial_year_start_month;
    form.elements.timezone.value = workspace.settings.timezone;
    form.elements.books_start_date.value = workspace.settings.books_start_date ?? '';

    /*
    | Null is a real setting rather than a missing one: it means the workshop
    | settles at the counter and wants no ageing measured against terms nobody
    | agreed to. So it is an empty box, never a zero — zero days would be terms,
    | and strict ones.
    */
    form.elements.payment_due_days.value = workspace.settings.payment_due_days ?? '';

    form.elements.allow_negative_stock.checked = workspace.settings.allow_negative_stock;
    form.elements.round_off_invoices.checked = workspace.settings.round_off_invoices;

    $('[data-ws-slug]', listRoot).textContent = workspace.slug;
    $('[data-ws-currency]', listRoot).textContent = workspace.settings.currency;

    paintFinancialYear(workspace.current_financial_year);

    /*
    | A viewer without UPDATE:WORKSPACE reads but cannot change. The save
    | control is already absent — `data-requires-permission` in the markup — so
    | this only has to stop the fields inviting an edit that cannot be sent, and
    | say why.
    */
    if (!can('UPDATE', 'WORKSPACE')) {
        Array.from(form.elements).forEach((el) => { el.disabled = true; });
        $('[data-ws-readonly]', listRoot).classList.remove('hidden');
    }
}

function paintFinancialYear(year) {
    $('[data-ws-fy-range]', listRoot).textContent = year
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

function validate() {
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

    // Empty is the "no terms" setting and is always allowed; anything typed has
    // to be a whole number of days inside the range the request accepts, so a
    // slip is caught beside the field rather than as a 422 further down.
    const dueDays = form.elements.payment_due_days.value.trim();
    if (dueDays && !/^[0-9]+$/.test(dueDays)) {
        errors.payment_due_days = ['Payment terms are a whole number of days, or empty.'];
    } else if (dueDays && Number(dueDays) > 730) {
        errors.payment_due_days = ['Payment terms may not exceed 730 days.'];
    }

    return Object.keys(errors).length ? errors : null;
}

async function save(event) {
    event.preventDefault();

    clearFormErrors(form);

    const errors = validate();

    if (errors) {
        showFormErrors(form, { fields: errors, message: 'Please correct the highlighted fields.' });

        return;
    }

    const dueDays = form.elements.payment_due_days.value.trim();

    const payload = {
        name: form.elements.name.value.trim(),
        gstin: form.elements.gstin.value.trim().toUpperCase() || null,
        state_code: form.elements.state_code.value.trim() || null,
        address: form.elements.address.value.trim() || null,
        financial_year_start_month: Number(form.elements.financial_year_start_month.value),
        timezone: form.elements.timezone.value,
        books_start_date: form.elements.books_start_date.value || null,
        payment_due_days: dueDays === '' ? null : Number(dueDays),
        allow_negative_stock: form.elements.allow_negative_stock.checked,
        round_off_invoices: form.elements.round_off_invoices.checked,
    };

    setSubmitting(form, true);

    try {
        const { data } = await auth.call('/workspace', { method: 'PATCH', body: payload });

        loaded = data;
        paint(data);
        toast('Workshop settings saved.');

        // Once saved, the welcome prompt has served its purpose.
        $('#welcome-banner', listRoot)?.classList.add('hidden');

        // The topbar shows the workshop name, so a rename must be reflected
        // without a page reload (§3.2). Deliberately document-scoped: the chrome
        // this writes to is outside the module.
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
    const root = $('[data-ws-list]').closest('[data-module-root]');

    listRoot = $('[data-ws-list]', root);
    form = $('#workspace-form', listRoot);

    /*
    | Mounted before the fetch, so the heading is on screen while the record is
    | still arriving — and so it is still there if the fetch fails and the
    | surface below becomes an explanation instead of a form.
    */
    mountWorkspace(root, {
        key: 'workspace',
        title: 'Workshop settings',
        formSubtitle: '',
        listSubtitle: () => "Your workshop's identity, and the settings every report is built on.",
        createLabel: '',
        // One record. Nothing to create, so no switch control and no second
        // surface to switch to.
        canCreate: false,
    });

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
        $('#welcome-banner', listRoot).classList.remove('hidden');
        $('#welcome-banner', listRoot).classList.add('flex');
        clearModuleParams();
    }

    form.addEventListener('submit', save);

    // A GSTIN carries its state code in the first two digits; the server
    // re-derives it on save, so mirroring it here just avoids showing the user
    // two values that disagree.
    $('#ws-gstin', listRoot).addEventListener('input', (event) => {
        const gstin = event.target.value.trim().toUpperCase();

        if (/^[0-9]{2}/.test(gstin)) $('#ws-state-code', listRoot).value = gstin.slice(0, 2);
    });

    // The year range is server-truth, so show it as stale until saved rather
    // than recomputing the April off-by-one in the browser.
    $('#ws-fy', listRoot).addEventListener('change', () => {
        $('[data-ws-fy-range]', listRoot).textContent =
            Number($('#ws-fy', listRoot).value) === loaded?.settings.financial_year_start_month
                ? `Current year: ${formatDay(loaded.current_financial_year.start)} – ${formatDay(loaded.current_financial_year.end)}`
                : 'Save to apply the new financial year.';
    });
}
