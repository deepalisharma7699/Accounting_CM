import auth from '../auth-client';
import { mountPaymentRows } from '../components/payment-rows';
import { can } from '../permissions';
import { clearModuleParams, moduleParams, registerEscape } from '../shell';
import {
    $, $$, clearFormErrors, confirmAction, debounce, downloadCsv, esc, formatDate,
    formatMoney, hideModal, setSubmitting, showFormErrors, showModal, tableMessage, toast,
} from '../ui';
import { adoptForm, mountWorkspace } from '../workspace';

/**
 * Staff — the people who work for the workshop, M22.
 *
 * ## Four sections, and one shared renderer used four times
 *
 * Staff, attendance, payroll and advances are four things a workshop does with
 * the same nine people, so they are one card. Inside, each is an ordinary §2A
 * workspace: `mountWorkspace()` is called once per section, on that section's
 * own root, and every one of them inherits the form/list swap, the single switch
 * control, the count badge and the Escape step. There is no per-module flow code
 * here at all, which is the rule §2A exists to state.
 *
 * The sections are mounted **lazily**, on the first click of their tab (§2.5,
 * §7.2). A workshop that only ever marks attendance never pays for the payroll
 * sheet, and opening the module fetches the staff form's vocabulary and nothing
 * else.
 *
 * ## Escape
 *
 * Each section's workspace registers its own Escape handler under a key of its
 * own, which the shell never looks up — the shell asks for `staff`. So this file
 * registers that one, and delegates to whichever section is open. Without it the
 * last-mounted section would answer for all four, and a press on the payroll
 * list would swap the attendance sheet.
 *
 * ## One fetch of the staff list, four readers
 *
 * The list, the advance form's picker, the advance filter and the drawer all
 * want the same rows. `ensureEmployees()` fetches them once and holds them, so
 * opening Advances before Staff does not fetch twice and opening Staff
 * afterwards does not fetch at all (§3.6, §2A.6).
 *
 * ## What is never computed here
 *
 * **What anybody is owed.** Every figure on the payroll sheet comes from
 * `POST /staff/payroll/preview`, and the sheet is recomputed server-side again
 * when it posts. The one arithmetic this file does is `gross − recovery`, to
 * keep a typed recovery honest before the round trip — and the server caps it
 * anyway. A day rate anywhere in this file would be the second implementation
 * CLAUDE.md §4.4 exists to prevent, and it would be the copy that stayed wrong
 * longest, because these screens are read daily and the calculator monthly.
 */

const PAGE_SIZE = 25;
const FETCH_SIZE = 200;
const MAX_PAGES = 10;

const PEOPLE_COLUMNS = 7;
const ADVANCE_COLUMNS = 7;
const RUN_COLUMNS = 7;

/* -------------------------------------------------------------------------
 | State
 |
 | One object per section, so a swap between tabs restores exactly what was
 | there — the half-typed sheet, the applied filter, the month somebody was
 | looking at (§3.6).
 | ---------------------------------------------------------------------- */

const state = {
    meta: {
        bases: [],
        statuses: [],
        modes: [],
        designations: [],
    },

    employees: [],
    employeesLoaded: false,
    employeesTruncated: false,

    people: {
        search: '',
        designationId: '',
        pill: 'all',
        sort: { column: 'name', direction: 'asc' },
        page: 1,
    },

    attendance: {
        date: today(),
        rows: [],
        registerMonth: thisMonth(),
        register: null,
    },

    payroll: {
        month: thisMonth(),
        sheet: null,
        recoveries: {},
        runs: [],
        runsLoaded: false,
        openRun: null,
    },

    advances: {
        rows: [],
        outstanding: {},
        employeeId: '',
    },

    openEmployee: null,
};

/*
| Held at mount, while everything is still in the document.
|
| §2A.2 keeps exactly one of each section's form and list attached, so a
| `document.querySelector` into the other finds nothing — which is precisely when
| a save wants to bring a list up to date. Querying a *node* works while it is
| detached, so every lookup below is scoped to whichever of these it belongs to.
*/
let root = null;
let employeeForm = null;
let employeeFormSlot = null;
let employeeModalSlot = null;
let advanceForm = null;

/** sectionKey -> { root, form, list, workspace, opened } */
const sections = {};

let activeSection = 'people';
let advancePayments = null;
let payrollPayments = null;

const inForm = (key, selector) => $(selector, sections[key].form);
const inList = (key, selector) => $(selector, sections[key].list);
const allInList = (key, selector) => $$(selector, sections[key].list);

/* -------------------------------------------------------------------------
 | Dates
 | ---------------------------------------------------------------------- */

function today() {
    return new Date().toISOString().slice(0, 10);
}

function thisMonth() {
    return new Date().toISOString().slice(0, 7);
}

/** "September 2026", from `2026-09`. */
function monthLabel(period) {
    if (!period) return '';

    const [year, month] = period.split('-').map(Number);

    return new Date(year, month - 1, 1)
        .toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
}

function shiftDay(date, days) {
    const moved = new Date(`${date}T00:00:00`);
    moved.setDate(moved.getDate() + days);

    return moved.toISOString().slice(0, 10);
}

function daysIn(period) {
    const [year, month] = period.split('-').map(Number);

    return new Date(year, month, 0).getDate();
}

/* -------------------------------------------------------------------------
 | Vocabulary — everything the server publishes, and nothing hard-coded
 | ---------------------------------------------------------------------- */

function basis(value) {
    return state.meta.bases.find((row) => row.value === value) ?? null;
}

function statusMeta(value) {
    return state.meta.statuses.find((row) => row.value === value) ?? null;
}

function statusLabel(value) {
    return statusMeta(value)?.label ?? 'Not marked';
}

function statusTone(value) {
    return statusMeta(value)?.tone ?? 'bg-muted text-secondary-foreground';
}

/* -------------------------------------------------------------------------
 | Data
 | ---------------------------------------------------------------------- */

/**
 * The two salary bases, the six attendance states, the payment modes and this
 * workshop's designations.
 *
 * Fetched before the workspace mounts, because the section the module lands on
 * is a form that needs all four — a picker that renders empty for a moment on a
 * slow phone is a picker somebody saves without.
 */
async function loadMeta() {
    const { data } = await auth.call('/staff/meta');

    state.meta = {
        bases: data.salary_bases ?? [],
        statuses: data.attendance_statuses ?? [],
        modes: data.payment_modes ?? [],
        designations: data.designations ?? [],
    };
}

/**
 * The staff list, fetched once and held.
 *
 * Four screens read it, so it is cached rather than refetched per section
 * (§2A.6). `with_advances` and `with_attendance` are always asked for, although
 * only the list column shows them: they cost one extra query each for the whole
 * page rather than one per row, and two caches — one thin, one full — would be
 * two things to invalidate on every save.
 */
async function ensureEmployees({ force = false } = {}) {
    if (state.employeesLoaded && !force) return;

    const rows = [];
    let page = 1;
    let more = true;

    while (more && page <= MAX_PAGES) {
        // eslint-disable-next-line no-await-in-loop -- pages are sequential by definition.
        const payload = await auth.call(
            `/staff?per_page=${FETCH_SIZE}&page=${page}&with_advances=1&with_attendance=1`
            + `&period=${state.attendance.registerMonth}`,
        );

        rows.push(...(payload.data ?? []));

        more = Boolean(payload.meta?.pagination?.has_more);
        page += 1;
    }

    state.employeesTruncated = more;
    state.employees = rows;
    state.employeesLoaded = true;

    paintEmployeePickers();
}

/** Everybody still on the payroll, for the pickers that write something. */
function activeEmployees() {
    return state.employees.filter((row) => row.is_active);
}

/* -------------------------------------------------------------------------
 | Section 1 — Staff
 | ---------------------------------------------------------------------- */

function peopleScoped() {
    const search = state.people.search.toLowerCase();

    return state.employees.filter((row) => {
        if (search && !`${row.name} ${row.phone ?? ''} ${row.email ?? ''}`.toLowerCase().includes(search)) {
            return false;
        }

        if (state.people.designationId !== ''
            && String(row.designation_id ?? '') !== state.people.designationId) {
            return false;
        }

        return true;
    });
}

function matchesPeoplePill(row) {
    const pill = state.people.pill;

    if (pill === 'monthly') return row.salary_basis === 'monthly' && row.is_active;
    if (pill === 'daily') return row.salary_basis === 'daily' && row.is_active;
    if (pill === 'advance') return Number(row.advance?.outstanding ?? 0) > 0;
    if (pill === 'left') return !row.is_active;

    // "Everybody" means everybody still here. Somebody who has left is behind
    // their own pill rather than mixed into a list a workshop reads to know who
    // is on the bench today.
    return row.is_active;
}

function peopleSortValue(row, column) {
    if (column === 'pay_rate') return Number(row.pay_rate ?? 0);
    if (column === 'joined_on') return row.joined_on ? Date.parse(row.joined_on) : 0;

    return (row.name ?? '').toLowerCase();
}

function peopleSorted(rows) {
    const { column, direction } = state.people.sort;
    const sign = direction === 'asc' ? 1 : -1;

    return [...rows].sort((a, b) => {
        /*
        | Grouped by basis before anything else when sorting by pay.
        |
        | A monthly salary and a daily wage are different quantities, and 18,000
        | against 550 is not a comparison anybody means. Grouping first means the
        | column answers "who is the best paid fitter" rather than sorting every
        | daily-wage helper to the bottom of a list they do not belong at the
        | bottom of.
        */
        if (column === 'pay_rate' && a.salary_basis !== b.salary_basis) {
            return a.salary_basis === 'monthly' ? -1 : 1;
        }

        const left = peopleSortValue(a, column);
        const right = peopleSortValue(b, column);

        if (left === right) return (a.name ?? '').localeCompare(b.name ?? '');

        return left > right ? sign : -sign;
    });
}

function renderPeople() {
    const scope = peopleScoped();
    const matched = peopleSorted(scope.filter(matchesPeoplePill));

    // Clamped before the rows are painted, not after: a filter that shortens the
    // list while the reader is on page 3 must not paint an empty table once and
    // the right one on the next render.
    state.people.page = Math.min(
        state.people.page,
        Math.max(1, Math.ceil(matched.length / PAGE_SIZE)),
    );

    renderPeopleTiles(scope);
    renderPeoplePills();
    renderPeopleRows(matched);
    renderPeopleSummary(matched.length);
    renderPeoplePager(matched.length);
    renderPeopleSortIndicators();

    sections.people.workspace?.refresh();
    paintTabCounts();
}

function renderPeopleTiles(scope) {
    const here = scope.filter((row) => row.is_active);

    const advanceTotal = scope.reduce(
        (sum, row) => sum + Number(row.advance?.outstanding ?? 0),
        0,
    );

    inList('people', '#staff-stat-total').textContent = here.length.toLocaleString('en-IN');
    inList('people', '#staff-stat-monthly').textContent = here
        .filter((row) => row.salary_basis === 'monthly').length.toLocaleString('en-IN');
    inList('people', '#staff-stat-daily').textContent = here
        .filter((row) => row.salary_basis === 'daily').length.toLocaleString('en-IN');
    inList('people', '#staff-stat-advance').textContent = formatMoney(advanceTotal.toFixed(2));

    allInList('people', '[data-staff-filter]').forEach((tile) => {
        const on = state.people.pill === tile.dataset.staffFilter;

        tile.classList.toggle('stat-tile-on', on);
        tile.querySelector('[data-stat-chevron]')?.classList.toggle('text-primary', on);
        tile.querySelector('[data-stat-chevron]')?.classList.toggle('text-border', !on);
    });
}

function renderPeoplePills() {
    allInList('people', '[data-pill]').forEach((pill) => {
        pill.setAttribute('aria-pressed', String(pill.dataset.pill === state.people.pill));
    });

    const filtering = state.people.pill !== 'all'
        || state.people.search !== ''
        || state.people.designationId !== '';

    inList('people', '#staff-clear-filters').classList.toggle('hidden', !filtering);
    inList('people', '#staff-clear-filters').classList.toggle('flex', filtering);
}

/**
 * How this person's month has gone, in the smallest space that still says
 * something true.
 *
 * The unmarked count is the figure worth showing, and what it *means* differs by
 * basis — which is why the words differ too. On a monthly salary an unmarked day
 * is paid, so a large count is only a record nobody kept; on a daily wage it is
 * a day that will not be paid, so the same number is money somebody is about to
 * lose. Painting both as a neutral "12 unmarked" would be true and useless.
 */
function attendanceCell(row) {
    const summary = row.attendance;

    if (!summary) return '<span class="text-xs text-muted-foreground">—</span>';

    const counts = summary.counts ?? {};
    const marked = Object.values(counts).reduce((sum, n) => sum + n, 0);

    if (marked === 0) {
        return row.salary_basis === 'daily'
            ? '<span class="badge bg-rose-50 text-rose-700">Nothing marked</span>'
            : '<span class="text-xs text-muted-foreground">Nothing marked</span>';
    }

    const chips = state.meta.statuses
        .filter((status) => (counts[status.value] ?? 0) > 0)
        .map((status) => `<span class="badge ${status.tone}" title="${esc(status.label)}">`
            + `${esc(status.initial)} ${counts[status.value]}</span>`)
        .join('');

    return `<div class="flex flex-wrap gap-1">${chips}</div>`;
}

function renderPeopleRows(rows) {
    const body = inList('people', '#staff-body');

    if (!rows.length) {
        body.innerHTML = tableMessage(
            PEOPLE_COLUMNS,
            state.employees.length ? 'Nobody matches these filters.' : 'Nobody has been added yet.',
        );

        return;
    }

    const mayUpdate = can('UPDATE', 'STAFF');
    const mayDelete = can('DELETE', 'STAFF');

    const start = (state.people.page - 1) * PAGE_SIZE;

    body.innerHTML = rows.slice(start, start + PAGE_SIZE).map((row) => {
        const flash = sections.people.workspace?.isNew(row.id) ? ' row-new' : '';
        const advance = Number(row.advance?.outstanding ?? 0);

        const actions = [
            mayUpdate
                ? `<button type="button" class="btn btn-ghost btn-icon" data-edit="${row.id}"
                           title="Edit ${esc(row.name)}" aria-label="Edit ${esc(row.name)}">${iconPencil}</button>`
                : '',
            mayDelete
                ? `<button type="button" class="btn btn-ghost btn-icon hover:!text-rose-600" data-delete="${row.id}"
                           title="Remove ${esc(row.name)}" aria-label="Remove ${esc(row.name)}">${iconTrash}</button>`
                : '',
        ].join('');

        return `
            <tr class="cursor-pointer transition hover:bg-secondary/60${flash}" data-row="${row.id}"
                tabindex="0" role="button" aria-label="Open ${esc(row.name)}">
                <td class="px-4 py-3">
                    <div class="flex items-center gap-3">
                        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-accent text-sm
                                     font-semibold text-accent-foreground">${esc(initials(row.name))}</span>
                        <div class="min-w-0">
                            <div class="truncate text-[0.875rem] font-semibold text-foreground">${esc(row.name)}</div>
                            <div class="truncate text-[0.78125rem] text-muted-foreground">
                                ${row.is_active
                                    ? esc(row.phone ?? '—')
                                    : `Left ${esc(formatDate(row.left_on))}`}
                            </div>
                        </div>
                    </div>
                </td>
                <td class="px-4 py-3">
                    ${row.designation
                        ? `<span class="badge bg-accent text-accent-foreground">${esc(row.designation.name)}</span>`
                        : '<span class="text-[0.78125rem] text-muted-foreground">—</span>'}
                </td>
                <td class="px-4 py-3 text-right whitespace-nowrap">
                    <div class="text-[0.875rem] font-semibold text-foreground">${esc(formatMoney(row.pay_rate))}</div>
                    <div class="text-[0.75rem] text-muted-foreground">${esc(row.salary_basis_short ?? '')}</div>
                </td>
                <td class="px-4 py-3">${attendanceCell(row)}</td>
                <td class="px-4 py-3 text-right whitespace-nowrap">
                    ${advance > 0
                        ? `<span class="font-semibold text-amber-600">${esc(formatMoney(row.advance.outstanding))}</span>`
                        : '<span class="text-[0.78125rem] text-muted-foreground">—</span>'}
                </td>
                <td class="px-4 py-3 text-[0.8125rem] whitespace-nowrap text-muted-foreground">
                    ${esc(formatDate(row.joined_on))}
                </td>
                <td class="px-4 py-3">
                    <div class="flex justify-end gap-1">${actions
                        || '<span class="text-xs text-muted-foreground">—</span>'}</div>
                </td>
            </tr>`;
    }).join('');
}

function renderPeopleSummary(matched) {
    const total = state.employees.length;
    const parts = [`Showing ${matched.toLocaleString('en-IN')} of ${total.toLocaleString('en-IN')}`];

    if (matched !== total) parts.push('· Filtered');
    if (state.employeesTruncated) parts.push(`· first ${total.toLocaleString('en-IN')} loaded`);

    inList('people', '#staff-summary').textContent = parts.join(' ');
}

function renderPeoplePager(matched) {
    renderPager(inList('people', '#staff-pager'), matched, state.people.page, (page) => {
        state.people.page = page;
        renderPeople();
    });
}

function renderPeopleSortIndicators() {
    allInList('people', '#staff-head [data-sort]').forEach((th) => {
        const on = th.dataset.sort === state.people.sort.column;

        th.setAttribute('aria-sort', on
            ? (state.people.sort.direction === 'asc' ? 'ascending' : 'descending')
            : 'none');

        th.querySelector('[data-sort-arrow]')?.remove();

        const arrow = document.createElement('span');
        arrow.dataset.sortArrow = '';
        arrow.className = `ml-1 inline-block align-middle ${on ? 'text-primary' : 'text-border'}`;
        arrow.innerHTML = on && state.people.sort.direction === 'desc' ? iconArrowDown : iconArrowUp;

        th.append(arrow);
    });
}

function setPeoplePill(pill) {
    state.people.pill = state.people.pill === pill ? 'all' : pill;
    state.people.page = 1;
    renderPeople();
}

function exportPeople() {
    const rows = [[
        'Name', 'Designation', 'Paid', 'Rate', 'Joined', 'Left', 'Advance outstanding',
    ]];

    peopleSorted(peopleScoped().filter(matchesPeoplePill)).forEach((row) => {
        rows.push([
            row.name,
            row.designation?.name ?? '',
            row.salary_basis_label ?? row.salary_basis,
            row.pay_rate,
            row.joined_on ?? '',
            row.left_on ?? '',
            row.advance?.outstanding ?? '0.00',
        ]);
    });

    downloadCsv(`staff-${today()}.csv`, rows);
}

/* -------------------------------------------------------------------------
 | Section 1 — the employee form, one node with two homes
 | ---------------------------------------------------------------------- */

function paintDesignationOptions(selectedId = '') {
    const selected = String(selectedId ?? '');

    /*
    | A designation somebody already holds stays offered even after it has been
    | archived — otherwise saving an unrelated change to their phone number would
    | silently strip their trade, because the select had nothing selected to send
    | back. The same rule the users module applies to a removed role.
    */
    const active = state.meta.designations.filter(
        (row) => row.is_active || String(row.id) === selected,
    );

    const options = ['<option value="">None</option>'].concat(
        active.map((row) => `<option value="${row.id}">${esc(row.name)}`
            + `${row.is_active ? '' : ' (archived)'}</option>`),
    );

    const select = $('#employee-designation', employeeForm);
    select.innerHTML = options.join('');
    select.value = selected;

    // And the list's filter, which offers everything including the archived —
    // somebody filtering for "Apprentice" wants the people, not the flag.
    const filter = inList('people', '#staff-filter-designation');

    if (filter) {
        filter.innerHTML = ['<option value="">Any designation</option>'].concat(
            state.meta.designations.map((row) =>
                `<option value="${row.id}">${esc(row.name)}</option>`),
        ).join('');

        filter.value = state.people.designationId;
    }
}

function paintBasisOptions(selected = 'monthly') {
    const select = $('#employee-basis', employeeForm);

    select.innerHTML = state.meta.bases
        .map((row) => `<option value="${esc(row.value)}">${esc(row.label)}</option>`)
        .join('');

    select.value = selected;

    paintRateLabel();
}

/**
 * What the rate field is called, and what the hint under the basis says.
 *
 * Both come from the server's vocabulary rather than being written here, because
 * the difference between the two bases is the whole of what somebody filling
 * this form has to understand — and a label that said "Rate" for both would
 * leave them to guess whether 18000 is a month or a day.
 */
function paintRateLabel() {
    const chosen = basis($('#employee-basis', employeeForm).value);

    $('#employee-rate-label', employeeForm).textContent = chosen?.rate_label ?? 'Pay rate';
    $('#employee-basis-hint', employeeForm).textContent = chosen?.description ?? '';
}

function openEmployeeForm(employee = null) {
    const editing = employee !== null;

    adoptForm(
        employeeForm,
        editing ? employeeModalSlot : employeeFormSlot,
        { chrome: editing ? 'modal' : 'inline' },
    );

    clearFormErrors(employeeForm);
    employeeForm.reset();

    $('#employee-modal-title', employeeForm).textContent = editing ? `Edit ${employee.name}` : 'Add somebody';
    $('#employee-modal-subtitle', employeeForm).textContent = editing
        ? 'Changing the rate applies from the next payroll. Months already paid are untouched.'
        : '';

    employeeForm.elements.id.value = editing ? employee.id : '';
    $('#employee-name', employeeForm).value = editing ? employee.name : '';
    $('#employee-phone', employeeForm).value = editing ? (employee.phone ?? '') : '';
    $('#employee-email', employeeForm).value = editing ? (employee.email ?? '') : '';
    $('#employee-address', employeeForm).value = editing ? (employee.address ?? '') : '';
    $('#employee-notes', employeeForm).value = editing ? (employee.notes ?? '') : '';
    $('#employee-joined', employeeForm).value = editing ? (employee.joined_on ?? '') : today();
    $('#employee-rate', employeeForm).value = editing ? employee.pay_rate : '';

    paintBasisOptions(editing ? employee.salary_basis : 'monthly');
    paintDesignationOptions(editing ? (employee.designation_id ?? '') : '');

    /*
    | The leaving date is on the edit form only.
    |
    | Adding somebody who has already left is not a thing anybody does, and a
    | field for it on the create form would be a question with one answer.
    */
    const left = $('[data-employee-left]', employeeForm);
    left.classList.toggle('hidden', !editing);
    $('#employee-left', employeeForm).value = editing ? (employee.left_on ?? '') : '';

    if (editing) {
        showModal('#employee-modal');

        return;
    }

    sections.people.workspace?.showForm();
    $('#employee-name', employeeForm).focus();
}

/** A pre-check, so an obvious mistake costs nothing. The API re-validates all of it (§6.1). */
function validateEmployee() {
    const errors = {};

    const name = $('#employee-name', employeeForm).value.trim();
    const rate = $('#employee-rate', employeeForm).value;

    if (name.length < 2) errors.name = ['Give this person a name of at least 2 characters.'];
    if (rate === '' || Number(rate) < 0) errors.pay_rate = ['Give the rate — a monthly salary, or the rate per day.'];

    const joined = $('#employee-joined', employeeForm).value;
    const leftOn = $('#employee-left', employeeForm).value;

    if (joined && leftOn && leftOn < joined) {
        errors.left_on = ['Somebody cannot leave before they joined.'];
    }

    return Object.keys(errors).length ? errors : null;
}

async function submitEmployee(event) {
    event.preventDefault();

    const id = employeeForm.elements.id.value;
    const editing = id !== '';

    clearFormErrors(employeeForm);

    const errors = validateEmployee();

    if (errors) {
        showFormErrors(employeeForm, { fields: errors, message: 'Please correct the highlighted fields.' });

        return;
    }

    const payload = {
        name: $('#employee-name', employeeForm).value.trim(),
        designation_id: $('#employee-designation', employeeForm).value || null,
        salary_basis: $('#employee-basis', employeeForm).value,
        pay_rate: $('#employee-rate', employeeForm).value,
        joined_on: $('#employee-joined', employeeForm).value || null,
        phone: $('#employee-phone', employeeForm).value.trim() || null,
        email: $('#employee-email', employeeForm).value.trim() || null,
        address: $('#employee-address', employeeForm).value.trim() || null,
        notes: $('#employee-notes', employeeForm).value.trim() || null,
    };

    // Only on an edit, where an explicit null is what puts somebody back on the
    // day sheet. Sending it on a create would be saying "and they have not left",
    // which the server assumes anyway.
    if (editing) payload.left_on = $('#employee-left', employeeForm).value || null;

    setSubmitting(employeeForm, true);

    try {
        const saved = await auth.call(editing ? `/staff/${id}` : '/staff', {
            method: editing ? 'PATCH' : 'POST',
            body: payload,
        });

        toast(saved.message ?? (editing ? 'Staff record updated.' : 'Added to the staff list.'));

        // §2A.8 — the row is flagged rather than shown. Somebody entering a
        // workshop's staff writes several in a row and never sees the list in
        // between, so the flash happens whenever they do look.
        if (!editing) sections.people.workspace?.flagNew(saved?.data?.id);

        if (editing) hideModal('#employee-modal');

        await ensureEmployees({ force: true });

        if (sections.people.workspace?.hasList()) renderPeople();
        else sections.people.workspace?.refresh();

        // §2A.8 — a successful create stays on the form, cleared and focused,
        // because somebody adding the workshop's staff writes several in a row.
        if (!editing) openEmployeeForm();
    } catch (error) {
        showFormErrors(employeeForm, error);
    } finally {
        setSubmitting(employeeForm, false);
    }
}

async function destroyEmployee(id) {
    const employee = state.employees.find((row) => String(row.id) === String(id));
    if (!employee) return;

    const confirmed = await confirmAction({
        title: `Remove ${employee.name}?`,
        body: 'This only works for somebody who has never been marked present, paid or given an advance. '
            + 'Anybody else is marked as having left instead, so their payslips keep the name that explains them.',
        confirmLabel: 'Remove',
    });

    if (!confirmed) return;

    try {
        await auth.call(`/staff/${id}`, { method: 'DELETE' });

        toast('Removed from the staff list.');
        hideModal('#employee-drawer');
        state.openEmployee = null;

        await ensureEmployees({ force: true });
        renderPeople();
    } catch (error) {
        // EMPLOYEE_IN_USE explains itself and names archiving instead, so it is
        // shown as it arrives.
        toast(error.message, 'error');
    }
}

/* -------------------------------------------------------------------------
 | Section 1 — the drawer
 | ---------------------------------------------------------------------- */

async function openEmployeeDrawer(id) {
    const employee = state.employees.find((row) => String(row.id) === String(id));
    if (!employee) return;

    state.openEmployee = employee;

    $('#employee-drawer-initials').textContent = initials(employee.name);
    $('#employee-drawer-title').textContent = employee.name;
    $('#employee-drawer-designation').textContent = employee.designation?.name ?? 'No designation';
    $('#employee-drawer-status').innerHTML = employee.is_active
        ? '<span class="badge bg-emerald-50 text-emerald-700">On the staff</span>'
        : '<span class="badge bg-muted text-secondary-foreground">Left</span>';

    $('#employee-drawer-rate-label').textContent = employee.rate_label ?? 'Pay';
    $('#employee-drawer-rate').textContent = formatMoney(employee.pay_rate);
    $('#employee-drawer-advance').textContent = employee.advance
        ? formatMoney(employee.advance.outstanding)
        : '—';

    $('#employee-drawer-edit').classList.toggle('hidden', !can('UPDATE', 'STAFF'));
    $('#employee-drawer-delete').classList.toggle('hidden', !can('DELETE', 'STAFF'));
    $('#employee-drawer-advance-action').classList.toggle(
        'hidden',
        !can('WRITE', 'STAFF') || !employee.is_active,
    );

    $('#employee-drawer-body').innerHTML = employeeDetails(employee)
        + '<div class="mt-5"><span class="skel w-1/2"></span></div>';

    showModal('#employee-drawer');

    /*
    | The pay history, fetched rather than derived.
    |
    | What somebody was actually paid in a month is the payslip that was written
    | at the time — with the rate and the days it used — and reconstructing it
    | here from today's rate would be a different number the moment anybody had a
    | raise. The list request does not carry it: a year of payslips per row would
    | be a query per employee.
    */
    try {
        /*
        | Two requests, in parallel, because they answer unrelated questions and
        | neither should wait on the other: what this person is paid, and what
        | they have got through on the shop floor — M22.
        |
        | `allSettled`, so a workshop that records no attribution — or an error on
        | one of the two — still gets the half that worked. A drawer that showed
        | nothing because the work query failed would be worse than one showing
        | the pay history with the work section absent.
        */
        const [record, work] = await Promise.allSettled([
            auth.call(`/staff/${employee.id}`),
            auth.call(`/staff/${employee.id}/work?per_page=10`),
        ]);

        // The reader may have moved on while this was in flight.
        if (state.openEmployee?.id !== employee.id) return;

        if (record.status === 'rejected') throw record.reason;

        const payload = record.value;

        $('#employee-drawer-body').innerHTML = employeeDetails(payload.data)
            + workHistory(work.status === 'fulfilled' ? work.value : null)
            + payslipHistory(payload.meta?.payslips ?? []);
    } catch (error) {
        if (state.openEmployee?.id !== employee.id) return;

        $('#employee-drawer-body').innerHTML = employeeDetails(employee)
            + `<p class="mt-5 text-[0.8125rem] text-rose-600">${esc(error.message)}</p>`;
    }
}

function employeeDetails(employee) {
    const counts = employee.attendance?.counts ?? {};
    const marked = Object.entries(counts)
        .map(([value, days]) => `${statusLabel(value)} ${days}`)
        .join(' · ');

    return `
        <dl class="dl">
            <dt>Paid</dt>
            <dd>${esc(employee.salary_basis_label ?? '')} — ${esc(formatMoney(employee.pay_rate))}</dd>

            <dt>Designation</dt>
            <dd>${employee.designation
                ? esc(employee.designation.name)
                : '<span class="text-muted-foreground">None</span>'}</dd>

            <dt>Phone</dt>
            <dd>${employee.phone ? esc(employee.phone) : '<span class="text-muted-foreground">—</span>'}</dd>

            <dt>Joined</dt>
            <dd>${esc(formatDate(employee.joined_on))}</dd>

            ${employee.left_on
                ? `<dt>Left</dt><dd>${esc(formatDate(employee.left_on))}</dd>`
                : ''}

            <dt>${esc(employee.attendance?.period_label ?? 'This month')}</dt>
            <dd>${marked === ''
                ? '<span class="text-muted-foreground">Nothing marked</span>'
                : esc(marked)}</dd>

            <dt>Advance out</dt>
            <dd>${employee.advance
                ? esc(formatMoney(employee.advance.outstanding))
                : '<span class="text-muted-foreground">—</span>'}</dd>
        </dl>`;
}

/**
 * What this person has actually been paid, month by month.
 *
 * The one thing a rate on its own cannot answer. "Why was September short" is
 * settled by a row saying nineteen of thirty-one days, and every figure in it is
 * the snapshot the run posted rather than anything recomputed now.
 */
function payslipHistory(payslips) {
    const heading = '<h4 class="mt-6 mb-2 text-[0.6875rem] font-semibold uppercase tracking-wider '
        + 'text-muted-foreground">Paid</h4>';

    if (!payslips.length) {
        return `${heading}<p class="text-[0.8125rem] text-muted-foreground">
            No payroll has been run for this person yet.</p>`;
    }

    const rows = payslips.map((slip) => `
        <div class="flex items-baseline justify-between gap-3 border-b border-muted py-2 last:border-0
                    ${slip.run_status === 'reversed' ? 'opacity-60' : ''}">
            <div class="min-w-0">
                <div class="text-[0.8125rem] font-medium text-foreground">
                    ${esc(slip.period_label ?? '')}
                    ${slip.run_status === 'reversed'
                        ? '<span class="badge bg-muted text-secondary-foreground">Reversed</span>'
                        : ''}
                </div>
                <div class="text-[0.75rem] text-muted-foreground">
                    ${esc(String(slip.paid_days))} of ${esc(String(slip.period_days))} days
                    ${Number(slip.advance_recovered) > 0
                        ? ` · ${esc(formatMoney(slip.advance_recovered))} recovered`
                        : ''}
                </div>
            </div>
            <div class="shrink-0 text-right">
                <div class="text-[0.875rem] font-semibold text-foreground">${esc(formatMoney(slip.net))}</div>
                <div class="text-[0.75rem] text-muted-foreground">of ${esc(formatMoney(slip.gross))}</div>
            </div>
        </div>`).join('');

    return heading + rows;
}

/**
 * What this person has got through on the shop floor — M22.
 *
 * ## Why two figures and not one
 *
 * **Jobs** is throughput: eleven motors is eleven motors. **Value** is what
 * those invoices came to, and it is the one an owner reaches for and the one
 * most easily misread — a bill that is mostly bearings credits its fitter with
 * the bearings, because the document does not separate the labour from the
 * parts. Showing both is what stops either being taken for a measure of effort.
 *
 * ## Why this is not next to the pay
 *
 * Because it is not an input to it. Payroll computes from a rate and an
 * attendance sheet, in one place, and a throughput figure sitting inside the
 * pay panel would be read as a piece rate by the first person to see it — which
 * would make it a second source of truth for wages, arrived at by accident.
 *
 * Silent where the workshop records no attribution at all: a section headed
 * "Work done" with nothing under it invites somebody to go looking for the
 * setting, and there is a better place for that — the Designation Master, where
 * the trades are.
 */
function workHistory(payload) {
    const summary = payload?.meta?.summary ?? null;
    const invoices = payload?.data ?? [];

    if (summary === null) return '';

    // Nobody has been credited with anything, ever. Not an error and not worth a
    // panel — a workshop that has only just ticked the boxes would see this on
    // every person for a fortnight.
    if (summary.job_count === 0) return '';

    const heading = '<h4 class="mt-6 mb-2 text-[0.6875rem] font-semibold uppercase tracking-wider '
        + 'text-muted-foreground">Work done</h4>';

    const totals = `
        <div class="mb-2 flex gap-6 rounded-[10px] bg-secondary/50 px-3.5 py-2.5">
            <div>
                <div class="text-[0.75rem] text-muted-foreground">Jobs</div>
                <div class="text-[0.9375rem] font-semibold text-foreground">
                    ${esc(String(summary.job_count))}
                </div>
            </div>
            <div>
                <div class="text-[0.75rem] text-muted-foreground">Invoiced</div>
                <div class="text-[0.9375rem] font-semibold text-foreground">
                    ${esc(formatMoney(summary.invoice_value))}
                </div>
            </div>
        </div>`;

    const rows = invoices.map((invoice) => `
        <div class="flex items-baseline justify-between gap-3 border-b border-muted py-2 last:border-0">
            <div class="min-w-0">
                <div class="truncate text-[0.8125rem] font-medium text-foreground">
                    ${esc(invoice.doc_no ?? `#${invoice.id}`)}
                    ${invoice.trades?.length
                        ? `<span class="badge bg-accent text-accent-foreground">
                               ${esc(invoice.trades.join(', '))}
                           </span>`
                        : ''}
                </div>
                <div class="truncate text-[0.75rem] text-muted-foreground">
                    ${esc(formatDate(invoice.date))}${invoice.party ? ` · ${esc(invoice.party)}` : ''}
                </div>
            </div>
            <div class="shrink-0 text-[0.875rem] font-semibold text-foreground">
                ${esc(formatMoney(invoice.total))}
            </div>
        </div>`).join('');

    // The drawer shows the most recent ten. Saying so beats a list that looks
    // complete and is not — "eleven jobs" over ten rows is a discrepancy
    // somebody would otherwise have to work out for themselves.
    const more = summary.job_count > invoices.length
        ? `<p class="mt-2 text-[0.75rem] text-muted-foreground">
               Showing the ${invoices.length} most recent of ${summary.job_count}.
           </p>`
        : '';

    return heading + totals + rows + more;
}

/* -------------------------------------------------------------------------
 | Section 2 — Attendance
 | ---------------------------------------------------------------------- */

async function loadDaySheet() {
    const body = inForm('attendance', '#attendance-body');
    body.innerHTML = tableMessage(3, 'Loading the day…');

    try {
        const payload = await auth.call(`/staff/attendance?date=${state.attendance.date}`);

        state.attendance.rows = (payload.data ?? []).map((row) => ({
            employee: row.employee,
            status: row.status,
            notes: row.notes ?? '',
        }));

        renderDaySheet();
    } catch (error) {
        body.innerHTML = tableMessage(3, error.message, 'error');
        inForm('attendance', '#attendance-summary').textContent = '';
    }
}

function renderDaySheet() {
    const body = inForm('attendance', '#attendance-body');
    const rows = state.attendance.rows;

    inForm('attendance', '#attendance-date').value = state.attendance.date;

    if (!rows.length) {
        body.innerHTML = tableMessage(
            3,
            'Nobody was on the payroll on this day. Add somebody on the Staff tab first.',
        );
        inForm('attendance', '#attendance-summary').textContent = '';

        return;
    }

    const mayMark = can('UPDATE', 'STAFF');

    body.innerHTML = rows.map((row) => {
        const chips = state.meta.statuses.map((status) => `
            <button type="button" class="pill" data-mark="${row.employee.id}"
                    data-status="${esc(status.value)}"
                    aria-pressed="${row.status === status.value}"
                    ${mayMark ? '' : 'disabled'}>${esc(status.label)}</button>`).join('');

        return `
            <tr data-attendance-row="${row.employee.id}">
                <td class="px-4 py-3 align-top">
                    <div class="text-[0.875rem] font-semibold text-foreground">${esc(row.employee.name)}</div>
                    <div class="text-[0.75rem] text-muted-foreground">
                        ${esc(row.employee.designation?.name ?? row.employee.salary_basis_short ?? '')}
                    </div>
                </td>
                <td class="px-4 py-3">
                    <div class="flex flex-wrap gap-1.5">
                        ${chips}
                        <button type="button" class="pill" data-mark="${row.employee.id}" data-status=""
                                aria-pressed="${!row.status}" ${mayMark ? '' : 'disabled'}
                                title="Back to unmarked">Not marked</button>
                    </div>
                </td>
                <td class="px-4 py-3 align-top">
                    <input type="text" class="field-input" maxlength="190" data-note="${row.employee.id}"
                           value="${esc(row.notes)}" placeholder="Optional"
                           ${mayMark ? '' : 'disabled'}>
                </td>
            </tr>`;
    }).join('');

    renderDaySummary();
}

/**
 * What the day adds up to, with the unmarked count first.
 *
 * First because it is the number somebody filling the sheet in is actually
 * checking: everybody else is accounted for, and the remainder is what they have
 * not got to yet.
 */
function renderDaySummary() {
    const rows = state.attendance.rows;
    const unmarked = rows.filter((row) => !row.status).length;

    const counted = state.meta.statuses
        .map((status) => {
            const n = rows.filter((row) => row.status === status.value).length;

            return n === 0 ? null : `${status.label} ${n}`;
        })
        .filter(Boolean);

    inForm('attendance', '#attendance-summary').textContent = [
        `${rows.length} on the payroll`,
        unmarked > 0 ? `${unmarked} not marked` : 'all marked',
        ...counted,
    ].join(' · ');
}

function markAll(status) {
    state.attendance.rows.forEach((row) => {
        row.status = status;
    });

    renderDaySheet();
}

async function saveDaySheet() {
    const button = inForm('attendance', '#attendance-save');

    button.disabled = true;
    button.textContent = 'Saving…';

    try {
        const payload = await auth.call('/staff/attendance', {
            method: 'PUT',
            body: {
                date: state.attendance.date,
                rows: state.attendance.rows.map((row) => ({
                    employee_id: row.employee.id,
                    // null clears the mark, which is a correction somebody
                    // genuinely makes — see the request class.
                    status: row.status ?? null,
                    notes: row.notes || null,
                })),
            },
        });

        // Repainted from the server's answer rather than from what we hoped we
        // had sent, so a row the server declined to write cannot look saved.
        state.attendance.rows = (payload.data ?? []).map((row) => ({
            employee: row.employee,
            status: row.status,
            notes: row.notes ?? '',
        }));

        renderDaySheet();
        toast(payload.message ?? 'Attendance saved.');

        /*
        | The staff list's "this month" column and the register are now out of
        | date — but only refetched where they are actually held (§2A.7). A
        | module opened to mark a day must not be made to fetch a register by
        | saving one.
        */
        if (state.employeesLoaded) {
            await ensureEmployees({ force: true });

            if (sections.people.workspace?.hasList()) renderPeople();
        }

        if (state.attendance.register) await loadRegister();
    } catch (error) {
        toast(error.message, 'error');
    } finally {
        button.disabled = false;
        button.textContent = 'Save the day';
    }
}

async function loadRegister() {
    const body = inList('attendance', '#register-body');
    body.innerHTML = tableMessage(2, 'Loading the register…');

    try {
        const payload = await auth.call(`/staff/attendance?period=${state.attendance.registerMonth}`);

        state.attendance.register = {
            rows: payload.data ?? [],
            days: payload.meta?.days ?? daysIn(state.attendance.registerMonth),
            from: payload.meta?.from,
        };

        renderRegister();
    } catch (error) {
        body.innerHTML = tableMessage(2, error.message, 'error');
    }
}

function renderRegister() {
    const register = state.attendance.register;

    inList('attendance', '#register-month').value = state.attendance.registerMonth;
    renderRegisterLegend();

    if (!register) return;

    const days = Array.from({ length: register.days }, (unused, index) => index + 1);

    inList('attendance', '#register-head').innerHTML = `
        <tr class="border-b border-border bg-background">
            <th class="sticky left-0 z-10 bg-background px-4 py-3 text-left text-[11.5px] font-semibold
                       whitespace-nowrap text-muted-foreground" scope="col">Name</th>
            ${days.map((day) => `<th class="px-1 py-3 text-[11px] font-semibold text-muted-foreground"
                                     scope="col">${day}</th>`).join('')}
            <th class="px-3 py-3 text-right text-[11.5px] font-semibold whitespace-nowrap
                       text-muted-foreground" scope="col">Marked</th>
        </tr>`;

    const body = inList('attendance', '#register-body');

    if (!register.rows.length) {
        body.innerHTML = tableMessage(
            register.days + 2,
            'Nobody was on the payroll in this month.',
        );
        inList('attendance', '#register-summary').textContent = '';

        return;
    }

    const month = state.attendance.registerMonth;

    body.innerHTML = register.rows.map((row) => {
        const marked = Object.values(row.counts ?? {}).reduce((sum, n) => sum + n, 0);

        const cells = days.map((day) => {
            const key = `${month}-${String(day).padStart(2, '0')}`;
            const mark = row.marks?.[key];

            if (!mark) {
                // A gap, painted as one. What it is worth depends on the basis,
                // and that decision is the server's — see SalaryBasis.
                return '<td class="px-1 py-2 text-[11px] text-border">·</td>';
            }

            const meta = statusMeta(mark.status);

            return `<td class="px-1 py-2">
                <span class="inline-grid size-[22px] place-items-center rounded-[6px] text-[11px]
                             font-semibold ${esc(meta?.tone ?? '')}"
                      title="${esc(meta?.label ?? mark.status)}${mark.notes ? ` — ${esc(mark.notes)}` : ''}"
                >${esc(meta?.initial ?? '?')}</span>
            </td>`;
        }).join('');

        return `
            <tr class="transition hover:bg-secondary/40">
                <td class="sticky left-0 z-10 bg-card px-4 py-2 text-left">
                    <div class="truncate text-[0.8125rem] font-semibold text-foreground">
                        ${esc(row.employee.name)}
                    </div>
                    <div class="truncate text-[0.75rem] text-muted-foreground">
                        ${esc(row.employee.salary_basis_short ?? '')}
                    </div>
                </td>
                ${cells}
                <td class="px-3 py-2 text-right text-[0.8125rem] whitespace-nowrap text-muted-foreground">
                    ${marked} of ${register.days}
                </td>
            </tr>`;
    }).join('');

    inList('attendance', '#register-summary').textContent =
        `${register.rows.length} on the payroll in ${monthLabel(month)}. `
        + 'A dot is a day nobody marked — paid on a monthly salary, unpaid on a daily wage.';
}

function renderRegisterLegend() {
    inList('attendance', '#register-legend').innerHTML = state.meta.statuses.map((status) => `
        <span class="flex items-center gap-1.5 text-[0.78125rem] text-muted-foreground">
            <span class="inline-grid size-[22px] place-items-center rounded-[6px] text-[11px]
                         font-semibold ${esc(status.tone)}">${esc(status.initial)}</span>
            ${esc(status.label)}
        </span>`).join('')
        + '<span class="flex items-center gap-1.5 text-[0.78125rem] text-muted-foreground">'
        + '<span class="inline-grid size-[22px] place-items-center text-[11px] text-border">·</span>'
        + 'Not marked</span>';
}

function exportRegister() {
    const register = state.attendance.register;
    if (!register) return;

    const month = state.attendance.registerMonth;
    const days = Array.from({ length: register.days }, (unused, index) => index + 1);

    const rows = [['Name', 'Paid', ...days.map(String), 'Marked']];

    register.rows.forEach((row) => {
        const marked = Object.values(row.counts ?? {}).reduce((sum, n) => sum + n, 0);

        rows.push([
            row.employee.name,
            row.employee.salary_basis_short ?? '',
            ...days.map((day) => {
                const mark = row.marks?.[`${month}-${String(day).padStart(2, '0')}`];

                return mark ? (statusMeta(mark.status)?.initial ?? '?') : '';
            }),
            `${marked} of ${register.days}`,
        ]);
    });

    downloadCsv(`attendance-${month}.csv`, rows);
}

/* -------------------------------------------------------------------------
 | Section 3 — Payroll
 | ---------------------------------------------------------------------- */

async function loadSheet() {
    const body = inForm('payroll', '#payroll-body');
    body.innerHTML = tableMessage(7, 'Computing the month…');

    try {
        const payload = await auth.call('/staff/payroll/preview', {
            method: 'POST',
            body: {
                period: state.payroll.month,
                recoveries: state.payroll.recoveries,
            },
        });

        state.payroll.sheet = {
            rows: payload.data ?? [],
            meta: payload.meta ?? {},
        };

        // The server capped whatever was typed, so the boxes are refilled from
        // its answer rather than from what was sent — the two can differ, and
        // its answer is the one that will post.
        state.payroll.recoveries = {};
        (payload.data ?? []).forEach((row) => {
            state.payroll.recoveries[row.employee.id] = row.advance_recovered;
        });

        renderSheet();
    } catch (error) {
        body.innerHTML = tableMessage(7, error.message, 'error');
        inForm('payroll', '#payroll-foot').innerHTML = '';
    }
}

function renderSheet() {
    const sheet = state.payroll.sheet;

    inForm('payroll', '#payroll-month').value = state.payroll.month;

    if (!sheet) return;

    const existing = sheet.meta.existing_run;
    const banner = inForm('payroll', '#payroll-existing');

    banner.classList.toggle('hidden', !existing);

    if (existing) {
        banner.textContent = `${existing.period_label} has already been paid`
            + `${existing.transaction?.doc_no ? ` on ${existing.transaction.doc_no}` : ''}`
            + ` — ${formatMoney(existing.net)} to ${existing.headcount} staff. `
            + 'Running it again would pay everybody twice. Reverse that run first if it was wrong.';
    }

    const body = inForm('payroll', '#payroll-body');

    if (!sheet.rows.length) {
        body.innerHTML = tableMessage(
            7,
            `Nobody was on the payroll in ${monthLabel(state.payroll.month)}.`,
        );
        inForm('payroll', '#payroll-foot').innerHTML = '';
        renderSheetHint();

        return;
    }

    const mayPost = can('WRITE', 'STAFF');

    body.innerHTML = sheet.rows.map((row) => {
        const outstanding = Number(row.advance_outstanding);
        const recovered = state.payroll.recoveries[row.employee.id] ?? row.advance_recovered;
        const net = (Number(row.gross) - Number(recovered)).toFixed(2);

        /*
        | Somebody who earned nothing is shown, not dropped.
        |
        | A daily-wage helper computing to zero because nobody marked their days
        | is the single likeliest thing to be wrong with a month, and a row that
        | silently vanished would take the evidence with it. They get no payslip
        | when the run posts — there is nothing on it — but they are on the sheet
        | somebody is checking before they press Post.
        */
        const muted = row.is_payable ? '' : ' opacity-60';

        const unmarkedNote = row.unmarked_days > 0
            ? `<div class="text-[0.75rem] ${row.is_payable ? 'text-muted-foreground' : 'text-rose-600'}">
                   ${row.unmarked_days} day${row.unmarked_days === 1 ? '' : 's'} not marked
               </div>`
            : '';

        return `
            <tr class="${muted}">
                <td class="px-4 py-3">
                    <div class="text-[0.875rem] font-semibold text-foreground">${esc(row.employee.name)}</div>
                    ${unmarkedNote}
                </td>
                <td class="px-4 py-3 text-[0.8125rem] whitespace-nowrap text-muted-foreground">
                    ${esc(row.salary_basis_short)} · ${esc(formatMoney(row.pay_rate))}
                </td>
                <td class="px-4 py-3 text-right text-[0.8125rem] whitespace-nowrap">
                    <span class="font-medium text-foreground">${esc(String(row.paid_days))}</span>
                    <span class="text-muted-foreground">
                        / ${esc(String(row.eligible_days))}
                    </span>
                </td>
                <td class="px-4 py-3 text-right text-[0.875rem] font-semibold whitespace-nowrap text-foreground">
                    ${esc(formatMoney(row.gross))}
                </td>
                <td class="px-4 py-3 text-right text-[0.8125rem] whitespace-nowrap
                           ${outstanding > 0 ? 'text-amber-600' : 'text-muted-foreground'}">
                    ${outstanding > 0 ? esc(formatMoney(row.advance_outstanding)) : '—'}
                </td>
                <td class="px-4 py-3 text-right">
                    ${outstanding > 0 && row.is_payable && mayPost
                        ? `<input type="text" class="field-input w-28 text-right font-mono"
                                  inputmode="decimal" data-recover="${row.employee.id}"
                                  value="${esc(recovered)}" aria-label="Recover from ${esc(row.employee.name)}">`
                        : `<span class="text-[0.8125rem] text-muted-foreground">
                               ${Number(recovered) > 0 ? esc(formatMoney(recovered)) : '—'}
                           </span>`}
                </td>
                <td class="px-4 py-3 text-right text-[0.875rem] font-bold whitespace-nowrap text-foreground">
                    ${esc(formatMoney(net))}
                </td>
            </tr>`;
    }).join('');

    renderSheetTotals();
    renderSheetHint();
    payrollPayments?.repaint();
}

/**
 * The totals, recomputed here from the boxes rather than refetched.
 *
 * The one arithmetic this file does — `gross − recovery`, summed — and it is
 * display only. The server recomputes the whole sheet when it posts, and its
 * answer is the one that reaches the ledger. Doing a round trip per keystroke to
 * subtract two numbers would make the column unusable on a phone.
 */
function sheetTotals() {
    const rows = state.payroll.sheet?.rows ?? [];

    let gross = 0;
    let recovered = 0;
    let headcount = 0;

    rows.forEach((row) => {
        if (!row.is_payable) return;

        headcount += 1;
        gross += Number(row.gross);
        recovered += Number(state.payroll.recoveries[row.employee.id] ?? row.advance_recovered);
    });

    return {
        gross: gross.toFixed(2),
        recovered: recovered.toFixed(2),
        net: (gross - recovered).toFixed(2),
        headcount,
    };
}

function renderSheetTotals() {
    const totals = sheetTotals();

    inForm('payroll', '#payroll-foot').innerHTML = `
        <tr class="bg-background">
            <td class="px-4 py-3 text-[0.8125rem] font-semibold text-foreground" colspan="3">
                ${totals.headcount} to pay
            </td>
            <td class="px-4 py-3 text-right text-[0.875rem] font-bold whitespace-nowrap text-foreground">
                ${esc(formatMoney(totals.gross))}
            </td>
            <td class="px-4 py-3"></td>
            <td class="px-4 py-3 text-right text-[0.875rem] font-semibold whitespace-nowrap text-amber-600">
                ${Number(totals.recovered) > 0 ? esc(formatMoney(totals.recovered)) : '—'}
            </td>
            <td class="px-4 py-3 text-right text-[1rem] font-bold whitespace-nowrap text-foreground">
                ${esc(formatMoney(totals.net))}
            </td>
        </tr>`;
}

function renderSheetHint() {
    const totals = sheetTotals();

    inForm('payroll', '#payroll-post-hint').textContent = Number(totals.gross) > 0
        ? `${formatMoney(totals.gross)} earned, ${formatMoney(totals.recovered)} recovered against advances, `
            + `${formatMoney(totals.net)} to hand over.`
        : 'Nothing to post for this month.';
}

async function postPayroll() {
    const totals = sheetTotals();

    if (Number(totals.gross) <= 0) {
        toast('There is nothing to post for this month.', 'error');

        return;
    }

    const split = payrollPayments?.value() ?? [];
    const paid = Number(payrollPayments?.total() ?? 0);

    // Checked here so the message names the shortfall while somebody is looking
    // at the boxes. The server refuses it either way, with the same arithmetic.
    if (Math.abs(paid - Number(totals.net)) > 0.005) {
        toast(
            `The payment has to come to ${formatMoney(totals.net)} — it currently comes to `
            + `${formatMoney(paid.toFixed(2))}.`,
            'error',
        );

        return;
    }

    const confirmed = await confirmAction({
        title: `Post ${monthLabel(state.payroll.month)} payroll?`,
        body: `${formatMoney(totals.gross)} to ${totals.headcount} staff, `
            + `${formatMoney(totals.recovered)} recovered against advances, `
            + `${formatMoney(totals.net)} handed over. `
            + 'This posts to the ledger. Correcting it afterwards means reversing the whole run.',
        confirmLabel: 'Post payroll',
        tone: 'primary',
    });

    if (!confirmed) return;

    const button = inForm('payroll', '#payroll-post');

    button.disabled = true;
    button.textContent = 'Posting…';

    try {
        const payload = await auth.call('/staff/payroll', {
            method: 'POST',
            body: {
                period: state.payroll.month,
                payments: split,
                recoveries: state.payroll.recoveries,
                notes: inForm('payroll', '#payroll-notes').value.trim() || null,
                // A retry after a timeout must not pay everybody twice — M17.
                // Generated per run rather than per attempt, which is the whole
                // mechanism.
                client_ref: state.payroll.clientRef,
            },
        });

        toast(payload.message ?? 'Payroll posted.');

        // A fresh reference for the next run: reusing this one would have the
        // next month return this month's voucher.
        state.payroll.clientRef = crypto.randomUUID();

        payrollPayments?.reset();
        inForm('payroll', '#payroll-notes').value = '';

        // Everything downstream has moved: the advances are recovered, the staff
        // list's outstanding column is stale, and the runs list has a new row.
        await Promise.all([
            loadSheet(),
            state.employeesLoaded ? ensureEmployees({ force: true }) : Promise.resolve(),
            sections.payroll.workspace?.hasList() ? loadRuns() : Promise.resolve(),
            sections.advances.opened ? loadAdvances() : Promise.resolve(),
        ]);

        if (sections.people.workspace?.hasList()) renderPeople();
    } catch (error) {
        toast(error.message, 'error');
    } finally {
        button.disabled = false;
        button.textContent = 'Post payroll';
    }
}

async function loadRuns() {
    const body = inList('payroll', '#payroll-runs-body');
    body.innerHTML = tableMessage(RUN_COLUMNS, 'Loading payroll runs…');

    try {
        const payload = await auth.call('/staff/payroll?per_page=24');

        state.payroll.runs = payload.data ?? [];
        state.payroll.runsLoaded = true;

        renderRuns();
    } catch (error) {
        body.innerHTML = tableMessage(RUN_COLUMNS, error.message, 'error');
    }
}

function renderRuns() {
    const body = inList('payroll', '#payroll-runs-body');
    const runs = state.payroll.runs;

    if (!runs.length) {
        body.innerHTML = tableMessage(RUN_COLUMNS, 'No payroll has been run yet.');
        inList('payroll', '#payroll-runs-summary').textContent = '';

        return;
    }

    body.innerHTML = runs.map((run) => `
        <tr class="cursor-pointer transition hover:bg-secondary/60 ${run.is_live ? '' : 'opacity-60'}"
            data-run="${run.id}" tabindex="0" role="button"
            aria-label="Open ${esc(run.period_label)} payroll">
            <td class="px-4 py-3 text-[0.875rem] font-semibold whitespace-nowrap text-foreground">
                ${esc(run.period_label)}
            </td>
            <td class="px-4 py-3 text-[0.8125rem] whitespace-nowrap text-muted-foreground">
                ${esc(run.transaction?.doc_no ?? '—')}
                ${run.paid_on ? `<span class="block text-[0.75rem]">${esc(formatDate(run.paid_on))}</span>` : ''}
            </td>
            <td class="px-4 py-3 text-right text-[0.8125rem] text-muted-foreground">${run.headcount}</td>
            <td class="px-4 py-3 text-right text-[0.875rem] font-semibold whitespace-nowrap text-foreground">
                ${esc(formatMoney(run.gross))}
            </td>
            <td class="px-4 py-3 text-right text-[0.8125rem] whitespace-nowrap text-amber-600">
                ${Number(run.advance_recovered) > 0 ? esc(formatMoney(run.advance_recovered)) : '—'}
            </td>
            <td class="px-4 py-3 text-right text-[0.875rem] font-bold whitespace-nowrap text-foreground">
                ${esc(formatMoney(run.net))}
            </td>
            <td class="px-4 py-3">
                <span class="badge ${esc(run.status_tone)}">${esc(run.status_label)}</span>
            </td>
        </tr>`).join('');

    inList('payroll', '#payroll-runs-summary').textContent =
        `${runs.length} run${runs.length === 1 ? '' : 's'}. Click one to see who was paid what.`;

    sections.payroll.workspace?.refresh();
}

async function openRunDrawer(id) {
    const run = state.payroll.runs.find((row) => String(row.id) === String(id));
    if (!run) return;

    state.payroll.openRun = run;

    $('#payroll-drawer-title').textContent = `${run.period_label} payroll`;
    $('#payroll-drawer-subtitle').textContent = [
        run.transaction?.doc_no,
        run.paid_on ? `paid ${formatDate(run.paid_on)}` : null,
        run.posted_by ? `by ${run.posted_by}` : null,
    ].filter(Boolean).join(' · ');

    $('#payroll-drawer-status').innerHTML =
        `<span class="badge ${esc(run.status_tone)}">${esc(run.status_label)}</span>`;

    $('#payroll-drawer-gross').textContent = formatMoney(run.gross);
    $('#payroll-drawer-recovered').textContent = formatMoney(run.advance_recovered);
    $('#payroll-drawer-net').textContent = formatMoney(run.net);

    $('#payroll-drawer-reverse').classList.toggle(
        'hidden',
        !run.is_live || !can('UPDATE', 'STAFF'),
    );

    $('#payroll-drawer-body').innerHTML = '<span class="skel w-2/3"></span>';

    showModal('#payroll-drawer');

    try {
        const payload = await auth.call(`/staff/payroll/${run.id}`);

        if (state.payroll.openRun?.id !== run.id) return;

        state.payroll.openRun = payload.data;
        $('#payroll-drawer-body').innerHTML = payslipTable(payload.data.lines ?? []);
    } catch (error) {
        if (state.payroll.openRun?.id !== run.id) return;

        $('#payroll-drawer-body').innerHTML =
            `<p class="text-[0.8125rem] text-rose-600">${esc(error.message)}</p>`;
    }
}

/**
 * Who got what — the whole reason `payroll_lines` exists.
 *
 * The ledger holds one voucher for the run, so this breakdown is recoverable
 * from nowhere else. Every figure is the snapshot the run posted, which is why a
 * raise since then does not change it.
 */
function payslipTable(lines) {
    if (!lines.length) {
        return '<p class="text-[0.8125rem] text-muted-foreground">This run has no payslips on it.</p>';
    }

    return `
        <div class="overflow-x-auto">
            <table class="w-full min-w-[520px] border-collapse">
                <thead>
                    <tr class="border-b border-border text-left">
                        <th class="py-2 text-[11.5px] font-semibold text-muted-foreground" scope="col">Name</th>
                        <th class="py-2 text-right text-[11.5px] font-semibold text-muted-foreground" scope="col">Days</th>
                        <th class="py-2 text-right text-[11.5px] font-semibold text-muted-foreground" scope="col">Earned</th>
                        <th class="py-2 text-right text-[11.5px] font-semibold text-muted-foreground" scope="col">Recovered</th>
                        <th class="py-2 text-right text-[11.5px] font-semibold text-muted-foreground" scope="col">Paid</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-muted">
                    ${lines.map((line) => `
                        <tr>
                            <td class="py-2.5">
                                <div class="text-[0.8125rem] font-medium text-foreground">
                                    ${esc(line.employee_name)}
                                </div>
                                <div class="text-[0.75rem] text-muted-foreground">
                                    ${esc(line.designation ?? line.salary_basis_short)}
                                    · ${esc(formatMoney(line.pay_rate))}
                                </div>
                            </td>
                            <td class="py-2.5 text-right text-[0.8125rem] whitespace-nowrap text-muted-foreground">
                                ${esc(String(line.paid_days))} / ${esc(String(line.period_days))}
                            </td>
                            <td class="py-2.5 text-right text-[0.8125rem] whitespace-nowrap text-foreground">
                                ${esc(formatMoney(line.gross))}
                            </td>
                            <td class="py-2.5 text-right text-[0.8125rem] whitespace-nowrap text-amber-600">
                                ${Number(line.advance_recovered) > 0
                                    ? esc(formatMoney(line.advance_recovered))
                                    : '—'}
                            </td>
                            <td class="py-2.5 text-right text-[0.8125rem] font-semibold whitespace-nowrap text-foreground">
                                ${esc(formatMoney(line.net))}
                            </td>
                        </tr>`).join('')}
                </tbody>
            </table>
        </div>`;
}

async function reverseRun() {
    const run = state.payroll.openRun;
    if (!run) return;

    const confirmed = await confirmAction({
        title: `Reverse ${run.period_label} payroll?`,
        body: 'The ledger entries are cancelled by their mirror image — nothing is deleted from the books — '
            + 'and any advances this run recovered go back to being outstanding. The month is then free to '
            + 'run again against the attendance as it now stands.',
        confirmLabel: 'Reverse the run',
    });

    if (!confirmed) return;

    try {
        const payload = await auth.call(`/staff/payroll/${run.id}/reverse`, {
            method: 'POST',
            body: {},
        });

        toast(payload.message ?? 'Payroll reversed.');
        hideModal('#payroll-drawer');
        state.payroll.openRun = null;

        await Promise.all([
            loadRuns(),
            state.employeesLoaded ? ensureEmployees({ force: true }) : Promise.resolve(),
            sections.advances.opened ? loadAdvances() : Promise.resolve(),
        ]);

        if (sections.people.workspace?.hasList()) renderPeople();

        // The sheet on the form side is now describing a month that is free
        // again, so the banner has to go.
        if (state.payroll.sheet) await loadSheet();
    } catch (error) {
        toast(error.message, 'error');
    }
}

function exportRun() {
    const run = state.payroll.openRun;
    if (!run?.lines) return;

    const rows = [['Name', 'Designation', 'Paid', 'Rate', 'Days paid', 'Days in month', 'Earned', 'Recovered', 'Net']];

    run.lines.forEach((line) => {
        rows.push([
            line.employee_name,
            line.designation ?? '',
            line.salary_basis_label,
            line.pay_rate,
            line.paid_days,
            line.period_days,
            line.gross,
            line.advance_recovered,
            line.net,
        ]);
    });

    downloadCsv(`payroll-${run.period}.csv`, rows);
}

/* -------------------------------------------------------------------------
 | Section 4 — Advances
 | ---------------------------------------------------------------------- */

function paintEmployeePickers() {
    const pickers = [
        { select: advanceForm ? $('#advance-employee', advanceForm) : null, blank: 'Choose somebody…', activeOnly: true },
        { select: sections.advances?.list ? inList('advances', '#advances-filter-employee') : null, blank: 'Everybody', activeOnly: false },
    ];

    pickers.forEach(({ select, blank, activeOnly }) => {
        if (!select) return;

        const previous = select.value;
        const rows = activeOnly ? activeEmployees() : state.employees;

        select.innerHTML = [`<option value="">${esc(blank)}</option>`].concat(
            rows.map((row) => `<option value="${row.id}">${esc(row.name)}</option>`),
        ).join('');

        select.value = previous;
    });

    paintAdvanceHint();
}

/**
 * What is already out with the person the form is pointed at.
 *
 * Stated at the moment it can still change the decision, which is the same
 * reasoning the bill form's party picker follows: somebody about to hand over
 * another ₹5,000 should be able to see the ₹8,000 that has not come back yet
 * without opening a second screen.
 */
function paintAdvanceHint() {
    if (!advanceForm) return;

    const hint = $('#advance-outstanding-hint', advanceForm);
    const id = $('#advance-employee', advanceForm).value;

    if (!id) {
        hint.textContent = '';
        hint.className = 'mt-1.5 text-xs text-muted-foreground';

        return;
    }

    const employee = state.employees.find((row) => String(row.id) === String(id));
    const outstanding = Number(employee?.advance?.outstanding ?? 0);

    hint.className = outstanding > 0
        ? 'mt-1.5 text-xs font-medium text-amber-600'
        : 'mt-1.5 text-xs text-muted-foreground';

    hint.textContent = outstanding > 0
        ? `${formatMoney(employee.advance.outstanding)} is already out with them.`
        : 'Nothing is out with them at the moment.';
}

async function submitAdvance(event) {
    event.preventDefault();

    clearFormErrors(advanceForm);

    const employeeId = $('#advance-employee', advanceForm).value;

    if (!employeeId) {
        showFormErrors(advanceForm, {
            fields: { employee_id: ['Say who the advance is for.'] },
            message: 'Say who the advance is for.',
        });

        return;
    }

    const split = advancePayments.value();
    const total = advancePayments.total();

    if (!split.length) {
        toast('Enter how much is being handed over.', 'error');

        return;
    }

    const employee = state.employees.find((row) => String(row.id) === String(employeeId));

    const confirmed = await confirmAction({
        title: `Pay ${formatMoney(total)} to ${employee?.name ?? 'them'}?`,
        body: 'This posts to the ledger straight away — an advance is cash in somebody’s hand. '
            + 'It comes back off the next payroll, and an advance typed wrong is cancelled rather than edited.',
        confirmLabel: 'Pay advance',
        tone: 'primary',
    });

    if (!confirmed) return;

    setSubmitting(advanceForm, true);

    try {
        const payload = await auth.call('/staff/advances', {
            method: 'POST',
            body: {
                employee_id: Number(employeeId),
                date: $('#advance-date', advanceForm).value || null,
                payments: split,
                notes: $('#advance-notes', advanceForm).value.trim() || null,
                client_ref: state.advances.clientRef,
            },
        });

        toast(payload.message ?? 'Advance paid.');

        // A fresh reference for the next advance: reusing this one would have
        // the next payment return this one's voucher.
        state.advances.clientRef = crypto.randomUUID();

        if (sections.advances.workspace?.hasList()) await loadAdvances();
        else sections.advances.workspace?.refresh();

        await ensureEmployees({ force: true });

        if (sections.people.workspace?.hasList()) renderPeople();

        // §2A.8 — the form stays put, cleared for the next one.
        resetAdvanceForm();
    } catch (error) {
        showFormErrors(advanceForm, error);
    } finally {
        setSubmitting(advanceForm, false);
    }
}

function resetAdvanceForm() {
    clearFormErrors(advanceForm);

    $('#advance-employee', advanceForm).value = '';
    $('#advance-date', advanceForm).value = today();
    $('#advance-notes', advanceForm).value = '';

    advancePayments?.reset();
    paintAdvanceHint();

    $('#advance-employee', advanceForm).focus();
}

async function loadAdvances() {
    const body = inList('advances', '#advances-body');
    body.innerHTML = tableMessage(ADVANCE_COLUMNS, 'Loading advances…');

    try {
        const query = state.advances.employeeId
            ? `?employee_id=${state.advances.employeeId}&per_page=100`
            : '?per_page=100';

        const payload = await auth.call(`/staff/advances${query}`);

        state.advances.rows = payload.data ?? [];
        state.advances.outstanding = payload.meta?.outstanding ?? {};

        renderAdvances();
    } catch (error) {
        body.innerHTML = tableMessage(ADVANCE_COLUMNS, error.message, 'error');
    }
}

function renderAdvances() {
    const body = inList('advances', '#advances-body');
    const rows = state.advances.rows;

    if (!rows.length) {
        body.innerHTML = tableMessage(ADVANCE_COLUMNS, 'No advances have been paid.');
        inList('advances', '#advances-summary').textContent = '';
        sections.advances.workspace?.refresh();
        paintTabCounts();

        return;
    }

    const mayReverse = can('UPDATE', 'STAFF');

    body.innerHTML = rows.map((row) => {
        const reversed = row.status === 'reversed';
        const outstanding = state.advances.outstanding[row.employee_id]?.outstanding;

        return `
            <tr class="${reversed ? 'opacity-60' : ''}">
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
    const outTotal = Object.values(state.advances.outstanding)
        .reduce((sum, position) => sum + Number(position.outstanding ?? 0), 0);

    inList('advances', '#advances-summary').textContent =
        `${live.length} advance${live.length === 1 ? '' : 's'} · `
        + `${formatMoney(outTotal.toFixed(2))} still to come back`;

    sections.advances.workspace?.refresh();
    paintTabCounts();
}

async function reverseAdvance(id) {
    const advance = state.advances.rows.find((row) => String(row.id) === String(id));
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

        await Promise.all([loadAdvances(), ensureEmployees({ force: true })]);

        if (sections.people.workspace?.hasList()) renderPeople();
    } catch (error) {
        toast(error.message, 'error');
    }
}

function exportAdvances() {
    const rows = [['Date', 'Voucher', 'Who', 'Amount', 'Status', 'Note']];

    state.advances.rows.forEach((row) => {
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

/* -------------------------------------------------------------------------
 | The Designation Master — level 2
 | ---------------------------------------------------------------------- */

function openDesignations() {
    renderDesignations();
    showModal('#designation-drawer');
    $('#designation-name').focus();
}

function renderDesignations() {
    const host = $('#designation-list');
    const rows = state.meta.designations;

    if (!rows.length) {
        host.innerHTML = '<p class="text-[0.8125rem] text-muted-foreground">'
            + 'Nothing here yet. Add the trades this workshop has — Fitter, Winder, Helper.</p>';

        return;
    }

    const mayUpdate = can('UPDATE', 'STAFF');
    const mayDelete = can('DELETE', 'STAFF');

    host.innerHTML = rows.map((row) => `
        <div class="flex items-center gap-2 border-b border-muted py-2.5 last:border-0
                    ${row.is_active ? '' : 'opacity-60'}">
            <div class="min-w-0 flex-1">
                <div class="truncate text-[0.875rem] font-medium text-foreground">
                    ${esc(row.name)}
                    ${row.is_active ? '' : '<span class="badge bg-muted text-secondary-foreground">Archived</span>'}
                </div>
                <div class="text-[0.75rem] text-muted-foreground">
                    ${row.employee_count === 0
                        ? 'Nobody holds it'
                        : `${row.employee_count} ${row.employee_count === 1 ? 'person holds' : 'people hold'} it`}
                </div>

                ${/*
                  | Whether a sale asks for this trade by name — M22.
                  |
                  | Here rather than on the sale form, because it is a decision
                  | about the workshop and not about one invoice, and here rather
                  | than in a settings screen, because this is already the list of
                  | what the trades are. Ticking Fitter and Winder is what puts
                  | two boxes on the invoice screen; nothing else does.
                  |
                  | Offered on active rows only. An archived designation appears
                  | on no form at all, so the checkbox would be a control that
                  | does nothing, and a ticked-but-archived row would read as
                  | though it were still being asked for.
                  */ ''}
                ${mayUpdate && row.is_active ? `
                    <label class="mt-1.5 flex items-center gap-1.5 text-[0.75rem] text-muted-foreground">
                        <input type="checkbox" class="h-3.5 w-3.5 rounded border-border"
                               data-track-designation="${row.id}" ${row.track_on_sales ? 'checked' : ''}>
                        Ask for this on a sale
                    </label>`
                    : row.track_on_sales
                        ? '<div class="mt-1.5 text-[0.75rem] text-muted-foreground">Asked for on a sale</div>'
                        : ''}
            </div>
            ${mayUpdate
                ? `<button type="button" class="btn btn-ghost btn-sm" data-archive-designation="${row.id}"
                           data-active="${row.is_active}">
                       ${row.is_active ? 'Archive' : 'Restore'}
                   </button>`
                : ''}
            ${mayDelete && row.employee_count === 0
                ? `<button type="button" class="btn btn-ghost btn-icon hover:!text-rose-600"
                           data-delete-designation="${row.id}" aria-label="Delete ${esc(row.name)}"
                           >${iconTrash}</button>`
                : ''}
        </div>`).join('');
}

async function reloadDesignations() {
    const { data } = await auth.call('/staff/designations');

    state.meta.designations = data ?? [];

    renderDesignations();
    paintDesignationOptions($('#employee-designation', employeeForm).value);
}

async function submitDesignation(event) {
    event.preventDefault();

    const form = $('#designation-form');
    const input = $('#designation-name');
    const name = input.value.trim();

    if (name.length < 2) {
        toast('Give the designation a name.', 'error');

        return;
    }

    setSubmitting(form, true, 'Adding…');

    try {
        await auth.call('/staff/designations', { method: 'POST', body: { name } });

        input.value = '';
        await reloadDesignations();
        toast('Designation added.');
        input.focus();
    } catch (error) {
        toast(error.message, 'error');
    } finally {
        setSubmitting(form, false);
    }
}

/**
 * Tick or untick "ask for this on a sale" — M22.
 *
 * Saved on the change rather than behind a Save, because it is one boolean and
 * the list it sits in has no other pending state. Failure puts the box back
 * where it was: a checkbox that stayed ticked after the request was refused
 * would say the sale form is asking for a trade it is not.
 */
async function trackDesignation(id, wanted) {
    try {
        await auth.call(`/staff/designations/${id}`, {
            method: 'PATCH',
            body: { track_on_sales: wanted },
        });

        await reloadDesignations();
        toast(wanted
            ? 'A sale will now ask who did this.'
            : 'A sale will no longer ask who did this.');
    } catch (error) {
        toast(error.message, 'error');
        renderDesignations();
    }
}

async function archiveDesignation(id, isActive) {
    try {
        await auth.call(`/staff/designations/${id}`, {
            method: 'PATCH',
            body: { is_active: !isActive },
        });

        await reloadDesignations();
        toast(isActive ? 'Designation archived.' : 'Designation restored.');
    } catch (error) {
        toast(error.message, 'error');
    }
}

async function destroyDesignation(id) {
    const designation = state.meta.designations.find((row) => String(row.id) === String(id));

    const confirmed = await confirmAction({
        title: `Delete "${designation?.name ?? 'this designation'}"?`,
        body: 'This only works while nobody holds it. One that is in use is archived instead, so everybody '
            + 'who has it keeps it.',
        confirmLabel: 'Delete',
    });

    if (!confirmed) return;

    try {
        await auth.call(`/staff/designations/${id}`, { method: 'DELETE' });

        await reloadDesignations();
        toast('Designation deleted.');
    } catch (error) {
        toast(error.message, 'error');
    }
}

/* -------------------------------------------------------------------------
 | Shared rendering helpers
 | ---------------------------------------------------------------------- */

function renderPager(host, matched, current, onPage) {
    const pages = Math.max(1, Math.ceil(matched / PAGE_SIZE));

    if (pages <= 1) {
        host.innerHTML = '';

        return;
    }

    host.innerHTML = Array.from({ length: pages }, (unused, index) => {
        const page = index + 1;
        const active = page === current;

        return `<button type="button" data-page="${page}"
                    class="size-7 rounded-[6px] text-xs font-medium transition
                           ${active ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted'}"
                    ${active ? 'aria-current="page"' : ''}>${page}</button>`;
    }).join('');

    host.onclick = (event) => {
        const button = event.target.closest('[data-page]');

        if (button) onPage(Number(button.dataset.page));
    };
}

function initials(name) {
    return (name || '?')
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((word) => word.charAt(0).toUpperCase())
        .join('');
}

const svg = (paths, size = 16) =>
    `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor"
          stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="shrink-0">${paths}</svg>`;

const iconPencil = svg('<path d="M21.17 6.83a2.83 2.83 0 0 0-4-4L3.5 16.5 2 22l5.5-1.5z"/><path d="m15 5 4 4"/>');
const iconTrash = svg('<path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>'
    + '<path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>');
const iconUndo = svg('<path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-15-6.7L3 13"/>');
const iconArrowUp = svg('<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>', 12);
const iconArrowDown = svg('<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>', 12);

/* -------------------------------------------------------------------------
 | The tab strip
 | ---------------------------------------------------------------------- */

function paintTabCounts() {
    const people = $('[data-count="people"]', root);
    const advances = $('[data-count="advances"]', root);

    const here = state.employees.filter((row) => row.is_active).length;

    if (people) people.textContent = state.employeesLoaded ? String(here) : '';

    const out = Object.values(state.advances.outstanding)
        .reduce((sum, position) => sum + Number(position.outstanding ?? 0), 0);

    if (advances) advances.textContent = out > 0 ? formatMoney(out.toFixed(2)) : '';
}

/**
 * Show a section, mounting it the first time it is asked for.
 *
 * The lazy mount is §2.5 applied inside a module: a workshop that only ever
 * marks attendance never pays for the payroll sheet, and opening the module
 * fetches the staff form's vocabulary and nothing else.
 */
async function openSection(key) {
    if (!sections[key]) return;

    activeSection = key;

    Object.entries(sections).forEach(([name, section]) => {
        section.root.hidden = name !== key;
    });

    $$('[data-staff-tab]', root).forEach((tab) => {
        tab.setAttribute('aria-selected', String(tab.dataset.staffTab === key));
    });

    if (!sections[key].opened) {
        sections[key].opened = true;

        try {
            await sections[key].mount();
        } catch (error) {
            sections[key].opened = false;
            toast(error.message ?? 'That section could not be opened.', 'error');
        }
    }
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

export default async function initStaff() {
    root = $('[data-staff-section="people"]').closest('[data-module-root]');

    employeeForm = $('#employee-form', root);
    employeeFormSlot = $('[data-employee-form-slot]', root);
    employeeModalSlot = $('[data-employee-modal-slot]', root);

    ['people', 'attendance', 'payroll', 'advances'].forEach((key) => {
        const sectionRoot = $(`[data-staff-section="${key}"]`, root);

        sections[key] = {
            root: sectionRoot,
            form: $('[data-ws-form]', sectionRoot),
            list: $('[data-ws-list]', sectionRoot),
            workspace: null,
            opened: false,
            mount: async () => {},
        };
    });

    advanceForm = $('#advance-form', sections.advances.form);

    // A reference per document, generated when the operator starts writing it —
    // not per request. A value regenerated on retry would make the second
    // attempt look like a different run, which is exactly what it must not.
    state.payroll.clientRef = crypto.randomUUID();
    state.advances.clientRef = crypto.randomUUID();

    // Fetched before anything mounts: the section the module lands on is a form
    // that needs all of it.
    await loadMeta();

    initPeopleSection();
    initAttendanceSection();
    initPayrollSection();
    initAdvancesSection();
    initDesignationDrawer();
    initTabs();

    /*
    | §2A.9, one press at a time — but the shell asks for `staff`, and each
    | section's workspace registered under a key of its own. Without this the
    | last-mounted section would answer for all four, and a press on the payroll
    | list would swap the attendance sheet.
    |
    | Returning false lets the shell take the next step out to the grid.
    */
    registerEscape('staff', () => {
        const section = sections[activeSection];

        if (!section?.workspace || section.workspace.mode() !== 'list') return false;
        if (!can('WRITE', 'STAFF') && activeSection !== 'attendance') return false;

        section.workspace.showForm();

        return true;
    });

    /*
    | The section a deep link asked for, or Staff.
    |
    | `?tab=` is spent once it has been acted on: surviving a refresh or a Back
    | would reopen a section somebody has just navigated away from, which is
    | worse than not offering the link at all.
    */
    const requested = requestedTab();

    await openSection(requested ?? 'people');

    if (requested) clearModuleParams();
}

/* --- Staff --------------------------------------------------------------- */

function initPeopleSection() {
    const section = sections.people;

    inList('people', '#staff-search').addEventListener('input', debounce((event) => {
        state.people.search = event.target.value.trim();
        state.people.page = 1;
        renderPeople();
    }, 200));

    inList('people', '#staff-filter-designation').addEventListener('change', (event) => {
        state.people.designationId = event.target.value;
        state.people.page = 1;
        renderPeople();
    });

    inList('people', '#staff-pills').addEventListener('click', (event) => {
        const pill = event.target.closest('[data-pill]');

        if (pill) setPeoplePill(pill.dataset.pill);
    });

    allInList('people', '[data-staff-filter]').forEach((tile) =>
        tile.addEventListener('click', () => setPeoplePill(tile.dataset.staffFilter)));

    inList('people', '#staff-clear-filters').addEventListener('click', () => {
        state.people.search = '';
        state.people.designationId = '';
        state.people.pill = 'all';
        state.people.page = 1;

        inList('people', '#staff-search').value = '';
        inList('people', '#staff-filter-designation').value = '';

        renderPeople();
    });

    inList('people', '#staff-head').addEventListener('click', (event) => {
        const th = event.target.closest('[data-sort]');
        if (!th) return;

        const column = th.dataset.sort;

        if (state.people.sort.column === column) {
            state.people.sort.direction = state.people.sort.direction === 'asc' ? 'desc' : 'asc';
        } else {
            state.people.sort = { column, direction: 'asc' };
        }

        state.people.page = 1;
        renderPeople();
    });

    inList('people', '#staff-export').addEventListener('click', exportPeople);
    inList('people', '#staff-manage-designations').addEventListener('click', openDesignations);

    inList('people', '#staff-body').addEventListener('click', (event) => {
        const edit = event.target.closest('[data-edit]');

        if (edit) {
            event.stopPropagation();

            const employee = state.employees.find((row) => String(row.id) === edit.dataset.edit);

            if (employee) openEmployeeForm(employee);

            return;
        }

        const remove = event.target.closest('[data-delete]');

        if (remove) {
            event.stopPropagation();
            destroyEmployee(remove.dataset.delete);

            return;
        }

        const row = event.target.closest('[data-row]');

        if (row) openEmployeeDrawer(row.dataset.row);
    });

    // A row behaves like the link it looks like.
    inList('people', '#staff-body').addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;

        const row = event.target.closest('[data-row]');

        if (row) {
            event.preventDefault();
            openEmployeeDrawer(row.dataset.row);
        }
    });

    /* The drawer. */
    $('#employee-drawer-edit', root).addEventListener('click', () => {
        if (!state.openEmployee) return;

        hideModal('#employee-drawer');
        openEmployeeForm(state.openEmployee);
    });

    $('#employee-drawer-delete', root).addEventListener('click', () => {
        if (state.openEmployee) destroyEmployee(state.openEmployee.id);
    });

    $('#employee-drawer-advance-action', root).addEventListener('click', async () => {
        const employee = state.openEmployee;
        if (!employee) return;

        hideModal('#employee-drawer');
        await openSection('advances');

        sections.advances.workspace?.showForm();
        $('#advance-employee', advanceForm).value = String(employee.id);
        paintAdvanceHint();
    });

    /* The form. */
    employeeForm.addEventListener('submit', submitEmployee);
    $('[data-employee-clear]', employeeForm).addEventListener('click', () => openEmployeeForm());
    $('#employee-basis', employeeForm).addEventListener('change', paintRateLabel);
    $('[data-employee-add-designation]', employeeForm).addEventListener('click', openDesignations);

    const mayWrite = can('WRITE', 'STAFF');

    // Filled in before the workspace mounts, because mounting is what shows it:
    // the section lands on this form (§2A.1).
    if (mayWrite) openEmployeeForm();

    section.mount = async () => {
        section.workspace = mountWorkspace(section.root, {
            // A key of its own, never `staff` — see registerEscape() above.
            key: 'staff:people',
            title: 'Staff',
            formSubtitle: 'Add somebody to the workshop, or show who is already on it.',
            listSubtitle: (count) => (count === null
                ? 'Who works here, and how they are paid.'
                : `${count} on the staff. Click a row to open one.`),
            createLabel: 'Add staff',
            count: () => (state.employees.length ? state.employees.length : null),
            canCreate: mayWrite,
            onShowList: async () => {
                await ensureEmployees();
                renderPeople();
            },
            /*
            | Bring the form home.
            |
            | It may have been left in the edit drawer — closed with Cancel, with
            | Escape, or by a save — and level 1 is where a *create* lives. A form
            | still holding somebody's id is that person's edit form, so it is
            | reopened blank; one holding nothing is re-attached exactly as it was
            | typed. A half-written new employee survives a look at the list
            | (§2A.6), somebody else's record does not.
            */
            onShowForm: () => {
                if (employeeForm.elements.id.value) {
                    openEmployeeForm();

                    return;
                }

                adoptForm(employeeForm, employeeFormSlot, { chrome: 'inline' });
                $('#employee-name', employeeForm).focus();
            },
        });
    };
}

/* --- Attendance ---------------------------------------------------------- */

function initAttendanceSection() {
    const section = sections.attendance;

    inForm('attendance', '#attendance-date').addEventListener('change', (event) => {
        state.attendance.date = event.target.value || today();
        loadDaySheet();
    });

    inForm('attendance', '#attendance-today').addEventListener('click', () => {
        state.attendance.date = today();
        loadDaySheet();
    });

    inForm('attendance', '#attendance-prev').addEventListener('click', () => {
        state.attendance.date = shiftDay(state.attendance.date, -1);
        loadDaySheet();
    });

    inForm('attendance', '#attendance-next').addEventListener('click', () => {
        state.attendance.date = shiftDay(state.attendance.date, 1);
        loadDaySheet();
    });

    inForm('attendance', '#attendance-all-present').addEventListener('click', () => {
        markAll(state.meta.statuses[0]?.value ?? 'present');
    });

    inForm('attendance', '#attendance-all-clear').addEventListener('click', () => markAll(null));

    inForm('attendance', '#attendance-body').addEventListener('click', (event) => {
        const chip = event.target.closest('[data-mark]');
        if (!chip) return;

        const row = state.attendance.rows.find(
            (candidate) => String(candidate.employee.id) === chip.dataset.mark,
        );

        if (!row) return;

        row.status = chip.dataset.status || null;

        // Repaint the one row's chips rather than the table: rebuilding the
        // whole sheet would lose the note somebody is halfway through typing two
        // rows down.
        const host = chip.closest('tr');

        $$('[data-mark]', host).forEach((sibling) => {
            sibling.setAttribute('aria-pressed', String((sibling.dataset.status || null) === row.status));
        });

        renderDaySummary();
    });

    inForm('attendance', '#attendance-body').addEventListener('input', (event) => {
        const note = event.target.closest('[data-note]');
        if (!note) return;

        const row = state.attendance.rows.find(
            (candidate) => String(candidate.employee.id) === note.dataset.note,
        );

        if (row) row.notes = note.value;
    });

    inForm('attendance', '#attendance-save').addEventListener('click', saveDaySheet);

    inList('attendance', '#register-month').addEventListener('change', (event) => {
        state.attendance.registerMonth = event.target.value || thisMonth();
        loadRegister();
    });

    inList('attendance', '#register-export').addEventListener('click', exportRegister);

    section.mount = async () => {
        inForm('attendance', '#attendance-date').value = state.attendance.date;
        inList('attendance', '#register-month').value = state.attendance.registerMonth;

        section.workspace = mountWorkspace(section.root, {
            key: 'staff:attendance',
            title: 'Attendance',
            formSubtitle: 'Mark who was in. Only what is different from normal needs a mark.',
            listSubtitle: () => 'The month at a glance, one row per person.',
            createLabel: 'Mark a day',
            // Always the day sheet first — marking today is what somebody opens
            // this for, and the register is the thing they check afterwards.
            canCreate: true,
            onShowList: loadRegister,
            onShowForm: () => {},
        });

        await loadDaySheet();
    };
}

/* --- Payroll ------------------------------------------------------------- */

function initPayrollSection() {
    const section = sections.payroll;

    inForm('payroll', '#payroll-month').addEventListener('change', (event) => {
        state.payroll.month = event.target.value || thisMonth();
        // The recovery decisions belonged to the month somebody was looking at,
        // not to this one.
        state.payroll.recoveries = {};
        loadSheet();
    });

    inForm('payroll', '#payroll-recompute').addEventListener('click', loadSheet);

    inForm('payroll', '#payroll-body').addEventListener('input', (event) => {
        const input = event.target.closest('[data-recover]');
        if (!input) return;

        const id = input.dataset.recover;
        const row = state.payroll.sheet?.rows.find((candidate) => String(candidate.employee.id) === id);

        if (!row) return;

        /*
        | Capped here as well as on the server, and both caps matter.
        |
        | This one keeps the net column honest while somebody is typing; the
        | server's is what actually posts. They are the same two ceilings — what
        | is outstanding, and what was earned — because a payslip cannot end with
        | the employee owing the workshop money.
        */
        const ceiling = Math.min(Number(row.advance_outstanding), Number(row.gross));
        const typed = Number(input.value);

        state.payroll.recoveries[id] = Number.isFinite(typed) && typed > 0
            ? Math.min(typed, ceiling).toFixed(2)
            : '0.00';

        // The row's net and the totals, without rebuilding the table under the
        // cursor.
        const net = (Number(row.gross) - Number(state.payroll.recoveries[id])).toFixed(2);
        const cells = input.closest('tr').cells;

        cells[cells.length - 1].textContent = formatMoney(net);

        renderSheetTotals();
        renderSheetHint();
        payrollPayments?.repaint();
    });

    inForm('payroll', '#payroll-post').addEventListener('click', postPayroll);

    inList('payroll', '#payroll-runs-body').addEventListener('click', (event) => {
        const row = event.target.closest('[data-run]');

        if (row) openRunDrawer(row.dataset.run);
    });

    inList('payroll', '#payroll-runs-body').addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;

        const row = event.target.closest('[data-run]');

        if (row) {
            event.preventDefault();
            openRunDrawer(row.dataset.run);
        }
    });

    $('#payroll-drawer-reverse', root).addEventListener('click', reverseRun);
    $('#payroll-drawer-export', root).addEventListener('click', exportRun);

    section.mount = async () => {
        inForm('payroll', '#payroll-month').value = state.payroll.month;

        // The shared component every other settlement in the application uses
        // (§5.2). The net is the document here: recovery has already come off it.
        payrollPayments = mountPaymentRows(inForm('payroll', '#payroll-payments-host'), {
            modes: state.meta.modes,
            outstanding: () => sheetTotals().net,
            heading: 'Paid by',
            noun: 'employee',
            verb: 'paid',
        });

        section.workspace = mountWorkspace(section.root, {
            key: 'staff:payroll',
            title: 'Payroll',
            formSubtitle: 'Computed from the attendance register as it stands right now.',
            listSubtitle: (count) => (count === null
                ? 'The months already paid.'
                : `${count} run${count === 1 ? '' : 's'}. Click one to see who was paid what.`),
            createLabel: 'Run a month',
            count: () => (state.payroll.runs.length ? state.payroll.runs.length : null),
            canCreate: can('WRITE', 'STAFF'),
            onShowList: loadRuns,
            onShowForm: () => {},
        });

        await loadSheet();
    };
}

/* --- Advances ------------------------------------------------------------ */

function initAdvancesSection() {
    const section = sections.advances;

    advanceForm.addEventListener('submit', submitAdvance);
    $('[data-advance-clear]', advanceForm).addEventListener('click', resetAdvanceForm);
    $('#advance-employee', advanceForm).addEventListener('change', paintAdvanceHint);

    inList('advances', '#advances-filter-employee').addEventListener('change', (event) => {
        state.advances.employeeId = event.target.value;
        loadAdvances();
    });

    inList('advances', '#advances-export').addEventListener('click', exportAdvances);

    inList('advances', '#advances-body').addEventListener('click', (event) => {
        const button = event.target.closest('[data-reverse-advance]');

        if (button) reverseAdvance(button.dataset.reverseAdvance);
    });

    section.mount = async () => {
        $('#advance-date', advanceForm).value = today();

        advancePayments = mountPaymentRows($('#advance-payments-host', advanceForm), {
            modes: state.meta.modes,
            heading: 'Handed over',
            verb: 'paid',
            // The split *is* the amount — there is no document to settle against,
            // so the state chips have nothing to mean. See payment-rows.js.
            settlesADocument: false,
        });

        section.workspace = mountWorkspace(section.root, {
            key: 'staff:advances',
            title: 'Advances',
            formSubtitle: 'Money against a salary not yet earned. It comes back off the next payroll.',
            listSubtitle: (count) => (count === null
                ? 'What has been handed out, and what is still to come back.'
                : `${count} advance${count === 1 ? '' : 's'}.`),
            createLabel: 'Pay an advance',
            count: () => (state.advances.rows.length ? state.advances.rows.length : null),
            canCreate: can('WRITE', 'STAFF'),
            onShowList: loadAdvances,
            onShowForm: () => {},
        });

        // The picker needs the staff list, and the list may not have been
        // fetched — somebody can open Advances without ever opening Staff.
        await ensureEmployees();
        paintEmployeePickers();
    };
}

/* --- The designation drawer, shared by both places that open it ---------- */

function initDesignationDrawer() {
    $('#designation-form', root).addEventListener('submit', submitDesignation);

    // Who a sale asks about — M22. `change` rather than `click`, so the
    // checkbox's own state is settled before it is read.
    $('#designation-list', root).addEventListener('change', (event) => {
        const track = event.target.closest('[data-track-designation]');

        if (track) trackDesignation(track.dataset.trackDesignation, track.checked);
    });

    $('#designation-list', root).addEventListener('click', (event) => {
        const archive = event.target.closest('[data-archive-designation]');

        if (archive) {
            archiveDesignation(archive.dataset.archiveDesignation, archive.dataset.active === 'true');

            return;
        }

        const remove = event.target.closest('[data-delete-designation]');

        if (remove) destroyDesignation(remove.dataset.deleteDesignation);
    });
}

/* --- The tab strip ------------------------------------------------------- */

function initTabs() {
    $('[data-staff-tabs]', root).addEventListener('click', (event) => {
        const tab = event.target.closest('[data-staff-tab]');

        if (tab) openSection(tab.dataset.staffTab);
    });

    /*
    | A deep link — `/dashboard#staff?tab=attendance`.
    |
    | Read on mount and again on `module:params`, because a module that is already
    | up cannot read its intent from `default()`: that ran on the first open and
    | will not run again.
    */
    root.addEventListener('module:params', (event) => {
        const tab = event.detail?.get('tab');

        if (tab && sections[tab]) {
            openSection(tab);
            clearModuleParams();
        }
    });
}

/**
 * The tab a deep link asked for, on the very first open.
 *
 * `module:params` covers every open *after* this one; it cannot cover this one,
 * because the shell dispatches it only for a module that is already mounted.
 * Returns null for an ordinary card click, which lands on Staff.
 */
function requestedTab() {
    const tab = moduleParams().get('tab');

    return tab && sections[tab] ? tab : null;
}
