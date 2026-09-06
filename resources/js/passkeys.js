/**
 * The browser half of a WebAuthn ceremony.
 *
 * Nothing in here decides anything. It converts between the JSON the API
 * speaks and the ArrayBuffers `navigator.credentials` insists on, asks the
 * device, and converts the answer back. Every security question — is this
 * challenge current, is this origin right, is this credential yours — is
 * answered on the server, because a check that runs in the page is a check an
 * attacker controls.
 *
 * The conversion is the whole of it, and it is the part that silently fails:
 * the API sends base64url strings, the browser API takes and returns binary,
 * and a mismatch shows up as a device prompt that appears normally and then
 * produces a signature the server cannot verify.
 */

/* -------------------------------------------------------------------------
 | base64url
 | ---------------------------------------------------------------------- */

function toBuffer(value) {
    const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
    const padded = base64.padEnd(base64.length + ((4 - (base64.length % 4)) % 4), '=');
    const binary = atob(padded);
    const bytes = new Uint8Array(binary.length);

    for (let i = 0; i < binary.length; i += 1) {
        bytes[i] = binary.charCodeAt(i);
    }

    return bytes.buffer;
}

function toBase64Url(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';

    // A chunk at a time: String.fromCharCode(...bytes) blows the argument
    // limit on the larger attestation objects, which is a crash on exactly the
    // authenticators that send the most data.
    for (let i = 0; i < bytes.length; i += 0x8000) {
        binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
    }

    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

/* -------------------------------------------------------------------------
 | Support
 | ---------------------------------------------------------------------- */

export function isSupported() {
    return typeof window.PublicKeyCredential === 'function'
        && typeof navigator.credentials?.create === 'function';
}

/**
 * Whether the browser can offer passkeys from inside the email field's own
 * autofill dropdown, rather than only from a button.
 */
export async function hasConditionalMediation() {
    if (!isSupported()) return false;

    try {
        return await window.PublicKeyCredential.isConditionalMediationAvailable?.() ?? false;
    } catch {
        return false;
    }
}

/* -------------------------------------------------------------------------
 | The ceremonies
 | ---------------------------------------------------------------------- */

/**
 * Ask the device to create a passkey.
 *
 * @param {object} options The API's `options` object, verbatim.
 * @returns {Promise<object>} The credential, ready to post back as JSON.
 */
export async function create(options) {
    const credential = await navigator.credentials.create({
        publicKey: {
            ...options,
            challenge: toBuffer(options.challenge),
            user: { ...options.user, id: toBuffer(options.user.id) },
            excludeCredentials: (options.excludeCredentials ?? []).map(withBufferId),

            // The serializer writes `authenticatorAttachment: null` for "no
            // preference". Firefox rejects the null outright, and the key it is
            // inside is optional, so the honest encoding of "no preference" is
            // for it not to be there.
            authenticatorSelection: withoutNulls(options.authenticatorSelection),
        },
    });

    if (!credential) {
        throw new Error('No passkey was created.');
    }

    return {
        id: credential.id,
        rawId: toBase64Url(credential.rawId),
        type: credential.type,
        response: {
            clientDataJSON: toBase64Url(credential.response.clientDataJSON),
            attestationObject: toBase64Url(credential.response.attestationObject),
        },
    };
}

/**
 * Ask the device for an assertion.
 *
 * @param {object} options The API's `options` object, verbatim.
 * @param {object} [extra]
 * @param {AbortSignal} [extra.signal] Cancels the prompt — see the note in app.js
 *   about only one ceremony being allowed in flight at a time.
 * @param {'optional'|'conditional'} [extra.mediation] `conditional` puts
 *   passkeys in the email field's autofill list instead of opening a dialog.
 * @returns {Promise<object>} The assertion, ready to post back as JSON.
 */
export async function get(options, { signal, mediation } = {}) {
    const assertion = await navigator.credentials.get({
        publicKey: {
            ...options,
            challenge: toBuffer(options.challenge),
            allowCredentials: (options.allowCredentials ?? []).map(withBufferId),
        },
        ...(signal ? { signal } : {}),
        ...(mediation ? { mediation } : {}),
    });

    if (!assertion) {
        throw new Error('No passkey was offered.');
    }

    return {
        id: assertion.id,
        rawId: toBase64Url(assertion.rawId),
        type: assertion.type,
        response: {
            clientDataJSON: toBase64Url(assertion.response.clientDataJSON),
            authenticatorData: toBase64Url(assertion.response.authenticatorData),
            signature: toBase64Url(assertion.response.signature),

            /*
             * Which account the device says this is. Null is sent as null
             * rather than dropped, because the server distinguishes "the device
             * did not say" from "the field is absent" — and the first is a
             * refusal, not a shrug.
             */
            userHandle: assertion.response.userHandle
                ? toBase64Url(assertion.response.userHandle)
                : null,
        },
    };
}

/**
 * True when the person dismissed the prompt, rather than anything failing.
 *
 * Worth telling apart: closing the sheet is a decision, and answering it with a
 * red error banner tells somebody who chose to use their password instead that
 * something is broken.
 */
export function wasDismissed(error) {
    return error?.name === 'NotAllowedError' || error?.name === 'AbortError';
}

function withBufferId(descriptor) {
    return { ...descriptor, id: toBuffer(descriptor.id) };
}

function withoutNulls(value) {
    if (!value) return undefined;

    return Object.fromEntries(Object.entries(value).filter(([, v]) => v !== null && v !== undefined));
}

export default {
    isSupported,
    hasConditionalMediation,
    create,
    get,
    wasDismissed,
};
