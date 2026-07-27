import auth from '../auth-client';
import { can } from '../permissions';
import {
    $, $$, clearFormErrors, confirmAction, debounce, esc, formatDate,
    hideModal, setSubmitting, showFormErrors, showModal, tableMessage, toast,
} from '../ui';

const COLUMNS = 5;

const STATUS_TONES = {
    active: 'bg-emerald-50 text-emerald-700',
    inactive: 'bg-muted text-secondary-foreground',
    suspended: 'bg-rose-50 text-rose-700',
    pending: 'bg-amber-50 text-amber-700',
};

const state = {
    page: 1,
    perPage: 15,
    search: '',
    status: '',
    roleId: '',
    roles: [],
};

/* -------------------------------------------------------------------------
 | Data
 | ---------------------------------------------------------------------- */

function query() {
    const params = new URLSearchParams({ page: state.page, per_page: state.perPage });

    if (state.search) params.set('search', state.search);
    if (state.status) params.set('status', state.status);
    if (state.roleId) params.set('role_id', state.roleId);

    return params.toString();
}

async function loadRoles() {
    // The role filter and picker need the catalogue; a user with READ:USERS but
    // not READ:ROLES simply gets no role options rather than a broken page.
    if (!can('READ', 'ROLES')) return;

    try {
        const { data } = await auth.call('/roles?per_page=100');
        state.roles = data;

        const options = ['<option value="">All roles</option>']
            .concat(data.map((r) => `<option value="${r.id}">${esc(r.name)}</option>`))
            .join('');

        $('#filter-role').innerHTML = options;
        $('#filter-role').closest('[data-role-filter]')?.classList.remove('hidden');
    } catch {
        // Non-fatal: leave the filter hidden.
    }
}

async function loadUsers() {
    const body = $('#users-body');
    body.innerHTML = tableMessage(COLUMNS, 'Loading users…');

    try {
        const payload = await auth.call(`/users?${query()}`);

        render(payload.data, payload.meta?.pagination);
    } catch (error) {
        body.innerHTML = tableMessage(COLUMNS, error.message, 'error');
        $('#users-pagination').innerHTML = '';
    }
}

/* -------------------------------------------------------------------------
 | Rendering
 | ---------------------------------------------------------------------- */

function render(users, pagination) {
    const body = $('#users-body');

    if (!users.length) {
        body.innerHTML = tableMessage(COLUMNS, 'No users match these filters.');
        $('#users-pagination').innerHTML = '';

        return;
    }

    const mayUpdate = can('UPDATE', 'USERS');
    const mayDelete = can('DELETE', 'USERS');

    body.innerHTML = users.map((user) => {
        const initial = esc((user.name || '?').charAt(0).toUpperCase());
        const tone = STATUS_TONES[user.status] ?? STATUS_TONES.inactive;

        const actions = [
            mayUpdate
                ? `<button type="button" class="btn btn-ghost btn-icon" data-edit="${user.id}" title="Edit user" aria-label="Edit ${esc(user.name)}">${iconPencil}</button>`
                : '',
            mayDelete
                ? `<button type="button" class="btn btn-ghost btn-icon hover:!text-rose-600" data-delete="${user.id}" data-name="${esc(user.name)}" title="Delete user" aria-label="Delete ${esc(user.name)}">${iconTrash}</button>`
                : '',
        ].join('');

        return `
            <tr class="border-t border-border transition hover:bg-secondary/60">
                <td class="table-cell">
                    <div class="flex items-center gap-3">
                        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-accent text-sm font-semibold text-accent-foreground">${initial}</span>
                        <div class="min-w-0">
                            <div class="truncate font-semibold">${esc(user.name)}</div>
                            <div class="truncate text-[0.8125rem] text-muted-foreground">${esc(user.email)}</div>
                        </div>
                    </div>
                </td>
                <td class="table-cell">
                    ${user.role
                        ? `<span class="badge bg-accent text-accent-foreground">${esc(user.role.name)}</span>`
                        : '<span class="text-[0.8125rem] text-muted-foreground">No role</span>'}
                </td>
                <td class="table-cell"><span class="badge ${tone}">${esc(user.status)}</span></td>
                <td class="table-cell text-[0.8125rem] text-muted-foreground">${esc(formatDate(user.last_login_at))}</td>
                <td class="table-cell"><div class="flex justify-end gap-1">${actions || '<span class="text-xs text-muted-foreground">—</span>'}</div></td>
            </tr>`;
    }).join('');

    renderPagination(pagination);
}

function renderPagination(pagination) {
    const host = $('#users-pagination');

    if (!pagination || pagination.last_page <= 1) {
        host.innerHTML = pagination
            ? `<span class="text-[0.8125rem] text-muted-foreground">${pagination.total} user(s)</span>`
            : '';

        return;
    }

    host.innerHTML = `
        <span class="text-[0.8125rem] text-muted-foreground">
            Page ${pagination.current_page} of ${pagination.last_page} · ${pagination.total} user(s)
        </span>
        <div class="flex gap-2">
            <button type="button" class="btn btn-secondary btn-sm" data-page="prev" ${pagination.current_page <= 1 ? 'disabled' : ''}>Previous</button>
            <button type="button" class="btn btn-secondary btn-sm" data-page="next" ${!pagination.has_more ? 'disabled' : ''}>Next</button>
        </div>`;
}

const iconPencil = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M21.17 6.83a2.83 2.83 0 0 0-4-4L3.5 16.5 2 22l5.5-1.5z"/><path d="m15 5 4 4"/></svg>';
const iconTrash = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>';

/* -------------------------------------------------------------------------
 | Create / edit
 | ---------------------------------------------------------------------- */

function roleOptions(selectedId) {
    return ['<option value="">No role</option>']
        .concat(state.roles.map((role) =>
            `<option value="${role.id}" ${String(role.id) === String(selectedId ?? '') ? 'selected' : ''}>${esc(role.name)}</option>`))
        .join('');
}

function openForm(user = null) {
    const form = $('#user-form');
    const editing = user !== null;

    clearFormErrors(form);
    form.reset();

    $('#user-modal-title').textContent = editing ? 'Edit user' : 'New user';
    form.elements.id.value = editing ? user.id : '';
    form.elements.name.value = editing ? user.name : '';
    form.elements.email.value = editing ? user.email : '';
    form.elements.status.value = editing ? user.status : 'active';
    $('#user-role').innerHTML = roleOptions(editing ? user.role?.id : '');

    // Password is required on create, optional on edit ("leave blank to keep").
    form.elements.password.required = !editing;
    form.elements.password.placeholder = editing ? 'Leave blank to keep current' : 'At least 12 characters';
    $('#password-hint').textContent = editing
        ? 'Leave blank to keep the current password.'
        : 'Minimum 12 characters with upper and lower case, a number and a symbol.';

    showModal('#user-modal');
}

/** Client-side pre-check. The API re-validates everything regardless. */
function validate(form, editing) {
    const errors = {};
    const name = form.elements.name.value.trim();
    const email = form.elements.email.value.trim();
    const password = form.elements.password.value;

    if (name.length < 2) errors.name = ['The name must be at least 2 characters.'];
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) errors.email = ['Enter a valid email address.'];

    if (!editing || password !== '') {
        if (password.length < 12) errors.password = ['The password must be at least 12 characters.'];
        else if (!/[a-z]/.test(password) || !/[A-Z]/.test(password) || !/\d/.test(password) || !/[^\w\s]/.test(password)) {
            errors.password = ['Use upper and lower case, a number and a symbol.'];
        }
    }

    return Object.keys(errors).length ? errors : null;
}

async function submitForm(event) {
    event.preventDefault();

    const form = event.target;
    const id = form.elements.id.value;
    const editing = id !== '';

    clearFormErrors(form);

    const errors = validate(form, editing);

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

    const password = form.elements.password.value;
    if (password !== '') payload.password = password;

    setSubmitting(form, true);

    try {
        await auth.call(editing ? `/users/${id}` : '/users', {
            method: editing ? 'PATCH' : 'POST',
            body: payload,
        });

        hideModal('#user-modal');
        toast(editing ? 'User updated.' : 'User created.');
        loadUsers();
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
        title: 'Delete user',
        body: `${name} will lose access immediately and all their sessions will be revoked. This can be undone by restoring the record.`,
        confirmLabel: 'Delete user',
    });

    if (!confirmed) return;

    try {
        await auth.call(`/users/${id}`, { method: 'DELETE' });
        toast('User deleted.');

        // Stepping back a page avoids landing on an empty last page.
        const remaining = $$('#users-body tr').length - 1;
        if (remaining === 0 && state.page > 1) state.page -= 1;

        loadUsers();
    } catch (error) {
        toast(error.message, 'error');
    }
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

export default async function initUsers() {
    await loadRoles();
    await loadUsers();

    $('#new-user')?.addEventListener('click', () => openForm());
    $('#user-form').addEventListener('submit', submitForm);

    $('#filter-search').addEventListener('input', debounce((event) => {
        state.search = event.target.value.trim();
        state.page = 1;
        loadUsers();
    }, 350));

    $('#filter-status').addEventListener('change', (event) => {
        state.status = event.target.value;
        state.page = 1;
        loadUsers();
    });

    $('#filter-role').addEventListener('change', (event) => {
        state.roleId = event.target.value;
        state.page = 1;
        loadUsers();
    });

    $('#users-body').addEventListener('click', async (event) => {
        const editButton = event.target.closest('[data-edit]');
        const deleteButton = event.target.closest('[data-delete]');

        if (editButton) {
            const { data } = await auth.call(`/users/${editButton.dataset.edit}`);
            openForm(data);
        }

        if (deleteButton) {
            remove(deleteButton.dataset.delete, deleteButton.dataset.name);
        }
    });

    $('#users-pagination').addEventListener('click', (event) => {
        const button = event.target.closest('[data-page]');
        if (!button) return;

        state.page += button.dataset.page === 'next' ? 1 : -1;
        loadUsers();
    });
}
