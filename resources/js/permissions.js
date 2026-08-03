/**
 * Client-side mirror of AuthorizationService.
 *
 * This only decides what to *show*. Every endpoint is still guarded server-side
 * by the permission middleware, so hiding a button is a convenience, never a
 * security boundary — a hand-crafted request still gets a 403.
 */

let grants = [];
let tenantId = null;

export function setGrants(list) {
    grants = Array.isArray(list) ? list : [];
}

/**
 * Which workshop the signed-in user belongs to, or null for a platform
 * super-admin.
 *
 * Permissions and tenancy are separate gates. A platform admin holds the `*`/`*`
 * wildcard, so a permission check alone would happily show them "Accounting" —
 * and then every request behind it fails, because they have no books. Authority
 * is not membership.
 */
export function setWorkspace(id) {
    tenantId = id ?? null;
}

export function hasWorkspace() {
    return tenantId !== null;
}

export function can(action, resource) {
    return grants.some((grant) => {
        const [grantAction, grantResource] = grant.split(':');

        return (grantAction === '*' || grantAction.toUpperCase() === action.toUpperCase())
            && (grantResource === '*' || grantResource.toUpperCase() === resource.toUpperCase());
    });
}

/**
 * Hide any element the signed-in user should not see:
 *
 *   data-requires-permission="ACTION:RESOURCE"  — needs that grant
 *   data-requires-workspace                     — needs to belong to a workshop
 *
 * Both gates apply independently, and an element carrying both must satisfy
 * both.
 */
export function applyPermissionGates(root = document) {
    root.querySelectorAll('[data-requires-permission], [data-requires-workspace]').forEach((el) => {
        const grant = el.dataset.requiresPermission;
        const [action, resource] = grant ? grant.split(':') : [];

        const allowed = (!grant || can(action, resource))
            && (el.dataset.requiresWorkspace === undefined || hasWorkspace());

        el.classList.toggle('hidden', !allowed);
    });
}
