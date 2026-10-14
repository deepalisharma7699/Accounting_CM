/**
 * How this device prints — the paper each document goes on, and whether the
 * thermal printer is driven directly through the print agent.
 *
 * Per **device**, not per workshop, and that is the point of it: the counter PC
 * has the thermal printer beside it and the office PC has an A4 laser, and both
 * print the same sale. A workshop-wide setting would be wrong on one of them
 * every time. So it lives in this browser's storage and never reaches the
 * server — which also means no grant guards it, because it changes nothing but
 * what comes out of the printer on this desk.
 *
 * The one reader of these choices. The Settings card's "This device" section
 * writes them, and `invoice-delivery.js` reads them every time something is
 * printed; nothing else may keep its own copy.
 *
 * Storage can be refused outright (a private window, blocked site data), so
 * every read falls back to the defaults and every write reports whether it
 * stuck. A device that cannot remember simply prints on A4 through the dialog,
 * which is what it did before any of this existed.
 */

const KEY = 'print-settings:v1';

/** The papers a document can go on. `value` is what the sheet's `data-paper` says. */
export const PAPERS = [
    { value: 'a4', label: 'A4 (regular printer)' },
    { value: '80', label: 'Thermal — 80 mm' },
    { value: '58', label: 'Thermal — 58 mm' },
];

/** Where the print agent listens unless this device was told otherwise. */
export const DEFAULT_AGENT_URL = 'http://127.0.0.1:9123';

const DEFAULTS = Object.freeze({
    // The estimate is what a counter sale is handed over as, so it is the one a
    // device with a thermal printer will most often point at it. Both start on
    // A4 because that is what every device can print before it is set up.
    estimate: 'a4',
    invoice: 'a4',
    // 'dialog' is the browser's own print dialog; 'agent' sends ESC/POS to the
    // print agent for thermal paper and falls back to the dialog if it is not
    // answering. A4 always goes through the dialog.
    method: 'dialog',
    agentUrl: DEFAULT_AGENT_URL,
});

const isPaper = (value) => PAPERS.some((paper) => paper.value === value);

/** This device's settings, with anything missing or unrecognised defaulted. */
export function printSettings() {
    let saved = {};

    try {
        saved = JSON.parse(localStorage.getItem(KEY) ?? '{}') ?? {};
    } catch {
        saved = {};
    }

    return {
        estimate: isPaper(saved.estimate) ? saved.estimate : DEFAULTS.estimate,
        invoice: isPaper(saved.invoice) ? saved.invoice : DEFAULTS.invoice,
        method: saved.method === 'agent' ? 'agent' : DEFAULTS.method,
        agentUrl: typeof saved.agentUrl === 'string' && saved.agentUrl.trim() !== ''
            ? saved.agentUrl.trim().replace(/\/+$/, '')
            : DEFAULTS.agentUrl,
    };
}

/**
 * Remember new settings for this device.
 *
 * @returns {boolean} whether the browser kept them — false in a private window
 */
export function savePrintSettings(next) {
    try {
        localStorage.setItem(KEY, JSON.stringify({ ...printSettings(), ...next }));

        return true;
    } catch {
        return false;
    }
}

/** The paper a given document goes on, on this device. */
export function paperFor(as) {
    const settings = printSettings();

    return as === 'estimate' ? settings.estimate : settings.invoice;
}

export const isThermal = (paper) => paper === '80' || paper === '58';
