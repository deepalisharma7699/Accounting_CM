import auth from '../auth-client';
import {
    $, debounce, esc, formatDate, tableMessage,
} from '../ui';

/**
 * The trail — M13.
 *
 * One list, filtered. There is no per-record view and no detail modal: an entry
 * *is* its detail, so the changed fields are shown inline on the row that
 * describes them. A modal would put one click between somebody and the only
 * thing they came to read.
 */

const PAGE_SIZE = 50;

const state = {
    search: '',
    resource: '',
    action: '',
    actorId: '',
    from: '',
    to: '',
    page: 1,
    // Whether this workshop has any history at all, from meta. It is what tells
    // an empty table apart from a filter that matched nothing — the two look
    // identical and mean completely different things.
    hasAny: false,
};

/* -------------------------------------------------------------------------
 | Filters
 | ---------------------------------------------------------------------- */

async function loadMeta() {
    const { data, meta } = await auth.call('/audit-logs/meta');

    state.hasAny = (meta?.total ?? 0) > 0;

    fill('#filter-resource', data.resources, (row) => [row.value, row.label]);
    fill('#filter-action', data.actions, (row) => [row.value, row.label]);
    fill('#filter-actor', data.actors, (row) => [
        row.id ?? 'system',
        `${row.name} · ${row.entries} change${row.entries === 1 ? '' : 's'}`,
    ]);
}

function fill(selector, rows, mapper) {
    const select = $(selector);

    select.insertAdjacentHTML('beforeend', (rows ?? []).map((row) => {
        const [value, label] = mapper(row);

        return `<option value="${esc(String(value))}">${esc(label)}</option>`;
    }).join(''));
}

function query() {
    const params = new URLSearchParams();

    if (state.search) params.set('search', state.search);
    if (state.resource) params.set('resource', state.resource);
    if (state.action) params.set('action', state.action);
    // "system" is the entries with nobody behind them — a console command or a
    // seeder. It has no id, so it cannot be filtered server-side; selecting it
    // simply clears the filter rather than pretending to narrow.
    if (state.actorId && state.actorId !== 'system') params.set('actor_id', state.actorId);
    if (state.from) params.set('from', state.from);
    if (state.to) params.set('to', state.to);

    params.set('per_page', PAGE_SIZE);
    params.set('page', state.page);

    return params;
}

/* -------------------------------------------------------------------------
 | Loading
 | ---------------------------------------------------------------------- */

async function load() {
    $('#audit-rows').innerHTML = tableMessage(4, 'Loading the history…');

    try {
        const payload = await auth.call(`/audit-logs?${query()}`);

        render(payload.data, payload.meta);
    } catch (error) {
        $('#audit-summary').textContent = '';

        // A platform super-admin holds every grant and owns no books. Their
        // request is well formed; there is simply nothing to show them.
        $('#audit-rows').innerHTML = error.code === 'NO_WORKSPACE'
            ? tableMessage(4, 'Your account administers the platform rather than a single workshop, so it has no history of its own.')
            : tableMessage(4, error.message, 'error');
    }
}

function render(rows, meta) {
    const body = $('#audit-rows');

    if (!rows.length) {
        body.innerHTML = tableMessage(
            4,
            state.hasAny
                ? 'Nothing matches those filters.'
                : 'Nothing has been changed yet. Edits to your accounts, parties, catalogue and settings will appear here.',
        );
    } else {
        body.innerHTML = rows.map(row).join('');
    }

    const total = meta?.pagination?.total ?? rows.length;

    $('#audit-summary').textContent = total
        ? `${total} change${total === 1 ? '' : 's'}`
        : '';

    $('#page-prev').disabled = (meta?.pagination?.current_page ?? 1) <= 1;
    $('#page-next').disabled = !(meta?.pagination?.has_more ?? false);
}

function row(entry) {
    return `
        <tr class="border-t border-border align-top transition hover:bg-secondary/60">
            <td class="table-cell w-40 whitespace-nowrap text-[0.8125rem] text-muted-foreground">
                ${esc(formatDate(entry.at))}
                <div class="mt-0.5 text-xs">${esc(timeOf(entry.at))}</div>
            </td>

            <td class="table-cell">
                <span class="font-medium">${esc(entry.label)}</span>
                <div class="mt-0.5 text-[0.8125rem] text-muted-foreground">
                    ${esc(entry.resource_label)}
                    ${entry.resource_route
                        ? ` · <a class="underline underline-offset-2 hover:text-foreground" href="${esc(entry.resource_route)}">open</a>`
                        : ''}
                </div>
            </td>

            <td class="table-cell">
                ${badge(entry.action, entry.action_label)}
                ${changes(entry)}
            </td>

            <td class="table-cell w-48 text-[0.8125rem]">
                ${esc(entry.actor.name)}
                ${entry.actor.email ? `<div class="mt-0.5 text-xs text-muted-foreground">${esc(entry.actor.email)}</div>` : ''}
                ${entry.actor.id && !entry.actor.exists
                    ? '<div class="mt-0.5 text-xs text-muted-foreground">no longer has an account</div>'
                    : ''}
            </td>
        </tr>`;
}

/**
 * Archiving and deleting are coloured differently from an ordinary edit,
 * because they are the two that remove something from every list in the
 * product — which is the change somebody is usually here to find.
 */
function badge(action, label) {
    const tone = {
        created: 'bg-emerald-50 text-emerald-700 border-emerald-200',
        updated: 'bg-secondary text-secondary-foreground border-border',
        archived: 'bg-amber-50 text-amber-700 border-amber-200',
        restored: 'bg-sky-50 text-sky-700 border-sky-200',
        deleted: 'bg-rose-50 text-rose-700 border-rose-200',
    }[action] ?? 'bg-secondary text-secondary-foreground border-border';

    return `<span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${tone}">${esc(label)}</span>`;
}

function changes(entry) {
    if (!entry.changes?.length) {
        // A creation carries no snapshot by design — the record itself is one.
        return '';
    }

    return `
        <dl class="mt-2 space-y-1 text-[0.8125rem]">
            ${entry.changes.map((change) => `
                <div class="flex flex-wrap items-baseline gap-x-2">
                    <dt class="text-muted-foreground">${esc(fieldLabel(change.field))}</dt>
                    <dd class="font-mono text-xs">
                        <span class="text-muted-foreground line-through">${esc(display(change.from))}</span>
                        <span class="mx-1 text-muted-foreground">→</span>
                        <span class="font-medium">${esc(display(change.to))}</span>
                    </dd>
                </div>`).join('')}
        </dl>`;
}

function fieldLabel(field) {
    return field.replace(/_/g, ' ').replace(/^./, (character) => character.toUpperCase());
}

/**
 * "empty" rather than a blank cell. A change from nothing to something and a
 * change the screen failed to render look identical when both sides are blank,
 * and only one of them is a bug.
 */
function display(value) {
    if (value === null || value === undefined || value === '') return 'empty';
    if (value === true) return 'yes';
    if (value === false) return 'no';
    if (Array.isArray(value)) return value.length ? value.join(', ') : 'none';

    return String(value);
}

function timeOf(iso) {
    if (!iso) return '';

    const at = new Date(iso);

    return Number.isNaN(at.getTime())
        ? ''
        : at.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

export default async function initAudit() {
    try {
        await loadMeta();
    } catch {
        // Without meta the dropdowns stay empty; the list below still loads and
        // reports its own failure if it has one.
    }

    await load();

    $('#filter-search').addEventListener('input', debounce((event) => {
        state.search = event.target.value.trim();
        state.page = 1;
        load();
    }));

    [
        ['#filter-resource', 'resource'],
        ['#filter-action', 'action'],
        ['#filter-actor', 'actorId'],
        ['#filter-from', 'from'],
        ['#filter-to', 'to'],
    ].forEach(([selector, key]) => {
        $(selector).addEventListener('change', (event) => {
            state[key] = event.target.value;
            state.page = 1;
            load();
        });
    });

    $('#clear-filters').addEventListener('click', () => {
        Object.assign(state, { search: '', resource: '', action: '', actorId: '', from: '', to: '', page: 1 });

        ['search', 'resource', 'action', 'actor', 'from', 'to']
            .forEach((field) => { $(`#filter-${field}`).value = ''; });

        load();
    });

    $('#page-prev').addEventListener('click', () => {
        if (state.page > 1) {
            state.page -= 1;
            load();
        }
    });

    $('#page-next').addEventListener('click', () => {
        state.page += 1;
        load();
    });
}
