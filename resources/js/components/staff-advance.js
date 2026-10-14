import auth from '../auth-client';
import { mountPaymentRows } from './payment-rows';
import { can } from '../permissions';
import {
    $, clearFormErrors, confirmAction, downloadCsv, esc, formatDate, formatMoney,
    setSubmitting, showFormErrors, showFormMessage, tableMessage, toast,
} from '../ui';
import { mountWorkspace } from '../workspace';

/**
 * Paying a member of staff an advance, and everything already out — M22.
 *
 * ```
 * tab → PAY AN ADVANCE FORM
 *     → "Show list (7)"       → every advance, and what is still to come back
 *     → row → cancel          → confirm (level 3)
 * ```
 *
 * One implementation, two hosts. The **Staff** card mounts it as its Advances
 * tab, and **Money in & out** mounts it as its Staff advance tab, because
 * handing somebody cash is both a thing done to a member of staff and a thing
 * that takes money out of the till — and a workshop looks for it in either
 * place. Both mount this file over the one partial,
 * `partials/staff-advance-section.blade.php`; never fork either (§5.1). A host
 * supplies a workspace key and, if it already holds the staff list, a way to
 * read and refresh it.
 *
 * ## An advance is not an expense
 *
 * It is money the workshop is owed back — `Dr Staff Advance / Cr Cash` — and it
 * comes back off the next payroll. Nothing here decides that; the posting
 * template does. What this file decides is only how somebody is asked.
 *
 * ## Two grants meet here
 *
 * Reading is READ:STAFF, and paying or cancelling one also needs
 * WRITE:TRANSACTIONS — enforced by the routes. The host gates the tab itself.
 */

const COLUMNS = 7;
const FETCH_SIZE = 200;
const MAX_PAGES = 10;

const iconUndo = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
    + 'stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="shrink-0">'
    + '<path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-15-6.7L3 13"/></svg>';

function today() {
    return new Date().toISOString().slice(0, 10);
}

/**
 * The staff list with each person's outstanding advance, for a host that does
 * not already hold one. Paged like the Staff card's own loader, because a
 * picker fed one page silently cannot offer the two-hundred-and-first person.
 */
async function fetchEmployees() {
    const rows = [];
    let page = 1;
    let more = true;

    while (more && page <= MAX_PAGES) {
        // eslint-disable-next-line no-await-in-loop -- pages are sequential by definition.
        const payload = await auth.call(`/staff?per_page=${FETCH_SIZE}&page=${page}&with_advances=1`);

        rows.push(...(payload.data ?? []));

        more = Boolean(payload.meta?.pagination?.has_more);
        page += 1;
    }

    return rows;
}

/**
 * @param {HTMLElement} sectionRoot the node holding one `[data-ws-form]` and one `[data-ws-list]`
 * @param {object} options
 * @param {string} options.key                    the workspace's Escape key
 * @param {Array}  options.modes                  the payment modes, from a meta endpoint
 * @param {() => Array} [options.employees]       the host's held staff list, if it has one
 * @param {() => Promise} [options.refreshEmployees] refetch the host's list after a write
 * @param {() => void} [options.onChange]          after any load or write — repaint what the host derives
 */
export async function mountStaffAdvances(sectionRoot, {
    key,
    modes,
    employees = null,
    refreshEmployees = null,
    onChange = () => {},
}) {
    /*
    | Closed over, never module-level: two hosts mount this file, and the shell
    | keeps both alive at once. Anything shared between the two calls would
    | belong to whichever mounted last — the two lists would start reading each
    | other's rows, the failure `pages/counterparty.js` records.
    */
    const state = {
        rows: [],
        outstanding: {},
        employeeId: '',
        clientRef: crypto.randomUUID(),
        ownEmployees: [],
    };

    // Held while both surfaces are still in the document — `mountWorkspace`
    // detaches one of them, and `document` cannot reach a detached node.
    const formRoot = $('[data-ws-form]', sectionRoot);
    const listRoot = $('[data-ws-list]', sectionRoot);
    const form = $('[data-advance-form]', formRoot);
    const inForm = (selector) => $(selector, form);
    const inList = (selector) => $(selector, listRoot);

    const staff = () => (employees ? employees() : state.ownEmployees);

    const reloadEmployees = async () => {
        if (refreshEmployees) {
            await refreshEmployees();
        } else {
            state.ownEmployees = await fetchEmployees();
        }

        paintPickers();
    };

    let workspace = null;
    let payments = null;

    /* --- Pickers --------------------------------------------------------- */

    function paintPickers() {
        const pickers = [
            { select: inForm('[data-advance-employee]'), blank: 'Choose somebody…', activeOnly: true },
            { select: inList('[data-advance-filter-employee]'), blank: 'Everybody', activeOnly: false },
        ];

        pickers.forEach(({ select, blank, activeOnly }) => {
            const previous = select.value;
            const rows = activeOnly ? staff().filter((row) => row.is_active) : staff();

            select.innerHTML = [`<option value="">${esc(blank)}</option>`].concat(
                rows.map((row) => `<option value="${row.id}">${esc(row.name)}</option>`),
            ).join('');

            select.value = previous;
        });

        paintHint();
    }

    /**
     * What is already out with the person the form is pointed at — stated at
     * the moment it can still change the decision.
     */
    function paintHint() {
        const hint = inForm('[data-advance-outstanding-hint]');
        const id = inForm('[data-advance-employee]').value;

        if (!id) {
            hint.textContent = '';
            hint.className = 'mt-1.5 text-xs text-muted-foreground';

            return;
        }

        const employee = staff().find((row) => String(row.id) === String(id));
        const outstanding = Number(employee?.advance?.outstanding ?? 0);

        hint.className = outstanding > 0
            ? 'mt-1.5 text-xs font-medium text-amber-600'
            : 'mt-1.5 text-xs text-muted-foreground';

        hint.textContent = outstanding > 0
            ? `${formatMoney(employee.advance.outstanding)} is already out with them.`
            : 'Nothing is out with them at the moment.';
    }

    /* --- Writing ---------------------------------------------------------- */

    async function submit(event) {
        event.preventDefault();

        clearFormErrors(form);

        const employeeId = inForm('[data-advance-employee]').value;

        if (!employeeId) {
            showFormErrors(form, {
                fields: { employee_id: ['Say who the advance is for.'] },
                message: 'Say who the advance is for.',
            });

            return;
        }

        const split = payments.value();
        const total = payments.total();

        if (!split.length) {
            showFormMessage(form, 'Enter how much is being handed over.');

            return;
        }

        const employee = staff().find((row) => String(row.id) === String(employeeId));

        const confirmed = await confirmAction({
            title: `Pay ${formatMoney(total)} to ${employee?.name ?? 'them'}?`,
            body: 'This posts to the ledger straight away — an advance is cash in somebody’s hand. '
                + 'It comes back off the next payroll, and an advance typed wrong is cancelled rather than edited.',
            confirmLabel: 'Pay advance',
            tone: 'primary',
        });

        if (!confirmed) return;

        setSubmitting(form, true);

        try {
            const payload = await auth.call('/staff/advances', {
                method: 'POST',
                body: {
                    employee_id: Number(employeeId),
                    date: inForm('[data-advance-date]').value || null,
                    payments: split,
                    notes: inForm('[data-advance-notes]').value.trim() || null,
                    client_ref: state.clientRef,
                },
            });

            toast(payload.message ?? 'Advance paid.');

            // A fresh reference for the next advance: reusing this one would
            // have the next payment return this one's voucher.
            state.clientRef = crypto.randomUUID();

            if (payload.data?.id) workspace?.flagNew(payload.data.id);

            if (workspace?.hasList()) await load();
            else workspace?.refresh();

            await reloadEmployees();
            onChange();

            // §2A.8 — the form stays put, cleared for the next one.
            reset();
        } catch (error) {
            showFormErrors(form, error);
        } finally {
            setSubmitting(form, false);
        }
    }

    function reset() {
        clearFormErrors(form);

        inForm('[data-advance-employee]').value = '';
        inForm('[data-advance-date]').value = today();
        inForm('[data-advance-notes]').value = '';

        payments?.reset();
        paintHint();

        inForm('[data-advance-employee]').focus();
    }

    /* --- The list --------------------------------------------------------- */

    async function load() {
        const body = inList('[data-advances-body]');
        body.innerHTML = tableMessage(COLUMNS, 'Loading advances…');

        try {
            const query = state.employeeId
                ? `?employee_id=${state.employeeId}&per_page=100`
                : '?per_page=100';

            const payload = await auth.call(`/staff/advances${query}`);

            state.rows = payload.data ?? [];
            state.outstanding = payload.meta?.outstanding ?? {};

            render();
        } catch (error) {
            body.innerHTML = tableMessage(COLUMNS, error.message, 'error');
        }
    }

    function render() {
        const body = inList('[data-advances-body]');
        const { rows } = state;

        if (!rows.length) {
            body.innerHTML = tableMessage(COLUMNS, 'No advances have been paid.');
            inList('[data-advances-summary]').textContent = '';
            workspace?.refresh();
            onChange();

            return;
        }

        const mayReverse = can('UPDATE', 'STAFF') && can('WRITE', 'TRANSACTIONS');

        body.innerHTML = rows.map((row) => {
            const reversed = row.status === 'reversed';
            const outstanding = state.outstanding[row.employee_id]?.outstanding;
            const flash = workspace?.isNew(row.id) ? ' row-new' : '';

            return `
                <tr class="${reversed ? 'opacity-60' : ''}${flash}">
                    <td class="px-4 py-3 text-[0.8125rem] whitespace-nowrap text-muted-foreground">
                        ${esc(formatDate(row.date))}
                    </td>
                    <td class="px-4 py-3 text-[0.8125rem] whitespace-nowrap text-muted-foreground">
                        ${esc(row.doc_no ?? '—')}
                    </td>
                    <td class="px-4 py-3 text-[0.875rem] font-semibold text-foreground">
                        ${esc(row.employee?.name ?? '—')}
                    </td>
                    <td class="px-4 py-3 text-right text-[0.875rem] font-semibold whitespace-nowrap
                               ${reversed ? 'text-muted-foreground line-through' : 'text-foreground'}">
                        ${esc(formatMoney(row.total))}
                    </td>
                    <td class="px-4 py-3 text-right text-[0.8125rem] whitespace-nowrap text-muted-foreground">
                        ${outstanding !== undefined && Number(outstanding) > 0
                            ? esc(formatMoney(outstanding))
                            : '—'}
                    </td>
                    <td class="px-4 py-3 text-[0.8125rem] text-muted-foreground">
                        ${reversed
                            ? '<span class="badge bg-muted text-secondary-foreground">Cancelled</span> '
                            : ''}${esc(row.notes ?? '')}
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex justify-end gap-1">
                            ${!reversed && mayReverse
                                ? `<button type="button" class="btn btn-ghost btn-icon hover:!text-rose-600"
                                           data-reverse-advance="${row.id}" title="Cancel this advance"
                                           aria-label="Cancel this advance">${iconUndo}</button>`
                                : '<span class="text-xs text-muted-foreground">—</span>'}
                        </div>
                    </td>
                </tr>`;
        }).join('');

        const live = rows.filter((row) => row.status !== 'reversed');

        inList('[data-advances-summary]').textContent =
            `${live.length} advance${live.length === 1 ? '' : 's'} · `
            + `${formatMoney(outstandingTotal().toFixed(2))} still to come back`;

        workspace?.refresh();
        onChange();
    }

    function outstandingTotal() {
        return Object.values(state.outstanding)
            .reduce((sum, position) => sum + Number(position.outstanding ?? 0), 0);
    }

    async function reverse(id) {
        const advance = state.rows.find((row) => String(row.id) === String(id));
        if (!advance) return;

        const confirmed = await confirmAction({
            title: 'Cancel this advance?',
            body: `${formatMoney(advance.total)} to ${advance.employee?.name ?? 'them'} is cancelled by its `
                + 'mirror image in the ledger — nothing is deleted from the books. It stops counting against '
                + 'them immediately, so the next payroll will not try to recover it.',
            confirmLabel: 'Cancel the advance',
        });

        if (!confirmed) return;

        try {
            const payload = await auth.call(`/staff/advances/${id}/reverse`, { method: 'POST', body: {} });

            toast(payload.message ?? 'Advance cancelled.');

            await Promise.all([load(), reloadEmployees()]);
            onChange();
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    function exportRows() {
        const rows = [['Date', 'Voucher', 'Who', 'Amount', 'Status', 'Note']];

        state.rows.forEach((row) => {
            rows.push([
                row.date,
                row.doc_no ?? '',
                row.employee?.name ?? '',
                row.total,
                row.status_label ?? row.status,
                row.notes ?? '',
            ]);
        });

        downloadCsv(`staff-advances-${today()}.csv`, rows);
    }

    /* --- Wiring ----------------------------------------------------------- */

    form.addEventListener('submit', submit);
    inForm('[data-advance-clear]').addEventListener('click', reset);
    inForm('[data-advance-employee]').addEventListener('change', paintHint);

    inList('[data-advance-filter-employee]').addEventListener('change', (event) => {
        state.employeeId = event.target.value;
        load();
    });

    inList('[data-advances-export]').addEventListener('click', exportRows);

    inList('[data-advances-body]').addEventListener('click', (event) => {
        const button = event.target.closest('[data-reverse-advance]');

        if (button) reverse(button.dataset.reverseAdvance);
    });

    inForm('[data-advance-date]').value = today();

    payments = mountPaymentRows(inForm('[data-advance-payments]'), {
        modes,
        heading: 'Handed over',
        verb: 'paid',
        // The split *is* the amount — there is no document to settle against,
        // so the state chips have nothing to mean. See payment-rows.js.
        settlesADocument: false,
    });

    const canPay = can('WRITE', 'STAFF') && can('WRITE', 'TRANSACTIONS');

    workspace = mountWorkspace(sectionRoot, {
        key,
        title: 'Advances',
        formSubtitle: 'Money against a salary not yet earned. It comes back off the next payroll.',
        listSubtitle: (count) => (count === null
            ? 'What has been handed out, and what is still to come back.'
            : `${count} advance${count === 1 ? '' : 's'}.`),
        createLabel: 'Pay an advance',
        count: () => (state.rows.length ? state.rows.length : null),
        canCreate: canPay,
        onShowList: load,
        onShowForm: () => inForm('[data-advance-employee]').focus(),

        // A payroll run recovers advances, and the other host can pay one —
        // both are `/staff` writes, which announce `staff`.
        refreshOn: ['staff'],
    });

    // The picker needs the staff list, and it may not have been fetched —
    // somebody can open this tab without ever opening the Staff card.
    if (employees) {
        paintPickers();
    } else {
        await reloadEmployees();
    }

    return {
        workspace,
        load,
        paintPickers,
        outstandingTotal,
        hasList: () => Boolean(workspace?.hasList()),

        /** Point the form at one person — the employee drawer's "Pay an advance". */
        preselect(employeeId) {
            workspace.showForm();
            inForm('[data-advance-employee]').value = String(employeeId);
            paintHint();
        },
    };
}
