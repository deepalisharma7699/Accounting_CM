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

import { announceWrite } from './data-bus';
import { beginRequest, endRequest } from './loader';

const BASE = '/api/v1';

let accessToken = null;
let refreshPromise = null;

/* -------------------------------------------------------------------------
 | Low level request
 | ---------------------------------------------------------------------- */

async function request(path, { method = 'GET', body, auth = true, credentials = 'same-origin' } = {}) {
    const headers = { Accept: 'application/json' };

    // FormData goes through untouched — M14's uploads. Deliberately *without* a
    // Content-Type header: multipart needs a boundary parameter, and the browser
    // is the only thing that knows the one it is about to generate. Setting the
    // header by hand produces a request the server cannot parse at all, and the
    // symptom is an empty $request->file() rather than an error.
    const isMultipart = typeof FormData !== 'undefined' && body instanceof FormData;

    if (body !== undefined && !isMultipart) {
        headers['Content-Type'] = 'application/json';
    }

    if (auth && accessToken) {
        headers.Authorization = `Bearer ${accessToken}`;
    }

    const response = await fetch(`${BASE}${path}`, {
        method,
        headers,
        credentials,
        body: body === undefined || isMultipart ? body : JSON.stringify(body),
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
 * Sign up a workshop and its owner, and start their session.
 *
 * Registration provisions a tenant and its first user together — there is no
 * user without a workshop — so this returns an authenticated owner exactly as
 * login() does.
 */
export async function register(fields) {
    const { response, payload } = await request('/auth/register', {
        method: 'POST',
        body: fields,
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

/* -------------------------------------------------------------------------
 | Passkeys
 | ---------------------------------------------------------------------- */

/**
 * A challenge to sign in with, naming no account.
 *
 * Unauthenticated, and takes nothing: the whole point of a discoverable
 * credential is that the browser already knows which passkeys it holds for this
 * site, so there is nothing for the page to tell the server first.
 */
export async function passkeyLoginOptions() {
    const { response, payload } = await request('/auth/passkeys/login/options', {
        method: 'POST',
        auth: false,
    });

    if (!response.ok) {
        throw toError(response, payload);
    }

    return payload.data;
}

/**
 * Redeem an assertion for a session. Same shape as login(), deliberately —
 * everything downstream of a started session is identical however it started.
 */
export async function passkeyLogin(state, credential) {
    const { response, payload } = await request('/auth/passkeys/login', {
        method: 'POST',
        body: { state, credential },
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

/** The devices on this account. */
export async function passkeys() {
    return (await call('/auth/passkeys')).data;
}

/**
 * Enrol the device this page is running on.
 *
 * Both halves are authenticated — the ceremony that adds a way into an account
 * is something you do from inside it, never on the way in.
 */
export async function passkeyRegisterOptions() {
    return (await call('/auth/passkeys/options', { method: 'POST' })).data;
}

export async function registerPasskey(state, credential, label) {
    return (await call('/auth/passkeys', { method: 'POST', body: { state, credential, label } })).data;
}

export async function renamePasskey(id, label) {
    return (await call(`/auth/passkeys/${id}`, { method: 'PATCH', body: { label } })).data;
}

export async function deletePasskey(id) {
    await call(`/auth/passkeys/${id}`, { method: 'DELETE' });
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
async function perform(path, options = {}) {
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

    /*
    | Every write in the application comes through here, which is the only
    | reason the announcement is made here and not at the call sites.
    |
    | The screens that hold rows — Stock's shelf, the Items catalogue, a module's
    | level-1 list — are held detached and alive for the life of the tab, so
    | nothing about a write reaches them on its own. A convention that each write
    | site remembers to say what it changed fails silently, one site at a time;
    | this cannot be forgotten because there is nowhere else to write from.
    |
    | It marks screens stale and fetches nothing (`data-bus.js`), so a write in
    | one module still never loads another module's data (§7.2).
    */
    announceWrite(path, options.method ?? 'GET');

    return payload;
}

/**
 * Every authenticated request in the application, with the global activity bar
 * around it.
 *
 * The wrapper is here rather than at the call sites for exactly the reason
 * `announceWrite()` above is: the thing being fixed is a *missing* indicator, and
 * a convention that each caller remembers to raise one fails silently, one
 * caller at a time. There is no way to reach the API without coming through
 * here, so there is no way to be busy without saying so (§3.4).
 *
 * The work is in `perform()` and the counting is in this function, which keeps
 * the retry-on-expiry inside one pair of begin/end: a call that refreshes its
 * token and repeats itself is one wait as far as the person watching is
 * concerned, not two.
 *
 * ## `quiet`
 *
 * Two paths run on a debounce as somebody types — the bill form's price preview
 * and the party and item pickers' search — and firing the bar on each of them
 * strobes the top of the screen through a whole line of typing. They pass
 * `quiet: true` and are silent globally; both already say what they are doing
 * where the eye actually is, in the picker's own list and in the totals panel.
 *
 * It is deliberately not an option anything *else* takes. A caller reaching for
 * it because a spinner looks untidy is removing the only signal that a request
 * exists, and the flag is named to be conspicuous in review.
 */
export async function call(path, options = {}) {
    const { quiet = false, ...rest } = options;

    if (quiet) {
        return perform(path, rest);
    }

    beginRequest();

    try {
        return await perform(path, rest);
    } finally {
        // In a finally, never after the await: a 422 on a save is the single
        // most common way one of these ends, and a bar left up by a rejected
        // promise makes the application look permanently busy from then on.
        endRequest();
    }
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
    register,
    passkeyLoginOptions,
    passkeyLogin,
    passkeys,
    passkeyRegisterOptions,
    registerPasskey,
    renamePasskey,
    deletePasskey,
    logout,
    logoutEverywhere,
    refresh,
    me,
    call,
    isAuthenticated,
    bootstrapSession,
};
