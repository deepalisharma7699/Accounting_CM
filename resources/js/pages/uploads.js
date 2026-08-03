import auth from '../auth-client';
import { progressMarkup, watchJob } from '../job-progress';
import { can } from '../permissions';
import {
    $, confirmAction, debounce, esc, formatDate, tableMessage, toast,
} from '../ui';

/**
 * Stored files — M14.
 *
 * Two lists on one page, and keeping them apart is the whole design. The queue
 * at the top is what is happening *now*; the table below is what the workshop
 * has. A file still travelling is not yet one of their records, and putting it
 * in the library — where it might then fail and vanish — would be worse than a
 * row that never claimed to be there in the first place.
 */

const COLUMNS = 6;

const state = {
    search: '',
    kind: '',
    status: '',
    page: 1,
    kinds: [],
    // One cancel function per row being watched, so leaving the page or
    // reloading the list does not leave pollers running against rows that are
    // no longer on screen.
    watching: new Map(),
};

/* -------------------------------------------------------------------------
 | What may be uploaded
 | ---------------------------------------------------------------------- */

async function loadMeta() {
    const { data } = await auth.call('/attachments/meta');

    state.kinds = data.kinds ?? [];

    $('#filter-kind').insertAdjacentHTML('beforeend', state.kinds
        .map((kind) => `<option value="${esc(kind.value)}">${esc(kind.label)}</option>`).join(''));

    $('#filter-status').insertAdjacentHTML('beforeend', (data.statuses ?? [])
        .map((status) => `<option value="${esc(status.value)}">${esc(status.label)}</option>`).join(''));

    // The picker offers exactly what the server accepts, because both come from
    // the same numbers. A list written into the page would be right until an
    // operator raised a limit, and would then refuse files the API would take.
    $('#upload-input').setAttribute(
        'accept',
        state.kinds.flatMap((kind) => kind.mime_types).join(','),
    );
}

/**
 * Which kind a file belongs to, decided from its own media type.
 *
 * The server checks this again against the bytes and is the authority; this is
 * only so nobody has to choose from a dropdown before they can send a
 * photograph. An unrecognised type is sent as a document and refused with a
 * message that names what is accepted, which is more use than a browser
 * silently declining to open the file picker.
 */
function kindFor(file) {
    const match = state.kinds.find((kind) => kind.mime_types.includes(file.type));

    return match?.value ?? 'document';
}

/* -------------------------------------------------------------------------
 | Uploading
 | ---------------------------------------------------------------------- */

async function upload(file) {
    const row = queueRow(file.name);

    const form = new FormData();
    form.append('file', file);
    form.append('kind', kindFor(file));

    try {
        const payload = await auth.call('/attachments', { method: 'POST', body: form });

        // The response is back as soon as the bytes are stored. Whether they can
        // be read again is a separate claim, and it is the job's to make.
        row.querySelector('[data-queue-state]').innerHTML =
            progressMarkup(payload.meta?.job) || '<span class="text-[0.8125rem] text-muted-foreground">Checking…</span>';

        const duplicates = payload.meta?.duplicates ?? [];

        if (duplicates.length) {
            // Said, never acted on. Photographing one invoice twice is
            // reasonable — the same treatment a shared GSTIN gets on a party.
            toast(`"${file.name}" matches a file you already have. Both have been kept.`, 'info');
        }

        await settle(payload.meta?.job?.id, row, file.name);
    } catch (error) {
        row.querySelector('[data-queue-state]').innerHTML =
            `<span class="text-[0.8125rem] text-rose-600">${esc(error.message)}</span>`;

        toast(error.message, 'error');

        // Left on screen rather than removed: a row that disappeared would leave
        // somebody unsure whether the file went or not.
        setTimeout(() => row.remove(), 8000);
    }
}

function settle(jobId, row, name) {
    return new Promise((resolve) => {
        const finish = (message, tone) => {
            row.remove();

            if (message) toast(message, tone);

            loadList();
            resolve();
        };

        if (!jobId) {
            // Queued nothing — the file is stored but unverified, which is
            // exactly what the row will say once the list reloads.
            finish(`"${name}" was uploaded but could not be checked.`, 'error');

            return;
        }

        watchJob(jobId, {
            onUpdate: (job) => {
                const cell = row.querySelector('[data-queue-state]');

                if (cell) cell.innerHTML = progressMarkup(job);
            },
            onDone: (job) => finish(
                job.status === 'failed'
                    ? `"${name}" was uploaded but could not be confirmed.`
                    : `"${name}" is stored.`,
                job.status === 'failed' ? 'error' : 'success',
            ),
            onError: () => finish(null),
        });
    });
}

function queueRow(name) {
    const host = $('#upload-queue');

    host.classList.remove('hidden');
    host.insertAdjacentHTML('beforeend', `
        <div class="surface flex items-center gap-3 px-4 py-3">
            <span class="text-muted-foreground"><svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 2v4"/><path d="M12 18v4"/>
                <path d="m4.9 4.9 2.9 2.9"/><path d="m16.2 16.2 2.9 2.9"/><path d="M2 12h4"/><path d="M18 12h4"/>
                <path d="m4.9 19.1 2.9-2.9"/><path d="m16.2 7.8 2.9-2.9"/></svg></span>
            <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-medium">${esc(name)}</span>
                <span class="mt-0.5 block" data-queue-state>
                    <span class="text-[0.8125rem] text-muted-foreground">Sending…</span>
                </span>
            </span>
        </div>`);

    return host.lastElementChild;
}

/* -------------------------------------------------------------------------
 | The library
 | ---------------------------------------------------------------------- */

async function loadList() {
    // Stop watching anything from the previous render before it leaves the DOM.
    state.watching.forEach((stop) => stop());
    state.watching.clear();

    const body = $('#upload-rows');
    body.innerHTML = tableMessage(COLUMNS, 'Loading your files…');

    const params = new URLSearchParams({ page: state.page, per_page: 25 });

    if (state.search) params.set('search', state.search);
    if (state.kind) params.set('kind', state.kind);
    if (state.status) params.set('status', state.status);

    try {
        const payload = await auth.call(`/attachments?${params}`);

        render(payload.data, payload.meta?.pagination);
    } catch (error) {
        $('#upload-summary').textContent = '';

        body.innerHTML = error.code === 'NO_WORKSPACE'
            ? tableMessage(COLUMNS, 'Your account administers the platform rather than a single workshop, so it stores no files of its own.')
            : tableMessage(COLUMNS, error.message, 'error');
    }
}

function render(rows, pagination) {
    const body = $('#upload-rows');

    body.innerHTML = rows.length
        ? rows.map(row).join('')
        : tableMessage(COLUMNS, 'No files yet. Photograph an invoice and it will appear here.');

    const total = pagination?.total ?? rows.length;

    $('#upload-summary').textContent = total ? `${total} file${total === 1 ? '' : 's'}` : '';
    $('#page-prev').disabled = (pagination?.current_page ?? 1) <= 1;
    $('#page-next').disabled = !(pagination?.has_more ?? false);

    // Anything still being checked keeps updating in place rather than making
    // somebody reload to find out.
    rows.filter((file) => file.status === 'pending' && file.job?.id)
        .forEach((file) => state.watching.set(file.id, watchJob(file.job.id, {
            onDone: () => loadList(),
            onError: () => {},
        })));
}

function row(file) {
    return `
        <tr class="border-t border-border transition hover:bg-secondary/60">
            <td class="table-cell">
                <span class="font-medium">${esc(file.name)}</span>
                ${file.error ? `<div class="mt-0.5 text-[0.8125rem] text-rose-600">${esc(file.error)}</div>` : ''}
            </td>
            <td class="table-cell w-40 text-[0.8125rem] text-muted-foreground">${esc(file.kind_label)}</td>
            <td class="table-cell w-28 font-mono text-[0.8125rem]">${esc(file.size_label)}</td>
            <td class="table-cell w-32">${statusBadge(file)}</td>
            <td class="table-cell w-44 text-[0.8125rem] text-muted-foreground">
                ${esc(formatDate(file.created_at))}
                ${file.uploaded_by ? `<div class="mt-0.5 text-xs">${esc(file.uploaded_by)}</div>` : ''}
            </td>
            <td class="table-cell w-40 text-right">
                <a class="btn btn-ghost btn-sm" href="${esc(file.download_url)}">Download</a>
                ${can('DELETE', 'ATTACHMENTS')
                    ? `<button type="button" class="btn btn-ghost btn-sm text-rose-600"
                               data-delete="${file.id}" data-name="${esc(file.name)}">Delete</button>`
                    : ''}
            </td>
        </tr>`;
}

function statusBadge(file) {
    const tone = {
        ready: 'bg-emerald-50 text-emerald-700 border-emerald-200',
        pending: 'bg-secondary text-secondary-foreground border-border',
        failed: 'bg-rose-50 text-rose-700 border-rose-200',
    }[file.status] ?? 'bg-secondary text-secondary-foreground border-border';

    return `<span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${tone}">
        ${esc(file.status_label)}
    </span>`;
}

/* -------------------------------------------------------------------------
 | Removing
 | ---------------------------------------------------------------------- */

async function remove(id, name) {
    const confirmed = await confirmAction({
        title: `Delete "${name}"?`,
        // Said plainly. Unlike an archived party or account, a deleted file
        // leaves nothing behind — which is also why the removal is recorded on
        // the history screen.
        body: 'The file is removed permanently and cannot be recovered. The deletion is recorded in your history.',
        confirmLabel: 'Delete',
    });

    if (!confirmed) return;

    try {
        const payload = await auth.call(`/attachments/${id}`, { method: 'DELETE' });

        toast(payload.message ?? 'Deleted.');
        loadList();
    } catch (error) {
        toast(error.message, 'error');
    }
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

export default async function initUploads() {
    try {
        await loadMeta();
    } catch {
        // Without meta the filters stay empty and every file is sent as a
        // document, which the server checks anyway.
    }

    await loadList();

    $('#upload-input').addEventListener('change', (event) => {
        const files = Array.from(event.target.files ?? []);

        // Reset first, so choosing the same file twice in a row still fires.
        event.target.value = '';

        // Sequentially rather than all at once: several large photographs in
        // parallel on a workshop's connection make every one of them slower, and
        // the progress display stops meaning anything.
        files.reduce((chain, file) => chain.then(() => upload(file)), Promise.resolve());
    });

    $('#filter-search').addEventListener('input', debounce((event) => {
        state.search = event.target.value.trim();
        state.page = 1;
        loadList();
    }));

    [['#filter-kind', 'kind'], ['#filter-status', 'status']].forEach(([selector, key]) => {
        $(selector).addEventListener('change', (event) => {
            state[key] = event.target.value;
            state.page = 1;
            loadList();
        });
    });

    $('#upload-rows').addEventListener('click', (event) => {
        const button = event.target.closest('[data-delete]');

        if (button) remove(button.dataset.delete, button.dataset.name);
    });

    $('#page-prev').addEventListener('click', () => {
        if (state.page > 1) {
            state.page -= 1;
            loadList();
        }
    });

    $('#page-next').addEventListener('click', () => {
        state.page += 1;
        loadList();
    });
}
