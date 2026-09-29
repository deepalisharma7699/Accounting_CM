import auth from '../auth-client';
import { can } from '../permissions';
import {
    $, $$, clearFormErrors, confirmAction, debounce, esc, formatDate, formatRelative,
    hideModal, setSubmitting, showFormErrors, showModal, tableMessage, toast,
} from '../ui';
import { checkedPermissionIds, renderPermissionMatrix } from '../components/permission-matrix';
import { adoptForm, mountWorkspace } from '../workspace';

/**
 * Workshops — every workshop on the platform.
 *
 * ## The §2A flow
 *
 * The module opens on the provisioning form; the platform's workshops sit behind
 * one switch control beside the heading and are fetched the first time it is
 * asked for (§2A.1, §2A.7). `#tenant-form` is written once and *moved* — into the
 * level-1 slot to provision, into the edit dialog to change one — so a create and
 * an edit share one set of fields, one set of ids and one submit handler.
 *
 * ## Paged and filtered by the server
 *
 * Unlike a workshop's own staff, the platform's list has no natural ceiling, so
 * search, status and paging are all sent to `/tenants` rather than applied to a
 * loaded copy. User counts are the exception that proves the rule: the list
 * deliberately does not carry them, so a page of workshops does not run a page
 * of count queries, and the drawer fetches the one it is looking at.
 *
 * ## Looking after a workshop from the drawer
 *
 * The drawer carries three more sections beside the details — the workshop's
 * users, the roles it can give them, and its settings — so the platform can add
 * a login, write a role for this workshop or reset a password without leaving
 * the list. A role written here belongs to this workshop alone: no other
 * workshop sees it, and the platform's own Roles card does not list it. Every
 * request goes to `/tenants/{id}/…`, which the API serves with the very same
 * controllers a workshop's own owner uses, pointed at that workshop; so the
 * rules are the workshop's rules and each change is written to its history. It
 * is the people, the roles and the settings only — never the books.
 *
 * ## Provisioning is one act
 *
 * The owner block is all or nothing, and it is sent inside the same request as
 * the workshop, which is what makes "the workshop and its owner, or neither"
 * true rather than hoped for.
 */

const COLUMNS = 6;
const PER_PAGE = 15;

const STATUS_TONES = {
    active: 'bg-emerald-50 text-emerald-700',
    suspended: 'bg-amber-50 text-amber-700',
    cancelled: 'bg-muted text-muted-foreground',
};

const state = {
    tenants: [],
    pagination: null,

    page: 1,
    search: '',
    status: '',
    sort: { column: 'name', direction: 'asc' },

    openTenant: null,

    // What the open drawer has fetched for its workshop. Dropped whenever a
    // different workshop is opened, so one workshop's people are never painted
    // under another's name.
    tab: 'details',
    users: null,
    roles: null,
    // The grantable catalogue for the open workshop, keyed by resource. Fetched
    // once, the first time a role form is opened, and dropped with the workshop.
    rolePermissions: null,
    settingsLoaded: false,
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
let drawerRoot = null;
let tenantForm = null;
let formSlot = null;
let modalSlot = null;
let workspace = null;

const inList = (selector) => $(selector, listRoot);

/* -------------------------------------------------------------------------
 | Data
 | ---------------------------------------------------------------------- */

async function fetchTenants() {
    const params = new URLSearchParams({
        page: state.page,
        per_page: PER_PAGE,
        sort: state.sort.column,
        direction: state.sort.direction,
    });

    if (state.search) params.set('search', state.search);
    if (state.status) params.set('status', state.status);

    const payload = await auth.call(`/tenants?${params}`);

    state.tenants = payload.data ?? [];
    state.pagination = payload.meta?.pagination ?? null;
}

/** The first Show, every filter change and every retry. Nothing here throws. */
async function loadList() {
    inList('#tenants-body').innerHTML = tableMessage(COLUMNS, 'Loading workshops…');

    try {
        await fetchTenants();
        render();
    } catch (error) {
        inList('#tenants-body').innerHTML = tableMessage(COLUMNS, error.message, 'error');
        inList('#tenants-pagination').innerHTML = '';
    }
}

/** Refetch after a write, keeping the reader on the page they were on. */
async function refresh() {
    try {
        await fetchTenants();

        // A page that emptied — the last row of it was deleted — steps back.
        if (!state.tenants.length && state.page > 1) {
            state.page -= 1;
            await fetchTenants();
        }

        render();
    } catch (error) {
        toast(error.message, 'error');
    }
}

function statusLabel(status) {
    const option = $(`#filter-status option[value="${status}"]`, listRoot);

    return option?.textContent.trim() || status || '—';
}

/* -------------------------------------------------------------------------
 | Rendering
 | ---------------------------------------------------------------------- */

function render() {
    renderRows();
    renderSortIndicators();
    renderPagination();
    workspace?.refresh();
}

function renderRows() {
    const body = inList('#tenants-body');

    if (!state.tenants.length) {
        body.innerHTML = tableMessage(
            COLUMNS,
            state.search || state.status ? 'No workshops match these filters.' : 'No workshop has been provisioned yet.',
        );

        return;
    }

    const mayUpdate = can('UPDATE', 'TENANTS');
    const mayDelete = can('DELETE', 'TENANTS');

    body.innerHTML = state.tenants.map((tenant) => {
        const active = tenant.status === 'active';
        const tone = STATUS_TONES[tenant.status] ?? STATUS_TONES.cancelled;
        const flash = workspace?.isNew(tenant.id) ? ' row-new' : '';
        const name = esc(tenant.name);

        const actions = [
            mayUpdate
                ? `<button type="button" class="btn btn-ghost btn-icon" data-edit="${tenant.id}"
                           title="Edit ${name}" aria-label="Edit ${name}">${iconPencil}</button>`
                : '',
            mayUpdate
                ? `<button type="button" class="btn btn-ghost btn-icon ${active ? 'hover:!text-amber-600' : 'hover:!text-emerald-600'}"
                           data-status="${tenant.id}"
                           title="${active ? 'Suspend' : 'Reactivate'} ${name}"
                           aria-label="${active ? 'Suspend' : 'Reactivate'} ${name}">${active ? iconPause : iconPlay}</button>`
                : '',
            mayDelete
                ? `<button type="button" class="btn btn-ghost btn-icon hover:!text-rose-600" data-delete="${tenant.id}"
                           title="Delete ${name}" aria-label="Delete ${name}">${iconTrash}</button>`
                : '',
        ].join('');

        return `
            <tr class="cursor-pointer transition hover:bg-secondary/60${flash}${active ? '' : ' opacity-70'}"
                data-row="${tenant.id}" tabindex="0" role="button" aria-label="Open ${name}">
                <td class="px-4 py-3">
                    <div class="flex items-center gap-3">
                        <span class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-muted text-secondary-foreground">${iconBuilding}</span>
                        <div class="min-w-0">
                            <div class="truncate text-[0.875rem] font-semibold text-foreground">${name}</div>
                            <div class="truncate font-mono text-[0.75rem] text-muted-foreground">${esc(tenant.slug)}</div>
                        </div>
                    </div>
                </td>
                <td class="px-4 py-3 font-mono text-[0.8125rem] text-muted-foreground">
                    ${tenant.gstin ? esc(tenant.gstin) : '—'}
                    ${tenant.state_code ? `<span class="ml-1 text-[0.6875rem]">(${esc(tenant.state_code)})</span>` : ''}
                </td>
                <td class="px-4 py-3"><span class="badge ${tone}">${esc(statusLabel(tenant.status))}</span></td>
                <td class="px-4 py-3 text-[0.8125rem] text-muted-foreground" data-users="${tenant.id}">—</td>
                <td class="px-4 py-3 text-[0.8125rem] whitespace-nowrap text-muted-foreground">${esc(formatDate(tenant.created_at))}</td>
                <td class="px-4 py-3">
                    <div class="flex justify-end gap-1">${actions
                        || '<span class="text-xs text-muted-foreground">—</span>'}</div>
                </td>
            </tr>`;
    }).join('');

    loadUserCounts(state.tenants);
}

/**
 * User counts come from the single-tenant endpoint, which is the only place that
 * reports them — the list deliberately does not, so paging through a hundred
 * workshops does not run a hundred count queries server-side. Filling them in
 * afterwards keeps the table useful without making the list slow.
 */
function loadUserCounts(tenants) {
    tenants.forEach(async (tenant) => {
        try {
            const { data } = await auth.call(`/tenants/${tenant.id}`);
            const cell = $(`[data-users="${tenant.id}"]`, listRoot);

            if (cell) cell.textContent = data.user_count ?? '—';
        } catch {
            // A count is decoration; its absence must not break the row.
        }
    });
}

function renderSortIndicators() {
    $$('#tenants-head [data-sort]', listRoot).forEach((th) => {
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

function applySort(column) {
    if (state.sort.column === column) {
        state.sort.direction = state.sort.direction === 'asc' ? 'desc' : 'asc';
    } else {
        state.sort = { column, direction: column === 'created_at' ? 'desc' : 'asc' };
    }

    state.page = 1;
    loadList();
}

function renderPagination() {
    const host = inList('#tenants-pagination');
    const pagination = state.pagination;

    if (!pagination) {
        host.innerHTML = '';

        return;
    }

    const summary = pagination.last_page > 1
        ? `Page ${pagination.current_page} of ${pagination.last_page} · ${pagination.total} workshop(s)`
        : `${pagination.total} workshop(s)`;

    host.innerHTML = `
        <span class="text-[0.78125rem] text-muted-foreground">${esc(summary)}</span>
        ${pagination.last_page > 1 ? `
            <div class="flex gap-2">
                <button type="button" class="btn btn-secondary btn-sm" data-page="prev" ${pagination.current_page <= 1 ? 'disabled' : ''}>Previous</button>
                <button type="button" class="btn btn-secondary btn-sm" data-page="next" ${!pagination.has_more ? 'disabled' : ''}>Next</button>
            </div>` : ''}`;
}

const svg = (paths, size = 16) => `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">${paths}</svg>`;

const iconBuilding = svg('<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01M16 6h.01M8 10h.01M16 10h.01M8 14h.01M16 14h.01"/>', 18);
const iconPencil = svg('<path d="M21.17 6.83a2.83 2.83 0 0 0-4-4L3.5 16.5 2 22l5.5-1.5z"/><path d="m15 5 4 4"/>');
const iconTrash = svg('<path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>');
const iconPause = svg('<rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>');
const iconPlay = svg('<path d="m6 3 14 9-14 9z"/>');
const iconArrowUp = svg('<path d="M12 19V5"/><path d="m5 12 7-7 7 7"/>', 12);
const iconArrowDown = svg('<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>', 12);

/* -------------------------------------------------------------------------
 | The drawer — one workshop, level 2
 | ---------------------------------------------------------------------- */

async function openDrawer(id) {
    const tenant = state.tenants.find((row) => String(row.id) === String(id));
    if (!tenant) return;

    state.openTenant = tenant;
    state.users = null;
    state.roles = null;
    state.rolePermissions = null;
    state.settingsLoaded = false;

    paintDrawer(tenant, null);
    setTab('details');

    showModal('#tenant-drawer');

    // The user count is only on the single-tenant response — see loadUserCounts().
    try {
        const { data } = await auth.call(`/tenants/${tenant.id}`);

        // The reader may have moved on while this was in flight.
        if (state.openTenant?.id !== tenant.id) return;

        state.openTenant = data;
        paintDrawer(data, data.user_count ?? null);
    } catch (error) {
        if (state.openTenant?.id !== tenant.id) return;

        $('#tenant-drawer-body').innerHTML += `<p class="mt-5 text-[0.8125rem] text-rose-600">${esc(error.message)}</p>`;
    }
}

function paintDrawer(tenant, userCount) {
    const active = tenant.status === 'active';
    const toggle = $('#tenant-drawer-status-toggle');

    $('#tenant-drawer-title').textContent = tenant.name;
    $('#tenant-drawer-slug').textContent = tenant.slug;
    $('#tenant-drawer-status').innerHTML =
        `<span class="badge ${STATUS_TONES[tenant.status] ?? STATUS_TONES.cancelled}">${esc(statusLabel(tenant.status))}</span>`;
    $('#tenant-drawer-users').textContent = userCount ?? '—';
    $('#tenant-drawer-created').textContent = formatDate(tenant.created_at);

    toggle.innerHTML = `${active ? iconPause : iconPlay} ${active ? 'Suspend' : 'Reactivate'}`;
    toggle.classList.toggle('hidden', !can('UPDATE', 'TENANTS'));
    $('#tenant-drawer-edit').classList.toggle('hidden', !can('UPDATE', 'TENANTS'));
    $('#tenant-drawer-delete').classList.toggle('hidden', !can('DELETE', 'TENANTS'));

    $('#tenant-drawer-body').innerHTML = drawerDetails(tenant);
}

function drawerDetails(tenant) {
    const settings = tenant.settings ?? {};
    const year = tenant.current_financial_year;

    return `
        <dl class="dl">
            <dt>GSTIN</dt>
            <dd class="font-mono">${tenant.gstin ? esc(tenant.gstin) : '<span class="text-muted-foreground">Not given</span>'}</dd>

            <dt>State code</dt>
            <dd class="font-mono">${tenant.state_code ? esc(tenant.state_code) : '—'}</dd>

            <dt>Address</dt>
            <dd>${tenant.address ? esc(tenant.address) : '<span class="text-muted-foreground">Not given</span>'}</dd>

            <dt>Financial year</dt>
            <dd>${year ? `${esc(formatDate(year.start))} – ${esc(formatDate(year.end))}` : '—'}</dd>

            <dt>Currency</dt>
            <dd>${settings.currency ? esc(settings.currency) : '—'}</dd>

            <dt>Time zone</dt>
            <dd>${settings.timezone ? esc(settings.timezone) : '—'}</dd>
        </dl>`;
}

/* -------------------------------------------------------------------------
 | The drawer's sections — users, roles and settings of the open workshop
 | ---------------------------------------------------------------------- */

const USER_TONES = {
    active: 'bg-emerald-50 text-emerald-700',
    inactive: 'bg-muted text-secondary-foreground',
    suspended: 'bg-rose-50 text-rose-700',
    pending: 'bg-amber-50 text-amber-700',
};

/** `/tenants/12` — every section below talks to the workshop that is open. */
const base = () => `/tenants/${state.openTenant.id}`;

/** The workshop the request was made for; a slow answer for another is dropped. */
const stillOpen = (id) => String(state.openTenant?.id) === String(id);

function setTab(tab) {
    state.tab = tab;

    $$('[data-tenant-tab]', drawerRoot).forEach((button) => {
        button.setAttribute('aria-selected', String(button.dataset.tenantTab === tab));
    });

    $$('[data-tenant-panel]', drawerRoot).forEach((panel) => {
        panel.classList.toggle('hidden', panel.dataset.tenantPanel !== tab);
    });

    // Fetched the first time each is looked at, and held for as long as this
    // workshop stays open.
    if (tab === 'users' && state.users === null) loadUsers();
    if (tab === 'roles' && state.roles === null) loadRoles();
    if (tab === 'settings' && !state.settingsLoaded) loadSettings();
}

function setTabCount(tab, count) {
    const host = $(`[data-tab-count="${tab}"]`, drawerRoot);

    if (host) host.textContent = count === null ? '' : `(${count})`;
}

function panelMessage(host, message, tone = 'muted') {
    host.innerHTML = `<p class="py-6 text-center text-[0.8125rem] ${tone === 'error' ? 'text-rose-600' : 'text-muted-foreground'}">${esc(message)}</p>`;
}

/* --- Roles -------------------------------------------------------------- */

async function loadRoles() {
    const id = state.openTenant.id;
    const host = $('#tenant-roles-list', drawerRoot);

    panelMessage(host, 'Loading roles…');

    try {
        const payload = await auth.call(`${base()}/roles?per_page=100`);

        if (!stillOpen(id)) return;

        state.roles = payload.data ?? [];
        setTabCount('roles', state.roles.length);
        renderRoles();
    } catch (error) {
        if (stillOpen(id)) panelMessage(host, error.message, 'error');
    }
}

function renderRoles() {
    const host = $('#tenant-roles-list', drawerRoot);

    if (!state.roles.length) {
        panelMessage(host, 'This workshop has no roles yet.');

        return;
    }

    const mayUpdate = can('UPDATE', 'ROLES');
    const mayDelete = can('DELETE', 'ROLES');

    host.innerHTML = `
        <div class="surface overflow-hidden rounded-[12px]"><div class="overflow-x-auto">
            <table class="w-full min-w-[520px] border-collapse">
                <thead>
                    <tr class="border-b border-border bg-background text-left">
                        <th class="px-3 py-2 text-[11.5px] font-semibold text-muted-foreground" scope="col">Role</th>
                        <th class="px-3 py-2 text-[11.5px] font-semibold text-muted-foreground" scope="col">Permissions</th>
                        <th class="px-3 py-2 text-[11.5px] font-semibold text-muted-foreground" scope="col">Users</th>
                        <th class="relative px-3 py-2" scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-muted">${state.roles.map((role) => {
                    const grants = (role.permissions ?? []);
                    const full = grants.some((grant) => grant.action === '*' && grant.resource === '*');
                    // The API decides; the drawer follows it, exactly as the
                    // Roles module does (§4.4).
                    const locked = Boolean(role.is_system_role) || role.editable === false;

                    const control = (allowed, attrs, label, icon, danger = false) => {
                        if (!allowed) return '';

                        return locked
                            ? `<button type="button" class="btn btn-ghost btn-icon opacity-40" disabled
                                       title="System roles cannot be changed"
                                       aria-label="${label} (not available)">${icon}</button>`
                            : `<button type="button" class="btn btn-ghost btn-icon ${danger ? 'hover:!text-rose-600' : ''}"
                                       ${attrs} title="${label}" aria-label="${label}">${icon}</button>`;
                    };

                    return `
                        <tr>
                            <td class="px-3 py-2.5">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-[0.875rem] font-semibold text-foreground">${esc(role.name)}</span>
                                    ${role.is_system_role
                                        ? '<span class="badge bg-accent text-accent-foreground">System</span>'
                                        : ''}
                                </div>
                                ${role.description
                                    ? `<div class="mt-0.5 text-[0.78125rem] text-muted-foreground">${esc(role.description)}</div>`
                                    : ''}
                            </td>
                            <td class="px-3 py-2.5 text-[0.8125rem] text-muted-foreground">${full ? 'All' : grants.length}</td>
                            <td class="px-3 py-2.5 text-[0.8125rem] text-muted-foreground">${role.users_count ?? 0}</td>
                            <td class="px-3 py-2.5">
                                <div class="flex justify-end gap-1">
                                    ${control(mayUpdate, `data-role-edit="${role.id}"`, `Edit ${esc(role.name)}`, iconPencil)}
                                    ${control(mayDelete, `data-role-delete="${role.id}"`, `Delete ${esc(role.name)}`, iconTrash, true)}
                                </div>
                            </td>
                        </tr>`;
                }).join('')}</tbody>
            </table>
        </div></div>`;
}

/**
 * The grants a role of *this workshop* may be given.
 *
 * `/tenants/{id}/permissions` is the ordinary permissions endpoint with the
 * context re-pointed, so it answers with the catalogue already narrowed to what
 * a workshop role may carry — no platform-level grant, no wildcard, and nothing
 * the caller does not hold themselves. Nobody is offered a tick the API would
 * then refuse.
 */
async function loadRolePermissions() {
    if (state.rolePermissions !== null || !can('READ', 'PERMISSIONS')) return;

    const id = state.openTenant.id;

    try {
        const { data } = await auth.call(`${base()}/permissions?grouped=1`);

        if (stillOpen(id)) state.rolePermissions = data ?? {};
    } catch {
        // Non-fatal: the matrix says so where it would have been drawn, and a
        // save then leaves the role's existing grants alone.
        if (stillOpen(id)) state.rolePermissions = {};
    }
}

async function openRoleForm(role = null) {
    const editing = role !== null;
    const form = $('#tenant-role-form');

    clearFormErrors(form);
    form.reset();

    form.elements.id.value = editing ? role.id : '';
    form.elements.name.value = editing ? role.name : '';
    form.elements.description.value = editing ? (role.description ?? '') : '';

    $('#tenant-role-title').textContent = editing ? `Edit ${role.name}` : 'Add a role';
    $('#tenant-role-subtitle').textContent = `${state.openTenant.name} · this role belongs to this workshop alone.`;

    // Drawn empty first, so the dialog opens on something rather than on a gap
    // while the catalogue is in flight.
    renderPermissionMatrix($('#tenant-role-matrix'), state.rolePermissions ?? {}, [], {
        mayRead: can('READ', 'PERMISSIONS'),
    });

    showModal('#tenant-role-modal');

    await loadRolePermissions();

    renderPermissionMatrix(
        $('#tenant-role-matrix'),
        state.rolePermissions ?? {},
        editing ? (role.permissions ?? []).map((permission) => permission.id) : [],
        { mayRead: can('READ', 'PERMISSIONS') },
    );
}

/** A pre-check only. The API re-validates all of it (§6.1). */
function validateRole(form) {
    const errors = {};
    const name = form.elements.name.value.trim();

    if (name.length < 2) errors.name = ['The role name must be at least 2 characters.'];
    else if (name.length > 64) errors.name = ['The role name may not exceed 64 characters.'];
    else if (!/^[\p{L}\p{N}][\p{L}\p{N} \-_]*$/u.test(name)) {
        errors.name = ['Use only letters, numbers, spaces, hyphens and underscores.'];
    }

    if (form.elements.description.value.trim().length > 255) {
        errors.description = ['The description may not exceed 255 characters.'];
    }

    return Object.keys(errors).length ? errors : null;
}

async function submitRole(event) {
    event.preventDefault();

    const form = event.target;
    const id = form.elements.id.value;
    const editing = id !== '';

    clearFormErrors(form);

    const errors = validateRole(form);

    if (errors) {
        showFormErrors(form, { fields: errors, message: 'Please correct the highlighted fields.' });

        return;
    }

    const payload = {
        name: form.elements.name.value.trim(),
        description: form.elements.description.value.trim() || null,
    };

    /*
    | The grants are sent only when the matrix was actually drawn. A caller
    | without READ:PERMISSIONS sees no checkboxes, and sending the empty set
    | that produces would strip every grant the role holds — a rename would
    | silently disable everybody who has it.
    */
    if (state.rolePermissions && Object.keys(state.rolePermissions).length) {
        payload.permission_ids = checkedPermissionIds(form);
    }

    setSubmitting(form, true);

    try {
        await auth.call(editing ? `${base()}/roles/${id}` : `${base()}/roles`, {
            method: editing ? 'PATCH' : 'POST',
            body: payload,
        });

        toast(editing ? 'Role updated.' : 'Role created.');
        hideModal('#tenant-role-modal');

        await loadRoles();
    } catch (error) {
        showFormErrors(form, error);
    } finally {
        setSubmitting(form, false);
    }
}

async function deleteRole(id) {
    const role = state.roles?.find((row) => String(row.id) === String(id));
    if (!role) return;

    const held = role.users_count ?? 0;

    const confirmed = await confirmAction({
        title: 'Delete this role',
        body: held > 0
            ? `${role.name} is held by ${held} user${held === 1 ? '' : 's'} in ${state.openTenant.name}. It cannot `
                + 'be deleted while anybody holds it — give them another role first, then delete this one.'
            : `${role.name} will be removed from ${state.openTenant.name}. Nobody holds it, so nobody loses access, `
                + 'and no other workshop is affected.',
        confirmLabel: 'Delete role',
    });

    if (!confirmed) return;

    try {
        await auth.call(`${base()}/roles/${id}`, { method: 'DELETE' });

        toast('Role deleted.');

        await loadRoles();
    } catch (error) {
        // RBAC_ROLE_IN_USE and the system-role refusal both explain themselves.
        toast(error.message, 'error');
    }
}

/** Fill the role picker from this workshop's own list. Never written into the markup. */
async function paintRoleSelect(selectedId) {
    if (state.roles === null) await loadRoles();

    const select = $('#tuser-role');

    select.innerHTML = '<option value="">No role</option>'
        + (state.roles ?? []).map((role) => `<option value="${role.id}">${esc(role.name)}</option>`).join('');

    select.value = selectedId ? String(selectedId) : '';
}

/* --- Users -------------------------------------------------------------- */

async function loadUsers() {
    const id = state.openTenant.id;
    const host = $('#tenant-users-list', drawerRoot);

    panelMessage(host, 'Loading users…');

    try {
        const payload = await auth.call(`${base()}/users?per_page=100`);

        if (!stillOpen(id)) return;

        state.users = payload.data ?? [];
        setTabCount('users', state.users.length);
        renderUsers();
    } catch (error) {
        if (stillOpen(id)) panelMessage(host, error.message, 'error');
    }
}

function renderUsers() {
    const host = $('#tenant-users-list', drawerRoot);

    if (!state.users.length) {
        panelMessage(host, 'Nobody can sign in to this workshop yet.');

        return;
    }

    const mayUpdate = can('UPDATE', 'USERS');
    const mayDelete = can('DELETE', 'USERS');

    host.innerHTML = `
        <div class="surface overflow-hidden rounded-[12px]"><div class="overflow-x-auto">
            <table class="w-full min-w-[520px] border-collapse">
                <thead>
                    <tr class="border-b border-border bg-background text-left">
                        <th class="px-3 py-2 text-[11.5px] font-semibold text-muted-foreground" scope="col">User</th>
                        <th class="px-3 py-2 text-[11.5px] font-semibold text-muted-foreground" scope="col">Role</th>
                        <th class="px-3 py-2 text-[11.5px] font-semibold text-muted-foreground" scope="col">Status</th>
                        <th class="px-3 py-2 text-[11.5px] font-semibold text-muted-foreground" scope="col">Last sign-in</th>
                        <th class="relative px-3 py-2" scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-muted">${state.users.map((user) => `
                    <tr>
                        <td class="px-3 py-2.5">
                            <div class="truncate text-[0.875rem] font-semibold text-foreground">${esc(user.name)}</div>
                            <div class="truncate text-[0.78125rem] text-muted-foreground">${esc(user.email)}</div>
                        </td>
                        <td class="px-3 py-2.5 text-[0.8125rem]">
                            ${user.role
                                ? `<span class="badge bg-accent text-accent-foreground">${esc(user.role.name)}</span>`
                                : '<span class="text-muted-foreground">No role</span>'}
                        </td>
                        <td class="px-3 py-2.5">
                            <span class="badge ${USER_TONES[user.status] ?? USER_TONES.inactive}">${esc(userStatusLabel(user.status))}</span>
                        </td>
                        <td class="px-3 py-2.5 text-[0.8125rem] whitespace-nowrap text-muted-foreground">
                            ${user.last_login_at ? esc(formatRelative(user.last_login_at)) : 'Never'}
                        </td>
                        <td class="px-3 py-2.5">
                            <div class="flex justify-end gap-1">
                                ${mayUpdate ? `<button type="button" class="btn btn-ghost btn-icon" data-user-edit="${user.id}"
                                        title="Edit ${esc(user.name)}" aria-label="Edit ${esc(user.name)}">${iconPencil}</button>` : ''}
                                ${mayDelete ? `<button type="button" class="btn btn-ghost btn-icon hover:!text-rose-600" data-user-delete="${user.id}"
                                        title="Delete ${esc(user.name)}" aria-label="Delete ${esc(user.name)}">${iconTrash}</button>` : ''}
                            </div>
                        </td>
                    </tr>`).join('')}</tbody>
            </table>
        </div></div>`;
}

function userStatusLabel(status) {
    const option = $(`#tuser-status option[value="${status}"]`);

    return option?.textContent.trim() || status || '—';
}

async function openUserForm(user = null) {
    const editing = user !== null;
    const form = $('#tenant-user-form');

    clearFormErrors(form);
    form.reset();

    form.elements.id.value = editing ? user.id : '';
    form.elements.name.value = editing ? user.name : '';
    form.elements.email.value = editing ? user.email : '';
    form.elements.status.value = editing ? user.status : 'active';

    $('#tenant-user-title').textContent = editing ? `Edit ${user.name}` : 'Add a user';
    $('#tenant-user-subtitle').textContent = `${state.openTenant.name} · a role or status change signs them out everywhere.`;

    const password = form.elements.password;

    password.required = !editing;
    password.placeholder = editing ? 'Leave blank to keep the current one' : 'At least 12 characters';
    $('#tenant-user-password-hint').textContent = editing
        ? 'Leave blank to keep their current password. Setting one signs them out everywhere.'
        : 'At least 12 characters, with upper and lower case, a number and a symbol.';

    showModal('#tenant-user-modal');

    await paintRoleSelect(editing ? (user.role?.id ?? '') : '');
}

function validateUser(form, editing) {
    const errors = {};
    const name = form.elements.name.value.trim();
    const email = form.elements.email.value.trim();
    const password = form.elements.password.value;

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

    const form = event.target;
    const id = form.elements.id.value;
    const editing = id !== '';

    clearFormErrors(form);

    const errors = validateUser(form, editing);

    if (errors) {
        showFormErrors(form, { fields: errors, message: 'Please correct the highlighted fields.' });

        return;
    }

    const payload = {
        name: form.elements.name.value.trim(),
        email: form.elements.email.value.trim(),
        status: form.elements.status.value,
        custom_role_id: form.elements.custom_role_id.value || null,
    };

    // Blank on an edit means "leave it alone" — omitted, never sent empty.
    if (form.elements.password.value !== '') payload.password = form.elements.password.value;

    setSubmitting(form, true);

    try {
        await auth.call(editing ? `${base()}/users/${id}` : `${base()}/users`, {
            method: editing ? 'PATCH' : 'POST',
            body: payload,
        });

        toast(editing ? 'User updated.' : 'User created.');
        hideModal('#tenant-user-modal');

        await loadUsers();
        showUserCount();
    } catch (error) {
        showFormErrors(form, error);
    } finally {
        setSubmitting(form, false);
    }
}

async function deleteUser(id) {
    const user = state.users?.find((row) => String(row.id) === String(id));
    if (!user) return;

    const confirmed = await confirmAction({
        title: 'Delete this user',
        body: `${user.name} loses access to ${state.openTenant.name} immediately and every session they hold is `
            + 'revoked. What they have already posted stays in the books, under their name.',
        confirmLabel: 'Delete user',
    });

    if (!confirmed) return;

    try {
        await auth.call(`${base()}/users/${id}`, { method: 'DELETE' });

        toast('User deleted.');

        await loadUsers();
        showUserCount();
    } catch (error) {
        toast(error.message, 'error');
    }
}

/** The list and the drawer both show a user count; keep them honest after a write. */
function showUserCount() {
    if (state.users === null) return;

    $('#tenant-drawer-users').textContent = state.users.length;

    const cell = $(`[data-users="${state.openTenant.id}"]`, listRoot);

    if (cell) cell.textContent = state.users.length;
}

/* --- Settings ----------------------------------------------------------- */

async function loadSettings() {
    const id = state.openTenant.id;
    const form = $('#tenant-settings-form', drawerRoot);

    clearFormErrors(form);

    try {
        const { data } = await auth.call(`${base()}/workspace`);

        if (!stillOpen(id)) return;

        const settings = data.settings ?? {};

        form.elements.financial_year_start_month.value = String(settings.financial_year_start_month ?? 4);
        form.elements.timezone.value = settings.timezone ?? '';
        form.elements.books_start_date.value = settings.books_start_date ?? '';
        form.elements.payment_due_days.value = settings.payment_due_days ?? '';
        form.elements.allow_negative_stock.checked = Boolean(settings.allow_negative_stock);
        form.elements.round_off_invoices.checked = Boolean(settings.round_off_invoices);

        state.settingsLoaded = true;
    } catch (error) {
        if (stillOpen(id)) showFormErrors(form, error);
    }
}

async function submitSettings(event) {
    event.preventDefault();

    const form = event.target;

    clearFormErrors(form);

    const due = form.elements.payment_due_days.value.trim();

    const payload = {
        financial_year_start_month: Number(form.elements.financial_year_start_month.value),
        timezone: form.elements.timezone.value.trim(),
        books_start_date: form.elements.books_start_date.value || null,
        payment_due_days: due === '' ? null : Number(due),
        allow_negative_stock: form.elements.allow_negative_stock.checked,
        round_off_invoices: form.elements.round_off_invoices.checked,
    };

    setSubmitting(form, true);

    try {
        await auth.call(`${base()}/workspace`, { method: 'PATCH', body: payload });

        toast('Settings saved.');
    } catch (error) {
        showFormErrors(form, error);
    } finally {
        setSubmitting(form, false);
    }
}

/* -------------------------------------------------------------------------
 | Provision and edit — one form, two homes
 | ---------------------------------------------------------------------- */

/** Mirrors Tenant::slugFor(): "Sharma Electricals" -> sharma-electricals. */
function slugFor(name) {
    return name.trim().toLowerCase()
        .replace(/[^\p{L}\p{N}]+/gu, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 150);
}

function openTenantForm(tenant = null) {
    const editing = tenant !== null;

    adoptForm(tenantForm, editing ? modalSlot : formSlot, { chrome: editing ? 'modal' : 'inline' });

    clearFormErrors(tenantForm);
    tenantForm.reset();

    $('#tenant-modal-title', tenantForm).textContent = editing ? `Edit ${tenant.name}` : 'Provision a workshop';
    $('#tenant-modal-subtitle', tenantForm).textContent = editing
        ? 'The handle is set once and stays as it is.'
        : '';

    tenantForm.elements.id.value = editing ? tenant.id : '';
    tenantForm.elements.name.value = editing ? tenant.name : '';
    tenantForm.elements.gstin.value = editing ? (tenant.gstin ?? '') : '';
    tenantForm.elements.state_code.value = editing ? (tenant.state_code ?? '') : '';
    tenantForm.elements.address.value = editing ? (tenant.address ?? '') : '';

    $('#tenant-slug-preview', tenantForm).textContent = editing ? tenant.slug : '—';

    // An existing workshop's people are managed from inside it, not from here.
    $('#tenant-owner-block', tenantForm).classList.toggle('hidden', editing);

    if (editing) {
        showModal('#tenant-modal');

        return;
    }

    workspace?.showForm();
    tenantForm.elements.name.focus();
}

function ownerFields() {
    return {
        name: tenantForm.elements.owner_name.value.trim(),
        email: tenantForm.elements.owner_email.value.trim(),
        password: tenantForm.elements.owner_password.value,
    };
}

/** A pre-check, so an obvious mistake costs nothing. The API re-validates all of it (§6.1). */
function validate(editing) {
    const errors = {};
    const name = tenantForm.elements.name.value.trim();

    if (name.length < 2) errors.name = ['The workshop name must be at least 2 characters.'];
    else if (name.length > 160) errors.name = ['The workshop name may not exceed 160 characters.'];

    const gstin = tenantForm.elements.gstin.value.trim().toUpperCase();
    if (gstin && !/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/.test(gstin)) {
        errors.gstin = ['That does not look like a valid GSTIN.'];
    }

    const stateCode = tenantForm.elements.state_code.value.trim();
    if (stateCode && !/^[0-9]{2}$/.test(stateCode)) {
        errors.state_code = ['A state code is two digits.'];
    }

    if (!editing) {
        const owner = ownerFields();
        const supplied = [owner.name, owner.email, owner.password].filter(Boolean).length;

        // All or nothing: a half-filled owner is a slip, not an intention.
        if (supplied > 0 && supplied < 3) {
            if (!owner.name) errors.owner_name = ['Required when creating an owner.'];
            if (!owner.email) errors.owner_email = ['Required when creating an owner.'];
            if (!owner.password) errors.owner_password = ['Required when creating an owner.'];
        }
    }

    return Object.keys(errors).length ? errors : null;
}

/**
 * The API reports the owner block as `owner.email`; the form's inputs are named
 * `owner_email`. Without this the message would have nowhere to land and would
 * fall back to the banner.
 */
function mapOwnerErrors(error) {
    if (!error.fields) return error;

    const fields = {};

    Object.entries(error.fields).forEach(([key, messages]) => {
        fields[key.startsWith('owner.') ? key.replace('owner.', 'owner_') : key] = messages;
    });

    error.fields = fields;

    return error;
}

async function submitTenant(event) {
    event.preventDefault();

    const id = tenantForm.elements.id.value;
    const editing = id !== '';

    clearFormErrors(tenantForm);

    const errors = validate(editing);

    if (errors) {
        showFormErrors(tenantForm, { fields: errors, message: 'Please correct the highlighted fields.' });

        return;
    }

    const payload = {
        name: tenantForm.elements.name.value.trim(),
        gstin: tenantForm.elements.gstin.value.trim().toUpperCase() || null,
        state_code: tenantForm.elements.state_code.value.trim() || null,
        address: tenantForm.elements.address.value.trim() || null,
    };

    if (!editing) {
        const owner = ownerFields();

        if (owner.name && owner.email && owner.password) payload.owner = owner;
    }

    setSubmitting(tenantForm, true);

    try {
        const saved = await auth.call(editing ? `/tenants/${id}` : '/tenants', {
            method: editing ? 'PATCH' : 'POST',
            body: payload,
        });

        toast(editing ? 'Workshop updated.' : 'Workshop created.');

        // §2A.8 — the row is flagged rather than shown, so the flash happens
        // whenever the list is next looked at.
        if (!editing) workspace?.flagNew(saved?.data?.id);

        if (editing) hideModal('#tenant-modal');

        // §2A.7 — refetched only where a list is actually held.
        if (workspace?.hasList()) await refresh();
        else workspace?.refresh();

        // §2A.8 — a successful create stays on the form, cleared and focused.
        if (!editing) openTenantForm();
    } catch (error) {
        showFormErrors(tenantForm, mapOwnerErrors(error));
    } finally {
        setSubmitting(tenantForm, false);
    }
}

/* -------------------------------------------------------------------------
 | Suspend / reactivate / delete — level 3 confirmations
 | ---------------------------------------------------------------------- */

function findTenant(id) {
    return state.tenants.find((row) => String(row.id) === String(id))
        ?? (String(state.openTenant?.id) === String(id) ? state.openTenant : null);
}

async function changeStatus(id) {
    const tenant = findTenant(id);
    if (!tenant) return;

    const active = tenant.status === 'active';

    if (active) {
        const confirmed = await confirmAction({
            title: 'Suspend workshop',
            body: `${tenant.name} will be locked immediately and everyone inside it signed out. Their books are kept `
                + 'intact and reactivating restores access.',
            confirmLabel: 'Suspend workshop',
        });

        if (!confirmed) return;
    }

    try {
        await auth.call(`/tenants/${id}/status`, {
            method: 'PUT',
            body: { status: active ? 'suspended' : 'active' },
        });

        toast(active ? 'Workshop suspended.' : 'Workshop reactivated.');
        hideModal('#tenant-drawer');
        state.openTenant = null;

        await refresh();
    } catch (error) {
        toast(error.message, 'error');
    }
}

async function destroy(id) {
    const tenant = findTenant(id);
    if (!tenant) return;

    const confirmed = await confirmAction({
        title: 'Delete workshop',
        body: `${tenant.name} will be removed. A workshop that still has users cannot be deleted — remove them first, `
            + 'or suspend the workshop instead to keep its books.',
        confirmLabel: 'Delete workshop',
    });

    if (!confirmed) return;

    try {
        await auth.call(`/tenants/${id}`, { method: 'DELETE' });

        toast('Workshop deleted.');
        hideModal('#tenant-drawer');
        state.openTenant = null;

        await refresh();
    } catch (error) {
        // 409 TENANT_IN_USE lands here with a useful message.
        toast(error.message, 'error');
    }
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

export default async function initTenants() {
    /*
    | Both surfaces are still in the document here — mounting the workspace at
    | the end of this function is what detaches whichever one is not in use — so
    | they are held by reference now, while they can still be found.
    */
    const root = $('[data-ws-list]').closest('[data-module-root]');

    listRoot = $('[data-ws-list]', root);
    drawerRoot = $('#tenant-drawer', root);
    tenantForm = $('#tenant-form', root);
    formSlot = $('[data-tenant-form-slot]', root);
    modalSlot = $('[data-tenant-modal-slot]', root);

    /* Toolbar ---------------------------------------------------------- */

    inList('#filter-search').addEventListener('input', debounce((event) => {
        state.search = event.target.value.trim();
        state.page = 1;
        loadList();
    }, 350));

    inList('#filter-status').addEventListener('change', (event) => {
        state.status = event.target.value;
        state.page = 1;
        loadList();
    });

    inList('#tenants-head').addEventListener('click', (event) => {
        const th = event.target.closest('[data-sort]');

        if (th) applySort(th.dataset.sort);
    });

    inList('#tenants-pagination').addEventListener('click', (event) => {
        const button = event.target.closest('[data-page]');
        if (!button) return;

        state.page += button.dataset.page === 'next' ? 1 : -1;
        loadList();
    });

    /* The table -------------------------------------------------------- */

    inList('#tenants-body').addEventListener('click', async (event) => {
        const edit = event.target.closest('[data-edit]');

        if (edit) {
            event.stopPropagation();

            try {
                const { data } = await auth.call(`/tenants/${edit.dataset.edit}`);
                openTenantForm(data);
            } catch (error) {
                toast(error.message, 'error');
            }

            return;
        }

        const status = event.target.closest('[data-status]');

        if (status) {
            event.stopPropagation();
            changeStatus(status.dataset.status);

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
    inList('#tenants-body').addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        if (event.target.closest('button')) return;

        const row = event.target.closest('[data-row]');

        if (row) {
            event.preventDefault();
            openDrawer(row.dataset.row);
        }
    });

    /* The drawer ------------------------------------------------------- */

    $('#tenant-drawer-edit', root).addEventListener('click', () => {
        if (!state.openTenant) return;

        const tenant = state.openTenant;

        hideModal('#tenant-drawer');
        openTenantForm(tenant);
    });

    $('#tenant-drawer-status-toggle', root).addEventListener('click', () => {
        if (state.openTenant) changeStatus(state.openTenant.id);
    });

    $('#tenant-drawer-delete', root).addEventListener('click', () => {
        if (state.openTenant) destroy(state.openTenant.id);
    });

    $('[data-tenant-tabs]', drawerRoot).addEventListener('click', (event) => {
        const tab = event.target.closest('[data-tenant-tab]');

        if (tab) setTab(tab.dataset.tenantTab);
    });

    $('#tenant-users-list', drawerRoot).addEventListener('click', (event) => {
        const edit = event.target.closest('[data-user-edit]');
        const remove = event.target.closest('[data-user-delete]');

        if (edit) {
            const user = state.users?.find((row) => String(row.id) === edit.dataset.userEdit);

            if (user) openUserForm(user);
        }

        if (remove) deleteUser(remove.dataset.userDelete);
    });

    $('#tenant-roles-list', drawerRoot).addEventListener('click', (event) => {
        const edit = event.target.closest('[data-role-edit]');
        const remove = event.target.closest('[data-role-delete]');

        if (edit) {
            const role = state.roles?.find((row) => String(row.id) === edit.dataset.roleEdit);

            if (role) openRoleForm(role);
        }

        if (remove) deleteRole(remove.dataset.roleDelete);
    });

    $('#tenant-user-add', drawerRoot).addEventListener('click', () => openUserForm());
    $('#tenant-role-add', drawerRoot).addEventListener('click', () => openRoleForm());
    $('#tenant-user-form', root).addEventListener('submit', submitUser);
    $('#tenant-role-form', root).addEventListener('submit', submitRole);
    $('#tenant-settings-form', drawerRoot).addEventListener('submit', submitSettings);

    /* The form --------------------------------------------------------- */

    tenantForm.addEventListener('submit', submitTenant);

    $('[data-tenant-clear]', tenantForm).addEventListener('click', () => openTenantForm());

    // Live handle preview, and only while creating — the slug is set once.
    tenantForm.elements.name.addEventListener('input', (event) => {
        if (tenantForm.elements.id.value !== '') return;

        $('#tenant-slug-preview', tenantForm).textContent = slugFor(event.target.value) || '—';
    });

    // A GSTIN carries its state code in the first two digits, so fill it in and
    // stop the two disagreeing.
    tenantForm.elements.gstin.addEventListener('input', (event) => {
        const gstin = event.target.value.trim().toUpperCase();

        if (/^[0-9]{2}/.test(gstin)) tenantForm.elements.state_code.value = gstin.slice(0, 2);
    });

    /* The workspace ---------------------------------------------------- */

    const canWrite = can('WRITE', 'TENANTS');

    // Filled in before the workspace mounts, because mounting is what shows it:
    // the module lands on this form (§2A.1).
    if (canWrite) openTenantForm();

    workspace = mountWorkspace(root, {
        key: 'tenants',
        title: 'Workshops',
        formSubtitle: 'Provision a workshop, or show every one already on the platform.',
        listSubtitle: (count) => (count === null
            ? 'Every workshop on the platform.'
            : `${count} workshop${count === 1 ? '' : 's'}. Click a row to open one.`),
        createLabel: 'Provision workshop',
        count: () => state.pagination?.total ?? null,
        canCreate: canWrite,
        onShowList: loadList,

        /*
        | Bring the form home.
        |
        | It may have been left in the edit dialog — closed with Cancel, with
        | Escape, or by a save — and level 1 is where a *create* lives. A form
        | still holding a workshop's id is that workshop's edit form, so it is
        | reopened blank; one holding nothing is re-attached exactly as it was
        | typed. A half-written new workshop survives a look at the list
        | (§2A.6), somebody else's record does not.
        */
        onShowForm: () => {
            if (tenantForm.elements.id.value) {
                openTenantForm();

                return;
            }

            adoptForm(tenantForm, formSlot, { chrome: 'inline' });
            tenantForm.elements.name.focus();
        },
    });
}
