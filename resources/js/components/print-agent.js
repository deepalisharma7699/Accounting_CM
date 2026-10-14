/**
 * Talking to the print agent — the small program on the counter PC that hands
 * ESC/POS bytes to the thermal printer and cuts the paper. See
 * tools/print-agent/README.md.
 *
 * Not `auth.call()`, deliberately: that is the application's own API, and it
 * would attach the session's token to a request leaving for another origin. The
 * agent holds no account and needs no credential — it listens on this PC's
 * loopback address only and accepts requests from the application's own origin.
 *
 * Every call has a short timeout. An agent that is not running is the ordinary
 * case on any PC without a thermal printer, and the caller falls back to the
 * print dialog; waiting thirty seconds to find that out would read as a hang.
 */

const TIMEOUT_MS = 4000;

async function request(url, init = {}) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);

    try {
        const response = await fetch(url, { ...init, signal: controller.signal });
        const body = await response.json().catch(() => ({}));

        if (!response.ok || body.ok === false) {
            throw new Error(body.error || `The print agent answered ${response.status}.`);
        }

        return body;
    } catch (error) {
        if (error.name === 'AbortError' || error instanceof TypeError) {
            throw new Error('The print agent is not answering on this PC. Check it is running.');
        }

        throw error;
    } finally {
        clearTimeout(timer);
    }
}

/** Base64 without spreading a large array into one call's arguments. */
function toBase64(bytes) {
    let binary = '';

    for (let i = 0; i < bytes.length; i += 0x8000) {
        binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
    }

    return btoa(binary);
}

/** What the agent is and which printer it is pointed at. */
export function agentStatus(agentUrl) {
    return request(`${agentUrl}/status`);
}

/**
 * Print raw bytes. Resolves once the agent has handed them to the printer.
 *
 * @param {string} agentUrl
 * @param {Uint8Array} bytes
 */
export function agentPrint(agentUrl, bytes) {
    return request(`${agentUrl}/print`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ data: toBase64(bytes) }),
    });
}
