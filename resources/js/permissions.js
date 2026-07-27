/**
 * Client-side mirror of AuthorizationService.
 *
 * This only decides what to *show*. Every endpoint is still guarded server-side
 * by the permission middleware, so hiding a button is a convenience, never a
 * security boundary — a hand-crafted request still gets a 403.
 */

let grants = [];

export function setGrants(list) {
    grants = Array.isArray(list) ? list : [];
}

export function can(action, resource) {
    return grants.some((grant) => {
        const [grantAction, grantResource] = grant.split(':');

        return (grantAction === '*' || grantAction.toUpperCase() === action.toUpperCase())
            && (grantResource === '*' || grantResource.toUpperCase() === resource.toUpperCase());
    });
}

/**
 * Hide any element tagged `data-requires-permission="ACTION:RESOURCE"` whose
 * grant the signed-in user does not hold.
 */
export function applyPermissionGates(root = document) {
    root.querySelectorAll('[data-requires-permission]').forEach((el) => {
        const [action, resource] = el.dataset.requiresPermission.split(':');

        el.classList.toggle('hidden', !can(action, resource));
    });
}
