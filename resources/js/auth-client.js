/**
 * Browser client for the /api/v1 auth module.
 *
 * The access token is held in a module-scoped variable and never written to
 * localStorage or sessionStorage — anything readable by JavaScript is readable
 * by injected JavaScript. On a full page load there is no token in memory, so
 * the client silently redeems the HttpOnly refresh cookie to mint a new one.
 *
 * The refresh cookie is scoped to /api/v1/auth and SameSite=Strict, so requests
 * that need it must be same-origin and sent with credentials.
 */

const BASE = '/api/v1';

let accessToken = null;
let refreshPromise = null;

/* -------------------------------------------------------------------------
 | Low level request
 | ---------------------------------------------------------------------- */

async function request(path, { method = 'GET', body, auth = true, credentials = 'same-origin' } = {}) {
    const headers = { Accept: 'application/json' };

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    if (auth && accessToken) {
        headers.Authorization = `Bearer ${accessToken}`;
    }

    const response = await fetch(`${BASE}${path}`, {
        method,
        headers,
        credentials,
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    // 204s and empty bodies still need to resolve to something.
    const payload = response.status === 204 ? null : await response.json().catch(() => null);

    return { response, payload };
}

/**
 * The API's error envelope is always
 *   { success: false, error: { code, message, details } }
 */
function toError(response, payload) {
    const error = new Error(payload?.error?.message ?? 'Request failed.');

    error.status = response.status;
    error.code = payload?.error?.code ?? 'UNKNOWN';
    error.details = payload?.error?.details ?? {};
    error.fields = payload?.error?.details?.fields ?? null;

    return error;
}

/* -------------------------------------------------------------------------
 | Session
 | ---------------------------------------------------------------------- */

export async function login(email, password) {
    const { response, payload } = await request('/auth/login', {
        method: 'POST',
        body: { email, password },
        auth: false,
        // Required so the browser stores the Set-Cookie refresh token.
        credentials: 'include',
    });

    if (!response.ok) {
        throw toError(response, payload);
    }

    accessToken = payload.data.access_token;

    return payload.data.user;
}

/**
 * Perform the actual exchange. Never call this directly — go through refresh(),
 * which serialises callers.
 */
async function exchangeRefreshCookie() {
    const { response, payload } = await request('/auth/refresh', {
        method: 'POST',
        auth: false,
        credentials: 'include',
    });

    if (!response.ok) {
        accessToken = null;
        throw toError(response, payload);
    }

    accessToken = payload.data.access_token;

    return payload.data.user;
}

/**
 * Redeem the refresh cookie for a new access token.
 *
 * Refresh tokens rotate, and replaying an already-rotated one is treated as a
 * leak that revokes the whole session family. Two refreshes racing each other
 * therefore log the user out — so they are serialised at two levels:
 *
 *   1. `refreshPromise` — concurrent callers within one page share one request.
 *   2. Web Locks — tabs take turns. Whoever waits then redeems the *rotated*
 *      cookie the first tab just stored, which is valid, so nothing looks like
 *      a replay. This keeps reuse detection strict rather than adding a
 *      server-side grace window that would accept a spent token.
 *
 * Browsers without the Web Locks API (Safari < 15.4) fall back to level 1 only.
 */
export function refresh() {
    if (refreshPromise) {
        return refreshPromise;
    }

    refreshPromise = (async () => {
        try {
            if (navigator.locks?.request) {
                return await navigator.locks.request('auth:refresh', exchangeRefreshCookie);
            }

            return await exchangeRefreshCookie();
        } finally {
            refreshPromise = null;
        }
    })();

    return refreshPromise;
}

export async function logout() {
    await request('/auth/logout', { method: 'POST', auth: false, credentials: 'include' });

    accessToken = null;
}

export async function logoutEverywhere() {
    await request('/auth/logout-all', { method: 'POST', credentials: 'include' });

    accessToken = null;
}

export function me() {
    return call('/auth/me');
}

export function isAuthenticated() {
    return accessToken !== null;
}

/* -------------------------------------------------------------------------
 | Authenticated calls
 | ---------------------------------------------------------------------- */

/**
 * Authenticated request that transparently refreshes once on expiry.
 *
 * Only AUTH_TOKEN_EXPIRED is retried. A revoked, reused or structurally invalid
 * token means the session is genuinely over, and retrying would just rotate
 * another token for an attacker.
 */
export async function call(path, options = {}) {
    if (!accessToken) {
        await refresh();
    }

    let { response, payload } = await request(path, options);

    if (response.status === 401 && payload?.error?.code === 'AUTH_TOKEN_EXPIRED') {
        await refresh();
        ({ response, payload } = await request(path, options));
    }

    if (!response.ok) {
        throw toError(response, payload);
    }

    return payload;
}

/**
 * Restore a session on page load. Resolves with the user, or null when there is
 * no usable refresh cookie — the caller decides whether to redirect to /login.
 */
export async function bootstrapSession() {
    try {
        return await refresh();
    } catch {
        return null;
    }
}

export default {
    login,
    logout,
    logoutEverywhere,
    refresh,
    me,
    call,
    isAuthenticated,
    bootstrapSession,
};
