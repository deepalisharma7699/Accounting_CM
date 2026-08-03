import auth from '../auth-client';
import { can } from '../permissions';
import {
    $, $$, clearFormErrors, confirmAction, debounce, esc, formatDate,
    hideModal, setSubmitting, showFormErrors, showModal, tableMessage, toast,
} from '../ui';

const COLUMNS = 6;

const state = {
    page: 1,
    perPage: 15,
    search: '',
    status: '',
};

const STATUS_TONES = {
    active: 'bg-emerald-50 text-emerald-700',
    suspended: 'bg-amber-50 text-amber-700',
    cancelled: 'bg-muted text-muted-foreground',
};

/* -------------------------------------------------------------------------
 | Data
 | ---------------------------------------------------------------------- */

async function loadTenants() {
    const body = $('#tenants-body');
    body.innerHTML = tableMessage(COLUMNS, 'Loading workshops…');

    const params = new URLSearchParams({ page: state.page, per_page: state.perPage });
    if (state.search) params.set('search', state.search);
    if (state.status) params.set('status', state.status);

    try {
        const payload = await auth.call(`/tenants?${params}`);
        render(payload.data, payload.meta?.pagination);
    } catch (error) {
        body.innerHTML = tableMessage(COLUMNS, error.message, 'error');
        $('#tenants-pagination').innerHTML = '';
    }
}

/* -------------------------------------------------------------------------
 | Rendering
 | ---------------------------------------------------------------------- */

function render(tenants, pagination) {
    const body = $('#tenants-body');

    if (!tenants.length) {
        body.innerHTML = tableMessage(COLUMNS, 'No workshops match these filters.');
        $('#tenants-pagination').innerHTML = '';

        return;
    }

    const mayUpdate = can('UPDATE', 'TENANTS');
    const mayDelete = can('DELETE', 'TENANTS');

    body.innerHTML = tenants.map((tenant) => {
        const active = tenant.status === 'active';
        const tone = STATUS_TONES[tenant.status] ?? STATUS_TONES.cancelled;

        return `
            <tr class="border-t border-border transition hover:bg-secondary/60 ${active ? '' : 'opacity-70'}">
                <td class="table-cell">
                    <div class="flex items-center gap-3">
                        <span class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-muted text-secondary-foreground">${iconBuilding}</span>
                        <div class="min-w-0">
                            <div class="truncate font-semibold">${esc(tenant.name)}</div>
                            <div class="truncate font-mono text-[0.75rem] text-muted-foreground">${esc(tenant.slug)}</div>
                        </div>
                    </div>
                </td>

                <td class="table-cell font-mono text-[0.8125rem] text-muted-foreground">
                    ${tenant.gstin ? esc(tenant.gstin) : '—'}
                    ${tenant.state_code ? `<span class="ml-1 text-[0.6875rem]">(${esc(tenant.state_code)})</span>` : ''}
                </td>

                <td class="table-cell"><span class="badge ${tone}">${esc(tenant.status)}</span></td>

                <td class="table-cell text-[0.8125rem] text-muted-foreground" data-users="${tenant.id}">—</td>

                <td class="table-cell text-[0.8125rem] text-muted-foreground">${formatDate(tenant.created_at)}</td>

                <td class="table-cell">
                    <div class="flex justify-end gap-1">
                        ${mayUpdate ? `<button type="button" class="btn btn-ghost btn-icon" data-edit="${tenant.id}" title="Edit workshop" aria-label="Edit workshop">${iconPencil}</button>` : ''}
                        ${mayUpdate ? `<button type="button" class="btn btn-ghost btn-icon ${active ? 'hover:!text-amber-600' : 'hover:!text-emerald-600'}"
                                data-status="${tenant.id}" data-name="${esc(tenant.name)}" data-active="${active ? '1' : '0'}"
                                title="${active ? 'Suspend workshop' : 'Reactivate workshop'}"
                                aria-label="${active ? 'Suspend workshop' : 'Reactivate workshop'}">${active ? iconPause : iconPlay}</button>` : ''}
                        ${mayDelete ? `<button type="button" class="btn btn-ghost btn-icon hover:!text-rose-600" data-delete="${tenant.id}" data-name="${esc(tenant.name)}" title="Delete workshop" aria-label="Delete workshop">${iconTrash}</button>` : ''}
                    </div>
                </td>
            </tr>`;
    }).join('');

    renderPagination(pagination);
    loadUserCounts(tenants);
}

/**
 * User counts come from the single-tenant endpoint, which is the only place
 * that reports them — the list deliberately does not, so paging through a
 * hundred workshops does not run a hundred count queries server-side. Filling
 * them in afterwards keeps the table useful without making the list slow.
 */
function loadUserCounts(tenants) {
    tenants.forEach(async (tenant) => {
        try {
            const { data } = await auth.call(`/tenants/${tenant.id}`);
            const cell = $(`[data-users="${tenant.id}"]`);

            if (cell) cell.textContent = data.user_count ?? '—';
        } catch {
            // A count is decoration; its absence must not break the row.
        }
    });
}

function renderPagination(pagination) {
    const host = $('#tenants-pagination');

    if (!pagination || pagination.last_page <= 1) {
        host.innerHTML = pagination
            ? `<span class="text-[0.8125rem] text-muted-foreground">${pagination.total} workshop(s)</span>`
            : '';

        return;
    }

    host.innerHTML = `
        <span class="text-[0.8125rem] text-muted-foreground">
            Page ${pagination.current_page} of ${pagination.last_page} · ${pagination.total} workshop(s)
        </span>
        <div class="flex gap-2">
            <button type="button" class="btn btn-secondary btn-sm" data-page="prev" ${pagination.current_page <= 1 ? 'disabled' : ''}>Previous</button>
            <button type="button" class="btn btn-secondary btn-sm" data-page="next" ${!pagination.has_more ? 'disabled' : ''}>Next</button>
        </div>`;
}

const iconBuilding = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01M16 6h.01M8 10h.01M16 10h.01M8 14h.01M16 14h.01"/></svg>';
const iconPencil = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M21.17 6.83a2.83 2.83 0 0 0-4-4L3.5 16.5 2 22l5.5-1.5z"/><path d="m15 5 4 4"/></svg>';
const iconTrash = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>';
const iconPause = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/></svg>';
const iconPlay = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="m6 3 14 9-14 9z"/></svg>';

/* -------------------------------------------------------------------------
 | Create / edit
 | ---------------------------------------------------------------------- */

/** Mirrors Tenant::slugFor(): "Sharma Electricals" -> sharma-electricals. */
function slugFor(name) {
    return name.trim().toLowerCase()
        .replace(/[^\p{L}\p{N}]+/gu, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 150);
}

function openForm(tenant = null) {
    const form = $('#tenant-form');
    const editing = tenant !== null;

    clearFormErrors(form);
    form.reset();

    $('#tenant-modal-title').textContent = editing ? 'Edit workshop' : 'New workshop';

    form.elements.id.value = editing ? tenant.id : '';
    form.elements.name.value = editing ? tenant.name : '';
    form.elements.gstin.value = editing ? (tenant.gstin ?? '') : '';
    form.elements.state_code.value = editing ? (tenant.state_code ?? '') : '';
    form.elements.address.value = editing ? (tenant.address ?? '') : '';

    $('#tenant-slug-preview').textContent = editing ? tenant.slug : '—';

    // An existing workshop's people are managed from inside it, not from here.
    $('#tenant-owner-block').classList.toggle('hidden', editing);

    showModal('#tenant-modal');
}

function ownerFields(form) {
    return {
        name: form.elements.owner_name.value.trim(),
        email: form.elements.owner_email.value.trim(),
        password: form.elements.owner_password.value,
    };
}

function validate(form, editing) {
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

    if (!editing) {
        const owner = ownerFields(form);
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
        gstin: form.elements.gstin.value.trim().toUpperCase() || null,
        state_code: form.elements.state_code.value.trim() || null,
        address: form.elements.address.value.trim() || null,
    };

    if (!editing) {
        const owner = ownerFields(form);

        if (owner.name && owner.email && owner.password) payload.owner = owner;
    }

    setSubmitting(form, true);

    try {
        await auth.call(editing ? `/tenants/${id}` : '/tenants', {
            method: editing ? 'PATCH' : 'POST',
            body: payload,
        });

        hideModal('#tenant-modal');
        toast(editing ? 'Workshop updated.' : 'Workshop created.');
        loadTenants();
    } catch (error) {
        showFormErrors(form, mapOwnerErrors(error));
    } finally {
        setSubmitting(form, false);
    }
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

/* -------------------------------------------------------------------------
 | Suspend / reactivate / delete
 | ---------------------------------------------------------------------- */

async function changeStatus(id, name, active) {
    if (active) {
        const confirmed = await confirmAction({
            title: 'Suspend workshop',
            body: `${name} will be locked immediately and everyone inside it signed out. Their books are kept `
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
        loadTenants();
    } catch (error) {
        toast(error.message, 'error');
    }
}

async function remove(id, name) {
    const confirmed = await confirmAction({
        title: 'Delete workshop',
        body: `${name} will be removed. A workshop that still has users cannot be deleted — remove them first, `
            + 'or suspend the workshop instead to keep its books.',
        confirmLabel: 'Delete workshop',
    });

    if (!confirmed) return;

    try {
        await auth.call(`/tenants/${id}`, { method: 'DELETE' });
        toast('Workshop deleted.');

        const remaining = $$('#tenants-body tr').length - 1;
        if (remaining === 0 && state.page > 1) state.page -= 1;

        loadTenants();
    } catch (error) {
        // 409 TENANT_IN_USE lands here with a useful message.
        toast(error.message, 'error');
    }
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

export default async function initTenants() {
    await loadTenants();

    $('#new-tenant')?.addEventListener('click', () => openForm());
    $('#tenant-form').addEventListener('submit', submitForm);

    // Live handle preview, and only while creating — the slug is set once.
    $('#tenant-name').addEventListener('input', (event) => {
        if ($('#tenant-form').elements.id.value !== '') return;

        $('#tenant-slug-preview').textContent = slugFor(event.target.value) || '—';
    });

    // A GSTIN carries its state code in the first two digits, so fill it in
    // and stop the two disagreeing.
    $('#tenant-gstin').addEventListener('input', (event) => {
        const gstin = event.target.value.trim().toUpperCase();

        if (/^[0-9]{2}/.test(gstin)) {
            $('#tenant-state-code').value = gstin.slice(0, 2);
        }
    });

    $('#filter-search').addEventListener('input', debounce((event) => {
        state.search = event.target.value.trim();
        state.page = 1;
        loadTenants();
    }, 350));

    $('#filter-status').addEventListener('change', (event) => {
        state.status = event.target.value;
        state.page = 1;
        loadTenants();
    });

    $('#tenants-body').addEventListener('click', async (event) => {
        const editButton = event.target.closest('[data-edit]');
        const statusButton = event.target.closest('[data-status]');
        const deleteButton = event.target.closest('[data-delete]');

        if (editButton) {
            const { data } = await auth.call(`/tenants/${editButton.dataset.edit}`);
            openForm(data);
        }

        if (statusButton) {
            changeStatus(statusButton.dataset.status, statusButton.dataset.name, statusButton.dataset.active === '1');
        }

        if (deleteButton) {
            remove(deleteButton.dataset.delete, deleteButton.dataset.name);
        }
    });

    $('#tenants-pagination').addEventListener('click', (event) => {
        const button = event.target.closest('[data-page]');
        if (!button) return;

        state.page += button.dataset.page === 'next' ? 1 : -1;
        loadTenants();
    });
}
