import auth from '../auth-client';
import { can } from '../permissions';
import {
    $, $$, clearFormErrors, confirmAction, debounce, esc, formatDate, formatRelative,
    hideModal, setSubmitting, showFormErrors, showModal, tableMessage, toast,
} from '../ui';
import { adoptForm, mountWorkspace } from '../workspace';

/**
 * Users — who may sign in to this workshop, and as what.
 *
 * ## The §2A flow
 *
 * The module opens on its create form; the directory sits behind one switch
 * control beside the heading and is fetched the first time it is asked for
 * (§2A.1, §2A.7). The form is not written twice: `#user-form` starts inside the
 * edit dialog and is *moved* into the level-1 slot on mount, so a create and an
 * edit share one set of fields, one set of ids and one submit handler.
 *
 * ## The list is loaded whole and filtered here
 *
 * Same reason as the catalogue and the counterparty screens: the four figures
 * above the table have to agree with the rows under them, and counting "3 who
 * cannot sign in" server-side while filtering a page client-side is how a tile
 * comes to disagree with its own list. A workshop's staff is a handful of
 * people, so this is one request in practice; `MAX_PAGES` is a guard against a
 * platform-wide directory, not a page size, and hitting it is said out loud in
 * the summary rather than quietly truncating.
 *
 * ## Status is a gate, not a label
 *
 * `UserStatus::canAuthenticate()` is true for `active` and nothing else, so the
 * list groups the other three as "cannot sign in" and lets the row's badge say
 * which of them it is. The words for each come off the form's status select,
 * which Blade renders from the enum — a second copy of that vocabulary here
 * would be a place for "Pending activation" to drift.
 */

const COLUMNS = 5;
const PAGE_SIZE = 25;

// The users endpoint caps per_page at 100.
const FETCH_SIZE = 100;
const MAX_PAGES = 10;

const STATUS_TONES = {
    active: 'bg-emerald-50 text-emerald-700',
    inactive: 'bg-muted text-secondary-foreground',
    suspended: 'bg-rose-50 text-rose-700',
    pending: 'bg-amber-50 text-amber-700',
};

const state = {
    users: [],
    roles: [],
    truncated: false,

    search: '',
    roleId: '',
    pill: 'all',
    sort: { column: 'name', direction: 'asc' },
    page: 1,

    openUser: null,
};

/*
| Held at mount, while everything is still in the document.
|
| §2A.2 keeps exactly one of the form and the list attached, so a
| `document.querySelector` into the other finds nothing — which is precisely
| when a save wants to bring the list up to date. Querying a *node* works while
| it is detached, so every lookup below is scoped to whichever of these it
| belongs to.
*/
let listRoot = null;
let userForm = null;
let formSlot = null;
let modalSlot = null;
let workspace = null;

const inList = (selector) => $(selector, listRoot);
const allInList = (selector) => $$(selector, listRoot);

/* -------------------------------------------------------------------------
 | Data
 | ---------------------------------------------------------------------- */

/**
 * The role catalogue, for the form's picker and the list's filter.
 *
 * A caller holding READ:USERS without READ:ROLES simply gets neither rather
 * than a broken page: they can still read the directory, and the endpoint
 * refuses the roles request regardless (§6.1).
 */
async function loadRoles() {
    if (!can('READ', 'ROLES')) return;

    try {
        const { data } = await auth.call(`/roles?per_page=${FETCH_SIZE}`);

        state.roles = data ?? [];
    } catch {
        // Non-fatal. The picker keeps "No role" and the filter keeps "Any role".
        state.roles = [];
    }

    paintRoleOptions();
}

function paintRoleOptions() {
    const filter = inList('#filter-role');

    if (filter) {
        filter.innerHTML = ['<option value="">Any role</option>', '<option value="none">No role</option>']
            .concat(state.roles.map((role) => `<option value="${role.id}">${esc(role.name)}</option>`))
            .join('');

        filter.value = state.roleId;
    }

    paintRoleSelect($('#user-role', userForm), state.openUser?.role?.id ?? '');
}

/**
 * The form's role picker.
 *
 * A role the user already holds stays offered even if it has since been
 * removed from the catalogue — otherwise saving an unrelated change to their
 * email would silently strip the role, because the select had nothing selected
 * to send back.
 */
function paintRoleSelect(select, selectedId) {
    if (!select) return;

    const selected = String(selectedId ?? '');
    const known = state.roles.some((role) => String(role.id) === selected);

    const options = ['<option value="">No role</option>']
        .concat(state.roles.map((role) =>
            `<option value="${role.id}">${esc(role.name)}</option>`));

    if (selected !== '' && !known) {
        options.push(`<option value="${esc(selected)}">${esc(state.openUser?.role?.name ?? 'Current role')}</option>`);
    }

    select.innerHTML = options.join('');
    select.value = selected;
}

async function fetchUsers() {
    const users = [];
    let page = 1;
    let more = true;

    while (more && page <= MAX_PAGES) {
        // eslint-disable-next-line no-await-in-loop -- pages are sequential by definition.
        const payload = await auth.call(`/users?per_page=${FETCH_SIZE}&page=${page}&sort=name&direction=asc`);

        users.push(...(payload.data ?? []));

        more = Boolean(payload.meta?.pagination?.has_more);
        page += 1;
    }

    state.truncated = more;
    state.users = users;
}

/** The first Show, and every retry of it. Nothing here is allowed to throw. */
async function loadList() {
    inList('#users-body').innerHTML = tableMessage(COLUMNS, 'Loading users…');

    try {
        await fetchUsers();
        render();
    } catch (error) {
        inList('#users-body').innerHTML = tableMessage(COLUMNS, error.message, 'error');
        inList('#users-summary').textContent = '';
    }
}

/** Refetch, keeping the reader where they were. */
async function refresh({ keepPage = false } = {}) {
    const page = state.page;

    if (!keepPage) state.page = 1;

    try {
        await fetchUsers();

        if (keepPage) state.page = page;

        render();
    } catch (error) {
        toast(error.message, 'error');
    }
}

/* -------------------------------------------------------------------------
 | Filtering, sorting, paging — all client-side, over the whole list
 | ---------------------------------------------------------------------- */

/** Everything but the pill, so the tiles count what the pill chooses between. */
function scoped() {
    const search = state.search.toLowerCase();

    return state.users.filter((user) => {
        if (search && !`${user.name} ${user.email}`.toLowerCase().includes(search)) return false;

        if (state.roleId === 'none' && user.role) return false;
        if (state.roleId !== '' && state.roleId !== 'none' && String(user.role?.id ?? '') !== state.roleId) {
            return false;
        }

        return true;
    });
}

function matchesPill(user) {
    if (state.pill === 'active') return user.status === 'active';
    if (state.pill === 'blocked') return user.status !== 'active';
    if (state.pill === 'never') return !user.last_login_at;

    return true;
}

function sortValue(user, column) {
    if (column === 'role') return (user.role?.name ?? '').toLowerCase();
    if (column === 'status') return user.status ?? '';
    if (column === 'last_login') return user.last_login_at ? Date.parse(user.last_login_at) : 0;

    return (user.name ?? '').toLowerCase();
}

function sorted(users) {
    const { column, direction } = state.sort;
    const sign = direction === 'asc' ? 1 : -1;

    return [...users].sort((a, b) => {
        const left = sortValue(a, column);
        const right = sortValue(b, column);

        if (left === right) return a.name.localeCompare(b.name);

        return left > right ? sign : -sign;
    });
}

function statusLabel(status) {
    const option = $(`#user-status option[value="${status}"]`, userForm);

    return option?.textContent.trim() || status || '—';
}

/* -------------------------------------------------------------------------
 | Rendering
 | ---------------------------------------------------------------------- */

function render() {
    const scope = scoped();
    const matched = sorted(scope.filter(matchesPill));

    // Clamped before the rows are painted, not after: a filter that shortens the
    // list while the reader is on page 3 must not paint an empty table once and
    // the right one on the next render.
    state.page = Math.min(state.page, Math.max(1, Math.ceil(matched.length / PAGE_SIZE)));

    renderTiles(scope);
    renderPills();
    renderRows(matched);
    renderSummary(matched.length);
    renderPager(matched.length);
    renderSortIndicators();

    workspace?.refresh();
}

function renderTiles(scope) {
    inList('#stat-total').textContent = scope.length.toLocaleString('en-IN');
    inList('#stat-active').textContent = scope
        .filter((user) => user.status === 'active').length.toLocaleString('en-IN');
    inList('#stat-blocked').textContent = scope
        .filter((user) => user.status !== 'active').length.toLocaleString('en-IN');
    inList('#stat-never').textContent = scope
        .filter((user) => !user.last_login_at).length.toLocaleString('en-IN');

    allInList('[data-stat-filter]').forEach((tile) => {
        const on = state.pill === tile.dataset.statFilter;

        tile.classList.toggle('stat-tile-on', on);
        tile.querySelector('[data-stat-chevron]')?.classList.toggle('text-primary', on);
        tile.querySelector('[data-stat-chevron]')?.classList.toggle('text-border', !on);
    });
}

function renderPills() {
    allInList('[data-pill]').forEach((pill) =>
        pill.setAttribute('aria-pressed', String(pill.dataset.pill === state.pill)));

    const filtering = state.pill !== 'all' || state.search !== '' || state.roleId !== '';

    inList('#clear-filters').classList.toggle('hidden', !filtering);
    inList('#clear-filters').classList.toggle('flex', filtering);
}

function renderRows(users) {
    const body = inList('#users-body');

    if (!users.length) {
        body.innerHTML = tableMessage(
            COLUMNS,
            state.users.length ? 'No users match these filters.' : 'Nobody has been added yet.',
        );

        return;
    }

    const mayUpdate = can('UPDATE', 'USERS');
    const mayDelete = can('DELETE', 'USERS');

    const start = (state.page - 1) * PAGE_SIZE;

    body.innerHTML = users.slice(start, start + PAGE_SIZE).map((user) => {
        const tone = STATUS_TONES[user.status] ?? STATUS_TONES.inactive;
        const flash = workspace?.isNew(user.id) ? ' row-new' : '';

        const actions = [
            mayUpdate
                ? `<button type="button" class="btn btn-ghost btn-icon" data-edit="${user.id}"
                           title="Edit ${esc(user.name)}" aria-label="Edit ${esc(user.name)}">${iconPencil}</button>`
                : '',
            mayDelete
                ? `<button type="button" class="btn btn-ghost btn-icon hover:!text-rose-600" data-delete="${user.id}"
                           title="Delete ${esc(user.name)}" aria-label="Delete ${esc(user.name)}">${iconTrash}</button>`
                : '',
        ].join('');

        return `
            <tr class="cursor-pointer transition hover:bg-secondary/60${flash}" data-row="${user.id}"
                tabindex="0" role="button" aria-label="Open ${esc(user.name)}">
                <td class="px-4 py-3">
                    <div class="flex items-center gap-3">
                        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-accent text-sm
                                     font-semibold text-accent-foreground">${esc(initials(user.name))}</span>
                        <div class="min-w-0">
                            <div class="truncate text-[0.875rem] font-semibold text-foreground">${esc(user.name)}</div>
                            <div class="truncate text-[0.78125rem] text-muted-foreground">${esc(user.email)}</div>
                        </div>
                    </div>
                </td>
                <td class="px-4 py-3">
                    ${user.role
                        ? `<span class="badge bg-accent text-accent-foreground">${esc(user.role.name)}</span>`
                        : '<span class="text-[0.78125rem] text-muted-foreground">No role</span>'}
                </td>
                <td class="px-4 py-3"><span class="badge ${tone}">${esc(statusLabel(user.status))}</span></td>
                <td class="px-4 py-3 text-[0.8125rem] whitespace-nowrap text-muted-foreground">
                    ${user.last_login_at ? esc(formatRelative(user.last_login_at)) : 'Never'}
                </td>
                <td class="px-4 py-3">
                    <div class="flex justify-end gap-1">${actions
                        || '<span class="text-xs text-muted-foreground">—</span>'}</div>
                </td>
            </tr>`;
    }).join('');
}

function renderSummary(matched) {
    const total = state.users.length;

    const parts = [`Showing ${matched.toLocaleString('en-IN')} of ${total.toLocaleString('en-IN')} users`];

    if (matched !== total) parts.push('· Filtered');
    if (state.truncated) parts.push(`· first ${total.toLocaleString('en-IN')} loaded`);

    inList('#users-summary').textContent = parts.join(' ');
}

function renderPager(matched) {
    const host = inList('#users-pager');
    const pages = Math.max(1, Math.ceil(matched / PAGE_SIZE));

    if (pages <= 1) {
        host.innerHTML = '';

        return;
    }

    host.innerHTML = Array.from({ length: pages }, (unused, index) => {
        const page = index + 1;
        const active = page === state.page;

        return `<button type="button" data-page="${page}"
                    class="size-7 rounded-[6px] text-xs font-medium transition
                           ${active ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted'}"
                    ${active ? 'aria-current="page"' : ''}>${page}</button>`;
    }).join('');
}

function renderSortIndicators() {
    allInList('#users-head [data-sort]').forEach((th) => {
        const on = th.dataset.sort === state.sort.column;

        th.setAttribute('aria-sort', on
            ? (state.sort.direction === 'asc' ? 'ascending' : 'descending')
            : 'none');

        th.querySelector('[data-sort-arrow]')?.remove();

        const arrow = document.createElement('span');
        arrow.dataset.sortArrow = '';
        arrow.className = `ml-1 inline-block align-middle ${on ? 'text-primary' : 'text-border'}`;
        arrow.innerHTML = on && state.sort.direction === 'desc' ? iconArrowDown : iconArrowUp;

        th.append(arrow);
    });
}

function initials(name) {
    return (name || '?')
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((word) => word.charAt(0).toUpperCase())
        .join('');
}

function applySort(column) {
    if (state.sort.column === column) {
        state.sort.direction = state.sort.direction === 'asc' ? 'desc' : 'asc';
    } else {
        state.sort = { column, direction: 'asc' };
    }

    state.page = 1;
    render();
}

function setPill(pill) {
    state.pill = state.pill === pill ? 'all' : pill;
    state.page = 1;
    render();
}

function clearFilters() {
    state.search = '';
    state.roleId = '';
    state.pill = 'all';
    state.page = 1;

    inList('#filter-search').value = '';

    const filter = inList('#filter-role');
    if (filter) filter.value = '';

    render();
}

/* -------------------------------------------------------------------------
 | Icons
 | ---------------------------------------------------------------------- */

const svg = (paths, size = 16) =>
    `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor"
          stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="shrink-0">${paths}</svg>`;

const iconPencil = svg('<path d="M21.17 6.83a2.83 2.83 0 0 0-4-4L3.5 16.5 2 22l5.5-1.5z"/><path d="m15 5 4 4"/>');
const iconTrash = svg('<path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>'
    + '<path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>');
const iconArrowUp = svg('<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>', 12);
const iconArrowDown = svg('<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>', 12);

/* -------------------------------------------------------------------------
 | The drawer — one user, level 2
 | ---------------------------------------------------------------------- */

async function openDrawer(id) {
    const user = state.users.find((row) => String(row.id) === String(id));
    if (!user) return;

    state.openUser = user;

    $('#user-drawer-initials').textContent = initials(user.name);
    $('#user-drawer-title').textContent = user.name;
    $('#user-drawer-email').textContent = user.email;
    $('#user-drawer-status').innerHTML =
        `<span class="badge ${STATUS_TONES[user.status] ?? STATUS_TONES.inactive}">${esc(statusLabel(user.status))}</span>`;
    $('#user-drawer-role').textContent = user.role?.name ?? 'No role';
    $('#user-drawer-seen').textContent = user.last_login_at ? formatRelative(user.last_login_at) : 'Never';

    $('#user-drawer-edit').classList.toggle('hidden', !can('UPDATE', 'USERS'));
    $('#user-drawer-delete').classList.toggle('hidden', !can('DELETE', 'USERS'));

    $('#user-drawer-body').innerHTML = drawerDetails(user)
        + '<div class="mt-5"><span class="skel w-1/2"></span></div>';

    showModal('#user-drawer');

    /*
    | The grants, resolved server-side from the role.
    |
    | Fetched rather than derived: what somebody may actually do is the
    | AuthorizationService's answer, and a client-side join of role→permissions
    | would be a second implementation of the thing every endpoint is guarded by
    | (§4.4). The list request does not carry it — resolving a permission set per
    | row would be one query per user.
    */
    try {
        const { data } = await auth.call(`/users/${user.id}`);

        // The reader may have moved on while this was in flight.
        if (state.openUser?.id !== user.id) return;

        $('#user-drawer-body').innerHTML = drawerDetails(data) + drawerGrants(data.permissions ?? []);
    } catch (error) {
        if (state.openUser?.id !== user.id) return;

        $('#user-drawer-body').innerHTML = drawerDetails(user)
            + `<p class="mt-5 text-[0.8125rem] text-rose-600">${esc(error.message)}</p>`;
    }
}

function drawerDetails(user) {
    return `
        <dl class="dl">
            <dt>Status</dt>
            <dd>${esc(statusLabel(user.status))}${user.status === 'active'
                ? ''
                : ' <span class="text-[0.78125rem] font-normal text-muted-foreground">— cannot sign in</span>'}</dd>

            <dt>Role</dt>
            <dd>${user.role ? esc(user.role.name) : '<span class="text-muted-foreground">No role</span>'}</dd>

            <dt>Email verified</dt>
            <dd>${user.email_verified_at ? esc(formatDate(user.email_verified_at)) : 'Not verified'}</dd>

            <dt>Last sign-in</dt>
            <dd>${user.last_login_at ? esc(formatDate(user.last_login_at)) : 'Never'}</dd>

            <dt>Added</dt>
            <dd>${esc(formatDate(user.created_at))}</dd>
        </dl>`;
}

/**
 * What this person may actually do, grouped by resource.
 *
 * A role's name answers "what are they here for"; only this answers "may they
 * void a bill". The wildcard is called out rather than listed as one more line,
 * because "*:*" reads as a footnote and means everything.
 */
function drawerGrants(grants) {
    const heading = '<h4 class="mt-6 mb-2 text-[0.6875rem] font-semibold uppercase tracking-wider '
        + 'text-muted-foreground">Effective permissions</h4>';

    if (!grants.length) {
        return `${heading}<p class="text-[0.8125rem] text-muted-foreground">
            No grants at all. They can sign in and do nothing.</p>`;
    }

    if (grants.includes('*:*')) {
        return `${heading}<p class="hint"><span>Every action on every resource, including ones that do not
            exist yet.</span></p>`;
    }

    const byResource = new Map();

    grants.forEach((grant) => {
        const [action, resource] = grant.split(':');

        if (!byResource.has(resource)) byResource.set(resource, []);
        byResource.get(resource).push(action);
    });

    const groups = [...byResource.entries()]
        .sort(([left], [right]) => left.localeCompare(right))
        .map(([resource, actions]) => `
            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1 border-b border-muted py-2 last:border-0">
                <span class="w-40 shrink-0 text-[0.8125rem] text-muted-foreground">${esc(resource)}</span>
                <span class="flex flex-wrap gap-1">
                    ${actions.sort().map((action) =>
                        `<span class="badge bg-secondary text-secondary-foreground">${esc(action)}</span>`).join('')}
                </span>
            </div>`)
        .join('');

    return heading + groups;
}

/* -------------------------------------------------------------------------
 | Create and edit — one form, two homes
 | ---------------------------------------------------------------------- */

function openUserForm(user = null) {
    const editing = user !== null;

    adoptForm(userForm, editing ? modalSlot : formSlot, { chrome: editing ? 'modal' : 'inline' });

    clearFormErrors(userForm);
    userForm.reset();

    $('#user-modal-title', userForm).textContent = editing ? `Edit ${user.name}` : 'Add a user';
    $('#user-modal-subtitle', userForm).textContent = editing
        ? 'Changing a role or a status revokes their sessions immediately.'
        : '';

    userForm.elements.id.value = editing ? user.id : '';
    $('#user-name', userForm).value = editing ? user.name : '';
    $('#user-email', userForm).value = editing ? user.email : '';
    $('#user-status', userForm).value = editing ? user.status : 'active';
    paintRoleSelect($('#user-role', userForm), editing ? (user.role?.id ?? '') : '');

    /*
    | A password is required to create an account and optional to change one.
    | Blank on an edit means "leave it alone" — the field is omitted from the
    | payload entirely, rather than sent empty for the server to interpret.
    */
    const password = $('#user-password', userForm);

    password.value = '';
    password.required = !editing;
    password.placeholder = editing ? 'Leave blank to keep the current one' : 'At least 12 characters';

    $('#password-hint', userForm).textContent = editing
        ? 'Leave blank to keep their current password. Setting one signs them out everywhere.'
        : 'At least 12 characters, with upper and lower case, a number and a symbol.';

    if (editing) {
        showModal('#user-modal');

        return;
    }

    workspace?.showForm();
    $('#user-name', userForm).focus();
}

/** A pre-check, so an obvious mistake costs nothing. The API re-validates all of it (§6.1). */
function validate(editing) {
    const errors = {};

    const name = $('#user-name', userForm).value.trim();
    const email = $('#user-email', userForm).value.trim();
    const password = $('#user-password', userForm).value;

    if (name.length < 2) errors.name = ['Give them a name of at least 2 characters.'];
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) errors.email = ['Enter a valid email address.'];

    if (!editing || password !== '') {
        if (password.length < 12) errors.password = ['The password must be at least 12 characters.'];
        else if (!/[a-z]/.test(password) || !/[A-Z]/.test(password)
            || !/\d/.test(password) || !/[^\w\s]/.test(password)) {
            errors.password = ['Use upper and lower case, a number and a symbol.'];
        }
    }

    return Object.keys(errors).length ? errors : null;
}

async function submitUser(event) {
    event.preventDefault();

    const id = userForm.elements.id.value;
    const editing = id !== '';

    clearFormErrors(userForm);

    const errors = validate(editing);

    if (errors) {
        showFormErrors(userForm, { fields: errors, message: 'Please correct the highlighted fields.' });

        return;
    }

    const payload = {
        name: $('#user-name', userForm).value.trim(),
        email: $('#user-email', userForm).value.trim(),
        status: $('#user-status', userForm).value,
        custom_role_id: $('#user-role', userForm).value || null,
    };

    const password = $('#user-password', userForm).value;

    if (password !== '') payload.password = password;

    setSubmitting(userForm, true);

    try {
        const saved = await auth.call(editing ? `/users/${id}` : '/users', {
            method: editing ? 'PATCH' : 'POST',
            body: payload,
        });

        toast(editing ? 'User updated.' : 'User created.');

        // §2A.8 — the row is flagged rather than shown. Somebody entering the
        // workshop's staff writes several in a row and never sees the list in
        // between, so the flash happens whenever they do look.
        if (!editing) workspace?.flagNew(saved?.data?.id);

        if (editing) hideModal('#user-modal');

        // §2A.7 — refetched only where a list is actually held. A module opened
        // to add one user must not be made to fetch the directory by saving.
        if (workspace?.hasList()) await refresh({ keepPage: true });
        else workspace?.refresh();

        // §2A.8 — a successful create stays on the form, cleared and focused,
        // because a clerk writes several in a row.
        if (!editing) openUserForm();
    } catch (error) {
        showFormErrors(userForm, error);
    } finally {
        setSubmitting(userForm, false);
    }
}

/* -------------------------------------------------------------------------
 | Delete
 | ---------------------------------------------------------------------- */

async function destroy(id) {
    const user = state.users.find((row) => String(row.id) === String(id));
    if (!user) return;

    const confirmed = await confirmAction({
        title: 'Delete this user',
        body: `${user.name} loses access immediately and every session they hold is revoked. `
            + 'What they have already posted stays in the books, under their name.',
        confirmLabel: 'Delete user',
    });

    if (!confirmed) return;

    try {
        await auth.call(`/users/${id}`, { method: 'DELETE' });

        toast('User deleted.');
        hideModal('#user-drawer');
        state.openUser = null;

        await refresh({ keepPage: true });
    } catch (error) {
        // USER_SELF_DELETE explains itself, so it is shown as it arrives.
        toast(error.message, 'error');
    }
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

export default async function initUsers() {
    /*
    | Both surfaces are still in the document here — mounting the workspace at
    | the end of this function is what detaches whichever one is not in use — so
    | they are held by reference now, while they can still be found.
    */
    const root = $('[data-ws-list]').closest('[data-module-root]');

    listRoot = $('[data-ws-list]', root);
    userForm = $('#user-form', root);
    formSlot = $('[data-user-form-slot]', root);
    modalSlot = $('[data-user-modal-slot]', root);

    /* Toolbar ---------------------------------------------------------- */

    inList('#filter-search').addEventListener('input', debounce((event) => {
        state.search = event.target.value.trim();
        state.page = 1;
        render();
    }, 200));

    inList('#filter-role')?.addEventListener('change', (event) => {
        state.roleId = event.target.value;
        state.page = 1;
        render();
    });

    inList('#filter-pills').addEventListener('click', (event) => {
        const pill = event.target.closest('[data-pill]');

        if (pill) setPill(pill.dataset.pill);
    });

    allInList('[data-stat-filter]').forEach((tile) =>
        tile.addEventListener('click', () => setPill(tile.dataset.statFilter)));

    inList('#clear-filters').addEventListener('click', clearFilters);

    inList('#users-head').addEventListener('click', (event) => {
        const th = event.target.closest('[data-sort]');

        if (th) applySort(th.dataset.sort);
    });

    inList('#users-pager').addEventListener('click', (event) => {
        const button = event.target.closest('[data-page]');
        if (!button) return;

        state.page = Number(button.dataset.page);
        render();
    });

    /* The table -------------------------------------------------------- */

    inList('#users-body').addEventListener('click', async (event) => {
        const edit = event.target.closest('[data-edit]');

        if (edit) {
            event.stopPropagation();

            const user = state.users.find((row) => String(row.id) === edit.dataset.edit);

            if (user) openUserForm(user);

            return;
        }

        const remove = event.target.closest('[data-delete]');

        if (remove) {
            event.stopPropagation();
            destroy(remove.dataset.delete);

            return;
        }

        const row = event.target.closest('[data-row]');

        if (row) openDrawer(row.dataset.row);
    });

    // A row behaves like the link it looks like.
    inList('#users-body').addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;

        const row = event.target.closest('[data-row]');

        if (row) {
            event.preventDefault();
            openDrawer(row.dataset.row);
        }
    });

    /* The drawer ------------------------------------------------------- */

    $('#user-drawer-edit', root).addEventListener('click', () => {
        if (!state.openUser) return;

        hideModal('#user-drawer');
        openUserForm(state.openUser);
    });

    $('#user-drawer-delete', root).addEventListener('click', () => {
        if (state.openUser) destroy(state.openUser.id);
    });

    /* The form --------------------------------------------------------- */

    userForm.addEventListener('submit', submitUser);

    $('[data-user-clear]', userForm).addEventListener('click', () => openUserForm());

    /* The workspace ---------------------------------------------------- */

    // The picker and the filter both need it, and the form is what the module
    // opens on — so it is fetched before the workspace mounts, not with the list.
    await loadRoles();

    const canWrite = can('WRITE', 'USERS');

    // Filled in before the workspace mounts, because mounting is what shows it:
    // the module lands on this form (§2A.1).
    if (canWrite) openUserForm();

    workspace = mountWorkspace(root, {
        key: 'users',
        title: 'Users',
        formSubtitle: 'Add somebody to this workshop, or show who is already on it.',
        listSubtitle: (count) => (count === null
            ? 'Who may sign in, and as what.'
            : `${count} user${count === 1 ? '' : 's'}. Click a row to open one.`),
        createLabel: 'Add user',
        count: () => (state.users.length ? state.users.length : null),
        canCreate: canWrite,
        onShowList: loadList,

        /*
        | Bring the form home.
        |
        | It may have been left in the edit dialog — closed with Cancel, with
        | Escape, or by a save — and level 1 is where a *create* lives. A form
        | still holding somebody's id is that person's edit form, so it is
        | reopened blank; one holding nothing is re-attached exactly as it was
        | typed. A half-written new user survives a look at the list (§2A.6),
        | somebody else's record does not.
        */
        onShowForm: () => {
            if (userForm.elements.id.value) {
                openUserForm();

                return;
            }

            adoptForm(userForm, formSlot, { chrome: 'inline' });
            $('#user-name', userForm).focus();
        },
    });
}
