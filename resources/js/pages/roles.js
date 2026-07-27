import auth from '../auth-client';
import { can } from '../permissions';
import {
    $, $$, clearFormErrors, confirmAction, debounce, esc,
    hideModal, setSubmitting, showFormErrors, showModal, tableMessage, toast,
} from '../ui';

const COLUMNS = 5;

const state = {
    page: 1,
    perPage: 15,
    search: '',
    permissions: {},   // { RESOURCE: [{id, action, description}] }
    loadedMatrix: false,
};

/* -------------------------------------------------------------------------
 | Data
 | ---------------------------------------------------------------------- */

async function loadPermissionMatrix() {
    if (state.loadedMatrix || !can('READ', 'PERMISSIONS')) return;

    const { data } = await auth.call('/permissions?grouped=1');
    state.permissions = data;
    state.loadedMatrix = true;
}

async function loadRoles() {
    const body = $('#roles-body');
    body.innerHTML = tableMessage(COLUMNS, 'Loading roles…');

    const params = new URLSearchParams({ page: state.page, per_page: state.perPage });
    if (state.search) params.set('search', state.search);

    try {
        const payload = await auth.call(`/roles?${params}`);
        render(payload.data, payload.meta?.pagination);
    } catch (error) {
        body.innerHTML = tableMessage(COLUMNS, error.message, 'error');
        $('#roles-pagination').innerHTML = '';
    }
}

/* -------------------------------------------------------------------------
 | Rendering
 | ---------------------------------------------------------------------- */

function render(roles, pagination) {
    const body = $('#roles-body');

    if (!roles.length) {
        body.innerHTML = tableMessage(COLUMNS, 'No roles match this search.');
        $('#roles-pagination').innerHTML = '';

        return;
    }

    const mayUpdate = can('UPDATE', 'ROLES');
    const mayDelete = can('DELETE', 'ROLES');

    body.innerHTML = roles.map((role) => {
        const grants = role.permissions ?? [];
        const wildcard = grants.some((p) => p.action === '*' && p.resource === '*');

        // System roles are immutable through the API, so their controls are
        // disabled rather than hidden — the reason stays visible.
        const locked = role.is_system_role;

        const preview = wildcard
            ? '<span class="badge bg-accent text-accent-foreground">Full access</span>'
            : grants.slice(0, 3).map((p) =>
                `<span class="badge bg-secondary text-secondary-foreground">${esc(p.key)}</span>`).join(' ')
                + (grants.length > 3
                    ? ` <span class="text-[0.75rem] text-muted-foreground">+${grants.length - 3} more</span>`
                    : '');

        const action = (enabled, attrs, label, icon, danger = false) => enabled
            ? `<button type="button" class="btn btn-ghost btn-icon ${danger ? 'hover:!text-rose-600' : ''}" ${attrs} title="${label}" aria-label="${label}">${icon}</button>`
            : `<button type="button" class="btn btn-ghost btn-icon opacity-40" disabled title="System roles cannot be modified" aria-label="${label} (disabled)">${icon}</button>`;

        return `
            <tr class="border-t border-border transition hover:bg-secondary/60">
                <td class="table-cell">
                    <div class="flex items-center gap-3">
                        <span class="grid size-9 shrink-0 place-items-center rounded-[10px] ${locked ? 'bg-accent text-accent-foreground' : 'bg-muted text-secondary-foreground'}">${iconShield}</span>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="truncate font-semibold">${esc(role.name)}</span>
                                ${locked ? '<span class="badge bg-accent text-accent-foreground">System</span>' : ''}
                            </div>
                            <div class="truncate text-[0.8125rem] text-muted-foreground">${esc(role.slug)}</div>
                        </div>
                    </div>
                </td>
                <td class="table-cell max-w-xs">
                    <span class="line-clamp-2 text-[0.8125rem] text-muted-foreground">${esc(role.description) || '—'}</span>
                </td>
                <td class="table-cell"><div class="flex flex-wrap items-center gap-1">${preview || '<span class="text-[0.8125rem] text-muted-foreground">None</span>'}</div></td>
                <td class="table-cell text-[0.8125rem] text-muted-foreground">${role.users_count ?? 0}</td>
                <td class="table-cell">
                    <div class="flex justify-end gap-1">
                        ${mayUpdate ? action(!locked, `data-edit="${role.id}"`, 'Edit role', iconPencil) : ''}
                        ${mayDelete ? action(!locked, `data-delete="${role.id}" data-name="${esc(role.name)}"`, 'Delete role', iconTrash, true) : ''}
                    </div>
                </td>
            </tr>`;
    }).join('');

    renderPagination(pagination);
}

function renderPagination(pagination) {
    const host = $('#roles-pagination');

    if (!pagination || pagination.last_page <= 1) {
        host.innerHTML = pagination
            ? `<span class="text-[0.8125rem] text-muted-foreground">${pagination.total} role(s)</span>`
            : '';

        return;
    }

    host.innerHTML = `
        <span class="text-[0.8125rem] text-muted-foreground">
            Page ${pagination.current_page} of ${pagination.last_page} · ${pagination.total} role(s)
        </span>
        <div class="flex gap-2">
            <button type="button" class="btn btn-secondary btn-sm" data-page="prev" ${pagination.current_page <= 1 ? 'disabled' : ''}>Previous</button>
            <button type="button" class="btn btn-secondary btn-sm" data-page="next" ${!pagination.has_more ? 'disabled' : ''}>Next</button>
        </div>`;
}

const iconShield = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/></svg>';
const iconPencil = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M21.17 6.83a2.83 2.83 0 0 0-4-4L3.5 16.5 2 22l5.5-1.5z"/><path d="m15 5 4 4"/></svg>';
const iconTrash = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>';

/* -------------------------------------------------------------------------
 | Permission matrix
 | ---------------------------------------------------------------------- */

function renderMatrix(selectedIds = []) {
    const host = $('#permission-matrix');
    const resources = Object.keys(state.permissions);

    if (!resources.length) {
        host.innerHTML = '<p class="text-[0.8125rem] text-muted-foreground">You do not have permission to view the permission catalogue.</p>';

        return;
    }

    const selected = new Set(selectedIds.map(String));

    // The "*" resource holds the full-access grant that the ADMIN system role
    // uses. Left inline it is simply the first checkbox in the list, which makes
    // it far too easy to hand a custom role superuser rights by accident — so it
    // gets its own labelled block, away from the ordinary per-resource grants.
    const wildcardGroup = resources.includes('*')
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
                           <span>Grants <strong>every action on every resource</strong>, including future ones. Prefer explicit grants below.</span>
                       </label>`).join('')}
               </div>
           </fieldset>`
        : '';

    host.innerHTML = wildcardGroup + resources.filter((resource) => resource !== '*').map((resource) => `
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
 | Create / edit
 | ---------------------------------------------------------------------- */

async function openForm(role = null) {
    const form = $('#role-form');
    const editing = role !== null;

    clearFormErrors(form);
    form.reset();

    await loadPermissionMatrix();

    $('#role-modal-title').textContent = editing ? 'Edit role' : 'New role';
    form.elements.id.value = editing ? role.id : '';
    form.elements.name.value = editing ? role.name : '';
    form.elements.description.value = editing ? (role.description ?? '') : '';

    renderMatrix(editing ? (role.permissions ?? []).map((p) => p.id) : []);
    $('#role-slug-preview').textContent = editing ? role.slug : '—';

    showModal('#role-modal');
}

/** Mirrors the slug the server derives: "Branch Accountant" -> BRANCH_ACCOUNTANT. */
function slugFor(name) {
    return name.trim().replace(/[^\p{L}\p{N}]+/gu, '_').replace(/^_+|_+$/g, '').toUpperCase();
}

function validate(form) {
    const errors = {};
    const name = form.elements.name.value.trim();

    if (name.length < 2) errors.name = ['The role name must be at least 2 characters.'];
    else if (name.length > 64) errors.name = ['The role name may not exceed 64 characters.'];
    else if (!/^[\p{L}\p{N}][\p{L}\p{N} \-_]*$/u.test(name)) {
        errors.name = ['Use only letters, numbers, spaces, hyphens and underscores.'];
    }

    const description = form.elements.description.value.trim();
    if (description.length > 255) errors.description = ['The description may not exceed 255 characters.'];

    return Object.keys(errors).length ? errors : null;
}

async function submitForm(event) {
    event.preventDefault();

    const form = event.target;
    const id = form.elements.id.value;
    const editing = id !== '';

    clearFormErrors(form);

    const errors = validate(form);

    if (errors) {
        showFormErrors(form, { fields: errors, message: 'Please correct the highlighted fields.' });

        return;
    }

    const payload = {
        name: form.elements.name.value.trim(),
        description: form.elements.description.value.trim() || null,
        permission_ids: $$('input[name="permission_ids"]:checked', form).map((el) => Number(el.value)),
    };

    setSubmitting(form, true);

    try {
        await auth.call(editing ? `/roles/${id}` : '/roles', {
            method: editing ? 'PATCH' : 'POST',
            body: payload,
        });

        hideModal('#role-modal');
        toast(editing ? 'Role updated.' : 'Role created.');
        loadRoles();
    } catch (error) {
        showFormErrors(form, error);
    } finally {
        setSubmitting(form, false);
    }
}

/* -------------------------------------------------------------------------
 | Delete
 | ---------------------------------------------------------------------- */

async function remove(id, name) {
    const confirmed = await confirmAction({
        title: 'Delete role',
        body: `${name} will be removed. Roles still assigned to users cannot be deleted — reassign those users first.`,
        confirmLabel: 'Delete role',
    });

    if (!confirmed) return;

    try {
        await auth.call(`/roles/${id}`, { method: 'DELETE' });
        toast('Role deleted.');

        const remaining = $$('#roles-body tr').length - 1;
        if (remaining === 0 && state.page > 1) state.page -= 1;

        loadRoles();
    } catch (error) {
        // 409 RBAC_ROLE_IN_USE lands here with a useful message.
        toast(error.message, 'error');
    }
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

export default async function initRoles() {
    await loadRoles();

    $('#new-role')?.addEventListener('click', () => openForm());
    $('#role-form').addEventListener('submit', submitForm);

    // Live slug preview so the derived identifier is never a surprise.
    $('#role-name').addEventListener('input', (event) => {
        $('#role-slug-preview').textContent = slugFor(event.target.value) || '—';
    });

    $('#filter-search').addEventListener('input', debounce((event) => {
        state.search = event.target.value.trim();
        state.page = 1;
        loadRoles();
    }, 350));

    $('#roles-body').addEventListener('click', async (event) => {
        const editButton = event.target.closest('[data-edit]');
        const deleteButton = event.target.closest('[data-delete]');

        if (editButton) {
            const { data } = await auth.call(`/roles/${editButton.dataset.edit}`);
            openForm(data);
        }

        if (deleteButton) {
            remove(deleteButton.dataset.delete, deleteButton.dataset.name);
        }
    });

    $('#roles-pagination').addEventListener('click', (event) => {
        const button = event.target.closest('[data-page]');
        if (!button) return;

        state.page += button.dataset.page === 'next' ? 1 : -1;
        loadRoles();
    });
}
