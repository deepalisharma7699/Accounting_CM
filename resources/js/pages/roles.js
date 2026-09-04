import auth from '../auth-client';
import { can } from '../permissions';
import {
    $, $$, clearFormErrors, confirmAction, debounce, esc,
    hideModal, setSubmitting, showFormErrors, showModal, tableMessage, toast,
} from '../ui';
import { adoptForm, mountWorkspace } from '../workspace';

/**
 * Roles — what each role is allowed to do.
 *
 * ## The §2A flow
 *
 * The module opens on its create form and the roles sit behind one switch
 * control beside the heading (§2A.1). A caller who may read roles but not write
 * them lands on the list instead and is painted no switch at all — which is the
 * ordinary case here: the workshop OWNER holds READ:ROLES and nothing more,
 * because a role is defined for the whole platform and a tenant creating one
 * would be creating it for everybody. Only ADMIN writes here.
 *
 * ## The matrix comes from the API
 *
 * `#permission-matrix` is filled from GET /permissions?grouped=1. The catalogue
 * grows with every module that gets built, so a copy of it in this file — or in
 * the markup — would be a list of grants that quietly stops matching the ones
 * the middleware checks: a role given permissions that no longer exist, and
 * refused ones that do.
 *
 * ## System roles are shown, not hidden
 *
 * ADMIN is seeded, flagged `is_system_role`, and refused by the API for edit and
 * delete — one compromised admin session must not be able to rewrite the
 * superuser role and lock everybody else out. It is listed here with its
 * controls disabled rather than removed, so the reason is visible where the
 * question is asked.
 */

const COLUMNS = 5;
const PAGE_SIZE = 25;

// The roles endpoint caps per_page at 100.
const FETCH_SIZE = 100;
const MAX_PAGES = 10;

const state = {
    roles: [],
    permissions: {},   // { RESOURCE: [{ id, action, description }] }
    matrixLoaded: false,
    truncated: false,

    search: '',
    pill: 'all',
    sort: { column: 'name', direction: 'asc' },
    page: 1,

    openRole: null,
};

/*
| Held at mount, while both surfaces are still in the document — see the note in
| CLAUDE.md: §2A.2 detaches whichever one is not in use, and a `document`
| lookup into the other finds nothing exactly when a save needs it.
*/
let listRoot = null;
let roleForm = null;
let formSlot = null;
let modalSlot = null;
let workspace = null;

const inList = (selector) => $(selector, listRoot);
const allInList = (selector) => $$(selector, listRoot);

/* -------------------------------------------------------------------------
 | Data
 | ---------------------------------------------------------------------- */

async function loadMatrix() {
    if (state.matrixLoaded || !can('READ', 'PERMISSIONS')) return;

    try {
        const { data } = await auth.call('/permissions?grouped=1');

        state.permissions = data ?? {};
        state.matrixLoaded = true;
    } catch {
        // Non-fatal: the matrix says so where it would have been drawn.
        state.permissions = {};
    }
}

async function fetchRoles() {
    const roles = [];
    let page = 1;
    let more = true;

    while (more && page <= MAX_PAGES) {
        // eslint-disable-next-line no-await-in-loop -- pages are sequential by definition.
        const payload = await auth.call(`/roles?per_page=${FETCH_SIZE}&page=${page}`);

        roles.push(...(payload.data ?? []));

        more = Boolean(payload.meta?.pagination?.has_more);
        page += 1;
    }

    state.truncated = more;
    state.roles = roles;
}

/** The first Show, and every retry of it. Nothing here is allowed to throw. */
async function loadList() {
    inList('#roles-body').innerHTML = tableMessage(COLUMNS, 'Loading roles…');

    try {
        await fetchRoles();
        render();
    } catch (error) {
        inList('#roles-body').innerHTML = tableMessage(COLUMNS, error.message, 'error');
        inList('#roles-summary').textContent = '';
    }
}

async function refresh({ keepPage = false } = {}) {
    const page = state.page;

    if (!keepPage) state.page = 1;

    try {
        await fetchRoles();

        if (keepPage) state.page = page;

        render();
    } catch (error) {
        toast(error.message, 'error');
    }
}

/* -------------------------------------------------------------------------
 | Filtering, sorting, paging — client-side, over the whole list
 | ---------------------------------------------------------------------- */

function grantsOf(role) {
    return role.permissions ?? [];
}

function isWildcard(role) {
    return grantsOf(role).some((permission) => permission.action === '*' && permission.resource === '*');
}

/** Everything but the pill, so the tiles count what the pill chooses between. */
function scoped() {
    const search = state.search.toLowerCase();

    if (!search) return state.roles;

    return state.roles.filter((role) =>
        `${role.name} ${role.slug} ${role.description ?? ''}`.toLowerCase().includes(search));
}

function matchesPill(role) {
    if (state.pill === 'system') return role.is_system_role;
    if (state.pill === 'custom') return !role.is_system_role;
    if (state.pill === 'unassigned') return (role.users_count ?? 0) === 0;

    return true;
}

function sortValue(role, column) {
    if (column === 'grants') return isWildcard(role) ? Number.MAX_SAFE_INTEGER : grantsOf(role).length;
    if (column === 'users') return role.users_count ?? 0;

    return (role.name ?? '').toLowerCase();
}

function sorted(roles) {
    const { column, direction } = state.sort;
    const sign = direction === 'asc' ? 1 : -1;

    return [...roles].sort((a, b) => {
        const left = sortValue(a, column);
        const right = sortValue(b, column);

        if (left === right) return a.name.localeCompare(b.name);

        return left > right ? sign : -sign;
    });
}

/* -------------------------------------------------------------------------
 | Rendering
 | ---------------------------------------------------------------------- */

function render() {
    const scope = scoped();
    const matched = sorted(scope.filter(matchesPill));

    // Clamped before the rows are painted: a filter that shortens the list while
    // the reader is on page 2 must not paint an empty table first.
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
    inList('#stat-custom').textContent = scope
        .filter((role) => !role.is_system_role).length.toLocaleString('en-IN');
    inList('#stat-system').textContent = scope
        .filter((role) => role.is_system_role).length.toLocaleString('en-IN');
    inList('#stat-unassigned').textContent = scope
        .filter((role) => (role.users_count ?? 0) === 0).length.toLocaleString('en-IN');

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

    const filtering = state.pill !== 'all' || state.search !== '';

    inList('#clear-filters').classList.toggle('hidden', !filtering);
    inList('#clear-filters').classList.toggle('flex', filtering);
}

function renderRows(roles) {
    const body = inList('#roles-body');

    if (!roles.length) {
        body.innerHTML = tableMessage(
            COLUMNS,
            state.roles.length ? 'No roles match these filters.' : 'No roles have been defined yet.',
        );

        return;
    }

    const mayUpdate = can('UPDATE', 'ROLES');
    const mayDelete = can('DELETE', 'ROLES');

    const start = (state.page - 1) * PAGE_SIZE;

    body.innerHTML = roles.slice(start, start + PAGE_SIZE).map((role) => {
        const locked = Boolean(role.is_system_role);
        const flash = workspace?.isNew(role.id) ? ' row-new' : '';

        /*
        | A system role's controls are disabled rather than dropped: the answer
        | to "why can I not edit ADMIN" belongs where the question is asked, and
        | an absent button asks it without answering.
        */
        const control = (allowed, attrs, label, icon, danger = false) => {
            if (!allowed) return '';

            return locked
                ? `<button type="button" class="btn btn-ghost btn-icon opacity-40" disabled
                           title="System roles cannot be changed" aria-label="${label} (not available)">${icon}</button>`
                : `<button type="button" class="btn btn-ghost btn-icon ${danger ? 'hover:!text-rose-600' : ''}"
                           ${attrs} title="${label}" aria-label="${label}">${icon}</button>`;
        };

        return `
            <tr class="cursor-pointer transition hover:bg-secondary/60${flash}" data-row="${role.id}"
                tabindex="0" role="button" aria-label="Open ${esc(role.name)}">
                <td class="px-4 py-3">
                    <div class="flex items-center gap-3">
                        <span class="grid size-9 shrink-0 place-items-center rounded-[10px] ${locked
                            ? 'bg-accent text-accent-foreground'
                            : 'bg-muted text-secondary-foreground'}">${iconShield}</span>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="truncate text-[0.875rem] font-semibold text-foreground">${esc(role.name)}</span>
                                ${locked ? '<span class="badge bg-accent text-accent-foreground">System</span>' : ''}
                            </div>
                            <div class="truncate font-mono text-[0.75rem] text-muted-foreground">${esc(role.slug)}</div>
                        </div>
                    </div>
                </td>
                <td class="max-w-xs px-4 py-3">
                    <span class="line-clamp-2 text-[0.8125rem] text-muted-foreground">${esc(role.description ?? '') || '—'}</span>
                </td>
                <td class="px-4 py-3"><div class="flex flex-wrap items-center gap-1">${grantPreview(role)}</div></td>
                <td class="px-4 py-3 text-[0.8125rem] whitespace-nowrap text-muted-foreground">
                    ${role.users_count ?? 0}
                </td>
                <td class="px-4 py-3">
                    <div class="flex justify-end gap-1">
                        ${control(mayUpdate, `data-edit="${role.id}"`, 'Edit role', iconPencil)}
                        ${control(mayDelete, `data-delete="${role.id}"`, 'Delete role', iconTrash, true)}
                    </div>
                </td>
            </tr>`;
    }).join('');
}

/** The first few grants, and how many more there are. The drawer has them all. */
function grantPreview(role) {
    if (isWildcard(role)) {
        return '<span class="badge bg-accent text-accent-foreground">Full access</span>';
    }

    const grants = grantsOf(role);

    if (!grants.length) return '<span class="text-[0.78125rem] text-muted-foreground">None</span>';

    const shown = grants.slice(0, 3)
        .map((permission) => `<span class="badge bg-secondary text-secondary-foreground">${esc(permission.key)}</span>`)
        .join('');

    return grants.length > 3
        ? `${shown}<span class="text-[0.75rem] text-muted-foreground">+${grants.length - 3} more</span>`
        : shown;
}

function renderSummary(matched) {
    const total = state.roles.length;

    const parts = [`Showing ${matched.toLocaleString('en-IN')} of ${total.toLocaleString('en-IN')} roles`];

    if (matched !== total) parts.push('· Filtered');
    if (state.truncated) parts.push(`· first ${total.toLocaleString('en-IN')} loaded`);

    inList('#roles-summary').textContent = parts.join(' ');
}

function renderPager(matched) {
    const host = inList('#roles-pager');
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
    allInList('#roles-head [data-sort]').forEach((th) => {
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
    state.pill = 'all';
    state.page = 1;

    inList('#filter-search').value = '';

    render();
}

/* -------------------------------------------------------------------------
 | Icons
 | ---------------------------------------------------------------------- */

const svg = (paths, size = 16) =>
    `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor"
          stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="shrink-0">${paths}</svg>`;

const iconShield = svg('<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 '
    + '4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>', 18);
const iconPencil = svg('<path d="M21.17 6.83a2.83 2.83 0 0 0-4-4L3.5 16.5 2 22l5.5-1.5z"/><path d="m15 5 4 4"/>');
const iconTrash = svg('<path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>'
    + '<path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>');
const iconArrowUp = svg('<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>', 12);
const iconArrowDown = svg('<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>', 12);

/* -------------------------------------------------------------------------
 | The drawer — one role, level 2
 | ---------------------------------------------------------------------- */

function openDrawer(id) {
    const role = state.roles.find((row) => String(row.id) === String(id));
    if (!role) return;

    state.openRole = role;

    const locked = Boolean(role.is_system_role);

    $('#role-drawer-title').textContent = role.name;
    $('#role-drawer-slug').textContent = role.slug;
    $('#role-drawer-badge').innerHTML = locked
        ? '<span class="badge bg-accent text-accent-foreground">System</span>'
        : '';
    $('#role-drawer-grants').textContent = isWildcard(role) ? 'All' : String(grantsOf(role).length);
    $('#role-drawer-users').textContent = String(role.users_count ?? 0);

    // Present but disabled on a system role, for the same reason as the row's:
    // the answer belongs where the question is asked.
    const edit = $('#role-drawer-edit');
    const remove = $('#role-drawer-delete');

    edit.classList.toggle('hidden', !can('UPDATE', 'ROLES'));
    remove.classList.toggle('hidden', !can('DELETE', 'ROLES'));
    edit.disabled = locked;
    remove.disabled = locked;
    edit.title = locked ? 'System roles cannot be changed' : '';
    remove.title = locked ? 'System roles cannot be deleted' : '';

    $('#role-drawer-body').innerHTML = drawerBody(role);

    showModal('#role-drawer');
}

function drawerBody(role) {
    const description = role.description
        ? `<p class="text-[0.875rem] text-secondary-foreground">${esc(role.description)}</p>`
        : '<p class="text-[0.8125rem] text-muted-foreground">No description.</p>';

    const heading = '<h4 class="mt-6 mb-2 text-[0.6875rem] font-semibold uppercase tracking-wider '
        + 'text-muted-foreground">Permissions</h4>';

    if (isWildcard(role)) {
        return `${description}${heading}<p class="hint"><span>Every action on every resource, including ones
            that do not exist yet.</span></p>`;
    }

    const grants = grantsOf(role);

    if (!grants.length) {
        return `${description}${heading}<p class="text-[0.8125rem] text-muted-foreground">
            No grants at all. Somebody holding this role can sign in and do nothing.</p>`;
    }

    const byResource = new Map();

    grants.forEach((permission) => {
        if (!byResource.has(permission.resource)) byResource.set(permission.resource, []);
        byResource.get(permission.resource).push(permission.action);
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

    return description + heading + groups;
}

/* -------------------------------------------------------------------------
 | The permission matrix
 | ---------------------------------------------------------------------- */

function renderMatrix(selectedIds = []) {
    const host = $('#permission-matrix', roleForm);
    const resources = Object.keys(state.permissions);

    if (!resources.length) {
        host.innerHTML = `<p class="text-[0.8125rem] text-muted-foreground">${
            can('READ', 'PERMISSIONS')
                ? 'The permission catalogue could not be loaded. A role saved now would keep the grants it has.'
                : 'Reading the permission catalogue needs READ:PERMISSIONS, so the grants cannot be shown here.'
        }</p>`;

        return;
    }

    const selected = new Set(selectedIds.map(String));

    /*
    | The `*` resource holds the full-access grant the ADMIN role uses. Left
    | inline it is simply the first checkbox in the list, which makes it far too
    | easy to hand a custom role superuser rights by accident — so it gets its
    | own labelled block, away from the ordinary per-resource grants.
    */
    const wildcard = resources.includes('*')
        ? `<fieldset class="rounded-[10px] border border-amber-200 bg-amber-50/60 p-3">
               <legend class="px-1 text-[0.6875rem] font-semibold uppercase tracking-wider text-amber-700">
                   Full access
               </legend>
               <div class="mt-1 space-y-1.5">
                   ${state.permissions['*'].map((permission) => `
                       <label class="flex cursor-pointer items-start gap-2 text-[0.8125rem] text-amber-900">
                           <input type="checkbox" name="permission_ids" value="${permission.id}"
                                  class="mt-0.5 size-4 rounded border-amber-300 text-amber-600 focus:ring-2 focus:ring-amber-300"
                                  ${selected.has(String(permission.id)) ? 'checked' : ''}>
                           <span>Grants <strong>every action on every resource</strong>, including ones that do not
                           exist yet. Prefer explicit grants below.</span>
                       </label>`).join('')}
               </div>
           </fieldset>`
        : '';

    host.innerHTML = wildcard + resources.filter((resource) => resource !== '*').map((resource) => `
        <fieldset class="rounded-[10px] border border-border p-3">
            <legend class="px-1 text-[0.6875rem] font-semibold uppercase tracking-wider text-muted-foreground">
                ${esc(resource)}
            </legend>
            <div class="mt-1 flex flex-wrap gap-x-5 gap-y-2">
                ${state.permissions[resource].map((permission) => `
                    <label class="flex cursor-pointer items-center gap-2 text-[0.8125rem] text-secondary-foreground"
                           title="${esc(permission.description ?? '')}">
                        <input type="checkbox" name="permission_ids" value="${permission.id}"
                               class="size-4 rounded border-border text-primary focus:ring-2 focus:ring-ring"
                               ${selected.has(String(permission.id)) ? 'checked' : ''}>
                        <span>${esc(permission.action)}</span>
                    </label>`).join('')}
            </div>
        </fieldset>`).join('');
}

/* -------------------------------------------------------------------------
 | Create and edit — one form, two homes
 | ---------------------------------------------------------------------- */

/** Mirrors Role::slugFor — "Branch Accountant" becomes BRANCH_ACCOUNTANT. */
function slugFor(name) {
    return name.trim().replace(/[^\p{L}\p{N}]+/gu, '_').replace(/^_+|_+$/g, '').toUpperCase();
}

async function openRoleForm(role = null) {
    const editing = role !== null;

    await loadMatrix();

    adoptForm(roleForm, editing ? modalSlot : formSlot, { chrome: editing ? 'modal' : 'inline' });

    clearFormErrors(roleForm);
    roleForm.reset();

    $('#role-modal-title', roleForm).textContent = editing ? `Edit ${role.name}` : 'Create a role';
    $('#role-modal-subtitle', roleForm).textContent = editing
        ? 'Taking a grant away here takes it away on the holder’s next request.'
        : '';

    roleForm.elements.id.value = editing ? role.id : '';
    $('#role-name', roleForm).value = editing ? role.name : '';
    $('#role-description', roleForm).value = editing ? (role.description ?? '') : '';
    $('#role-slug-preview', roleForm).textContent = editing ? role.slug : '—';

    renderMatrix(editing ? grantsOf(role).map((permission) => permission.id) : []);

    if (editing) {
        showModal('#role-modal');

        return;
    }

    workspace?.showForm();
    $('#role-name', roleForm).focus();
}

/** A pre-check only. The API re-validates all of it (§6.1). */
function validate() {
    const errors = {};
    const name = $('#role-name', roleForm).value.trim();

    if (name.length < 2) errors.name = ['The role name must be at least 2 characters.'];
    else if (name.length > 64) errors.name = ['The role name may not exceed 64 characters.'];
    else if (!/^[\p{L}\p{N}][\p{L}\p{N} \-_]*$/u.test(name)) {
        errors.name = ['Use only letters, numbers, spaces, hyphens and underscores.'];
    }

    if ($('#role-description', roleForm).value.trim().length > 255) {
        errors.description = ['The description may not exceed 255 characters.'];
    }

    return Object.keys(errors).length ? errors : null;
}

async function submitRole(event) {
    event.preventDefault();

    const id = roleForm.elements.id.value;
    const editing = id !== '';

    clearFormErrors(roleForm);

    const errors = validate();

    if (errors) {
        showFormErrors(roleForm, { fields: errors, message: 'Please correct the highlighted fields.' });

        return;
    }

    const payload = {
        name: $('#role-name', roleForm).value.trim(),
        description: $('#role-description', roleForm).value.trim() || null,
    };

    /*
    | The grants are sent only when the matrix was actually drawn. A caller
    | without READ:PERMISSIONS sees no checkboxes, and sending the empty set
    | that produces would strip every grant the role holds — a rename would
    | silently disable everybody who has it.
    */
    if (state.matrixLoaded) {
        payload.permission_ids = $$('input[name="permission_ids"]:checked', roleForm)
            .map((input) => Number(input.value));
    }

    setSubmitting(roleForm, true);

    try {
        const saved = await auth.call(editing ? `/roles/${id}` : '/roles', {
            method: editing ? 'PATCH' : 'POST',
            body: payload,
        });

        toast(editing ? 'Role updated.' : 'Role created.');

        // §2A.8 — flagged rather than shown; the flash happens whenever the list
        // is next looked at.
        if (!editing) workspace?.flagNew(saved?.data?.id);

        if (editing) hideModal('#role-modal');

        // §2A.7 — refetched only where a list is actually held.
        if (workspace?.hasList()) await refresh({ keepPage: true });
        else workspace?.refresh();

        // §2A.8 — a create stays on the form, cleared and focused.
        if (!editing) await openRoleForm();
    } catch (error) {
        showFormErrors(roleForm, error);
    } finally {
        setSubmitting(roleForm, false);
    }
}

/* -------------------------------------------------------------------------
 | Delete
 | ---------------------------------------------------------------------- */

async function destroy(id) {
    const role = state.roles.find((row) => String(row.id) === String(id));
    if (!role) return;

    const held = role.users_count ?? 0;

    const confirmed = await confirmAction({
        title: 'Delete this role',
        body: held > 0
            ? `${role.name} is held by ${held} user${held === 1 ? '' : 's'}. It cannot be deleted while anybody `
                + 'holds it — give them another role first, then delete this one.'
            : `${role.name} will be removed. Nobody holds it, so nobody loses access.`,
        confirmLabel: 'Delete role',
    });

    if (!confirmed) return;

    try {
        await auth.call(`/roles/${id}`, { method: 'DELETE' });

        toast('Role deleted.');
        hideModal('#role-drawer');
        state.openRole = null;

        await refresh({ keepPage: true });
    } catch (error) {
        // RBAC_ROLE_IN_USE and the system-role refusal both explain themselves.
        toast(error.message, 'error');
    }
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

export default async function initRoles() {
    const root = $('[data-ws-list]').closest('[data-module-root]');

    listRoot = $('[data-ws-list]', root);
    roleForm = $('#role-form', root);
    formSlot = $('[data-role-form-slot]', root);
    modalSlot = $('[data-role-modal-slot]', root);

    /* Toolbar ---------------------------------------------------------- */

    inList('#filter-search').addEventListener('input', debounce((event) => {
        state.search = event.target.value.trim();
        state.page = 1;
        render();
    }, 200));

    inList('#filter-pills').addEventListener('click', (event) => {
        const pill = event.target.closest('[data-pill]');

        if (pill) setPill(pill.dataset.pill);
    });

    allInList('[data-stat-filter]').forEach((tile) =>
        tile.addEventListener('click', () => setPill(tile.dataset.statFilter)));

    inList('#clear-filters').addEventListener('click', clearFilters);

    inList('#roles-head').addEventListener('click', (event) => {
        const th = event.target.closest('[data-sort]');

        if (th) applySort(th.dataset.sort);
    });

    inList('#roles-pager').addEventListener('click', (event) => {
        const button = event.target.closest('[data-page]');
        if (!button) return;

        state.page = Number(button.dataset.page);
        render();
    });

    /* The table -------------------------------------------------------- */

    inList('#roles-body').addEventListener('click', (event) => {
        const edit = event.target.closest('[data-edit]');

        if (edit) {
            event.stopPropagation();

            const role = state.roles.find((row) => String(row.id) === edit.dataset.edit);

            if (role) openRoleForm(role);

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

    inList('#roles-body').addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;

        const row = event.target.closest('[data-row]');

        if (row) {
            event.preventDefault();
            openDrawer(row.dataset.row);
        }
    });

    /* The drawer ------------------------------------------------------- */

    $('#role-drawer-edit', root).addEventListener('click', () => {
        if (!state.openRole || state.openRole.is_system_role) return;

        hideModal('#role-drawer');
        openRoleForm(state.openRole);
    });

    $('#role-drawer-delete', root).addEventListener('click', () => {
        if (state.openRole && !state.openRole.is_system_role) destroy(state.openRole.id);
    });

    /* The form --------------------------------------------------------- */

    roleForm.addEventListener('submit', submitRole);

    $('[data-role-clear]', roleForm).addEventListener('click', () => openRoleForm());

    // The derived identifier, live, so it is never a surprise after saving.
    $('#role-name', roleForm).addEventListener('input', (event) => {
        $('#role-slug-preview', roleForm).textContent = slugFor(event.target.value) || '—';
    });

    /* The workspace ---------------------------------------------------- */

    const canWrite = can('WRITE', 'ROLES');

    // Filled in before the workspace mounts, because mounting is what shows it:
    // the module lands on this form (§2A.1). A caller without the grant lands on
    // the list instead, and `canCreate` paints them no switch to a surface they
    // cannot use — which is the ordinary case here (see the note at the top).
    if (canWrite) await openRoleForm();

    workspace = mountWorkspace(root, {
        key: 'roles',
        title: 'Roles',
        formSubtitle: 'Define what a job is allowed to do, or show the roles that already exist.',
        listSubtitle: (count) => (count === null
            ? 'What each role is allowed to do.'
            : `${count} role${count === 1 ? '' : 's'}. Click a row to see every grant it carries.`),
        createLabel: 'Create role',
        count: () => (state.roles.length ? state.roles.length : null),
        canCreate: canWrite,
        onShowList: loadList,

        /*
        | Bring the form home. A form still holding a role's id is that role's
        | edit form, so it is reopened blank; one holding nothing is re-attached
        | exactly as it was left, half-ticked matrix and all (§2A.6).
        */
        onShowForm: () => {
            if (roleForm.elements.id.value) {
                openRoleForm();

                return;
            }

            adoptForm(roleForm, formSlot, { chrome: 'inline' });
            $('#role-name', roleForm).focus();
        },
    });
}
