import { $$, esc } from '../ui';

/**
 * The permission matrix — the tick-boxes that say what a role may do.
 *
 * Two screens ask the same question and must not answer it twice (§5.1). The
 * Roles module draws it for the caller's own scope, and the Workshops drawer
 * draws it for the workshop it has open — same markup, same grouping, same
 * treatment of the full-access grant, different host node and different
 * catalogue endpoint.
 *
 * The catalogue itself is **never** written into this file or into a template.
 * It comes from `GET …/permissions?grouped=1`, which grows with every module
 * that gets built and, inside a workshop, is already narrowed to what that
 * workshop's roles may be given — so nobody is offered a tick the API would
 * refuse. A copy here would be a list of grants that quietly stopped matching
 * the ones the middleware checks.
 */

/**
 * Fill `host` with one fieldset per resource.
 *
 * @param {HTMLElement} host
 * @param {Record<string, Array<{id: number, action: string, description: string|null}>>} grouped
 * @param {Array<number|string>} selectedIds
 * @param {{ mayRead?: boolean }} options  `mayRead: false` explains the absence
 *                                         rather than reporting a failure.
 */
export function renderPermissionMatrix(host, grouped, selectedIds = [], { mayRead = true } = {}) {
    const resources = Object.keys(grouped ?? {});

    if (!resources.length) {
        host.innerHTML = `<p class="text-[0.8125rem] text-muted-foreground">${
            mayRead
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
    | own labelled block, away from the ordinary per-resource grants. A
    | workshop's catalogue never contains it; the platform's does.
    */
    const wildcard = resources.includes('*')
        ? `<fieldset class="rounded-[10px] border border-amber-200 bg-amber-50/60 p-3">
               <legend class="px-1 text-[0.6875rem] font-semibold uppercase tracking-wider text-amber-700">
                   Full access
               </legend>
               <div class="mt-1 space-y-1.5">
                   ${grouped['*'].map((permission) => `
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
                ${grouped[resource].map((permission) => `
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

/**
 * The ticked grants inside `scope`, as numbers ready to send.
 *
 * @param {HTMLElement} scope
 * @returns {Array<number>}
 */
export function checkedPermissionIds(scope) {
    return $$('input[name="permission_ids"]:checked', scope).map((input) => Number(input.value));
}
