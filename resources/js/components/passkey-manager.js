/**
 * Sign-in security — the devices that can open this account.
 *
 * Drives partials/security-drawer.blade.php: enrol the device this page is on,
 * list what is already enrolled, rename a row, revoke one.
 *
 * The list is the point of the screen, not the button. Somebody opens this
 * because they have lost a phone or because they want to know what can get in,
 * and the only thing that makes it answerable is that every row is named and
 * dated — which is why enrolment insists on a label rather than defaulting one,
 * and why "last used" is on every row even though nothing acts on it.
 */

import auth from '../auth-client';
import passkeys from '../passkeys';
import { $, confirmAction, esc, formatRelative, toast } from '../ui';

/**
 * A first guess at what this device is called, so the name field is a
 * correction rather than a blank. Deliberately vague — the user agent is not
 * evidence of anything, and a wrong guess the person edits is better than an
 * empty box they skip.
 */
function guessDeviceName() {
    const agent = navigator.userAgent;

    if (/iPhone/i.test(agent)) return 'iPhone';
    if (/iPad/i.test(agent)) return 'iPad';
    if (/Android/i.test(agent)) return 'Android phone';
    if (/Macintosh/i.test(agent)) return 'Mac';
    if (/Windows/i.test(agent)) return 'Windows PC';

    return 'This device';
}

export function mountPasskeyManager(root) {
    const drawer = $('#security-drawer', root);

    if (!drawer) return null;

    const addPanel = $('[data-passkey-add]', drawer);
    const unsupported = $('[data-passkey-unsupported]', drawer);
    const button = $('[data-passkey-enrol]', drawer);
    const list = $('[data-passkey-list]', drawer);
    const error = $('[data-passkey-error]', drawer);
    const nameInput = $('[data-passkey-label-input]', drawer);

    const spinner = $('[data-passkey-spinner]', button);
    const icon = $('[data-passkey-icon]', button);
    const label = $('[data-passkey-label]', button);
    const idleLabel = label.textContent.trim();
    const busyLabel = label.dataset.busy || 'Working…';

    /*
     * Held here rather than refetched on every open (§3.6), and rebuilt after
     * any write — the list is short, the writes all happen on this screen, and
     * nothing else in the application can change it.
     */
    let devices = null;
    let loaded = false;

    const setBusy = (busy) => {
        button.disabled = busy;
        spinner.classList.toggle('hidden', !busy);
        icon.classList.toggle('hidden', busy);
        label.textContent = busy ? busyLabel : idleLabel;
    };

    const showError = (message) => {
        error.textContent = message || '';
        error.classList.toggle('hidden', !message);
    };

    /* -------------------------------------------------------------------- */

    function render() {
        if (devices === null) {
            list.innerHTML = '<p class="py-6 text-center text-[0.8125rem] text-muted-foreground">Loading…</p>';

            return;
        }

        if (devices === false) {
            list.innerHTML = `
                <p class="rounded-[10px] border border-rose-200 bg-rose-50 px-3.5 py-3 text-[0.8125rem] text-rose-700">
                    Your devices could not be loaded. Close this and try again.
                </p>`;

            return;
        }

        if (devices.length === 0) {
            /*
             * Empty is a real state and says so plainly. A blank area here
             * would read as "still loading" on the one screen where being
             * unsure whether anything can open your account is the worst
             * possible outcome.
             */
            list.innerHTML = `
                <p class="rounded-[10px] border border-dashed border-border px-3.5 py-4 text-center
                          text-[0.8125rem] text-muted-foreground">
                    No devices yet. You sign in with your password.
                </p>`;

            return;
        }

        list.innerHTML = devices.map((device) => `
            <div class="mb-2 flex items-start gap-3 rounded-[10px] border border-border px-3.5 py-3"
                 data-passkey-row="${device.id}">
                <span class="mt-0.5 shrink-0 text-muted-foreground">
                    ${device.backed_up ? cloudIcon() : phoneIcon()}
                </span>

                <div class="min-w-0 flex-1">
                    <p data-passkey-name
                       class="truncate text-[0.875rem] font-semibold text-foreground">${esc(device.label)}</p>
                    <p class="mt-0.5 text-[0.75rem] text-muted-foreground">
                        ${device.last_used_at
                            ? `Last used ${esc(formatRelative(device.last_used_at))}`
                            : 'Never used yet'}
                        ${device.backed_up ? ' · synced to your account' : ' · on this device only'}
                    </p>
                </div>

                <div class="flex shrink-0 items-center gap-1">
                    <button type="button" class="btn btn-ghost btn-icon" data-passkey-rename="${device.id}"
                            title="Rename" aria-label="Rename ${esc(device.label)}">
                        ${pencilIcon()}
                    </button>
                    <button type="button" class="btn btn-ghost btn-icon text-rose-600"
                            data-passkey-remove="${device.id}"
                            title="Remove" aria-label="Remove ${esc(device.label)}">
                        ${trashIcon()}
                    </button>
                </div>
            </div>`).join('');
    }

    async function load({ force = false } = {}) {
        if (loaded && !force) return;

        devices = null;
        render();

        try {
            devices = await auth.passkeys();
            loaded = true;
        } catch {
            devices = false;
        }

        render();
    }

    /* -------------------------------------------------------------------- */

    async function enrol() {
        showError('');

        const name = nameInput.value.trim().slice(0, 80);

        if (name === '') {
            showError('Give the device a name, so you can tell it apart later.');
            nameInput.focus();

            return;
        }

        setBusy(true);

        try {
            const { state, options } = await auth.passkeyRegisterOptions();
            const credential = await passkeys.create(options);

            await auth.registerPasskey(state, credential, name);

            toast('This device can now sign you in.');

            // Next enrolment is a different device, so the guess is worth
            // making again rather than leaving the last one in the box.
            nameInput.value = guessDeviceName();

            await load({ force: true });
        } catch (err) {
            if (!passkeys.wasDismissed(err)) {
                showError(err.message || 'That device could not be added.');
            }
        } finally {
            setBusy(false);
        }
    }

    /**
     * Rename in place: the row's name becomes a field, Enter or blur saves it
     * and Escape abandons it.
     *
     * In place rather than in a dialog because a drawer is already level 2, and
     * anything opened over it that is not the delete confirmation would be
     * spending the one level §2.2 leaves.
     */
    function rename(id) {
        const row = $(`[data-passkey-row="${id}"]`, list);
        const device = devices?.find((entry) => String(entry.id) === String(id));

        if (!row || !device || $('input', row)) return;

        const name = $('[data-passkey-name]', row);
        const input = document.createElement('input');

        input.type = 'text';
        input.maxLength = 80;
        input.value = device.label;
        input.className = 'h-8 w-full rounded-md border border-primary bg-card px-2 text-[0.875rem] '
            + 'font-semibold text-foreground focus:outline-none focus:ring-2 focus:ring-ring/60';

        name.replaceWith(input);
        input.focus();
        input.select();

        let settled = false;

        const finish = async (save) => {
            if (settled) return;

            settled = true;

            const value = input.value.trim().slice(0, 80);

            if (!save || value === '' || value === device.label) {
                render();

                return;
            }

            try {
                await auth.renamePasskey(id, value);
                toast('Renamed.');
                await load({ force: true });
            } catch (err) {
                showError(err.message || 'That could not be renamed.');
                render();
            }
        };

        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') finish(true);
            // Stop here, or the shell's Escape handler closes the drawer as
            // well and the cancelled rename takes the whole screen with it.
            if (event.key === 'Escape') {
                event.stopPropagation();
                finish(false);
            }
        });

        input.addEventListener('blur', () => finish(true));
    }

    async function remove(id) {
        const device = devices?.find((row) => String(row.id) === String(id));

        // Destructive, so it goes through confirmAction() and never the
        // browser's confirm() (§3.5).
        const ok = await confirmAction({
            title: 'Remove this device?',
            body: `${device?.label ?? 'This device'} will no longer be able to sign you in. `
                + 'You can add it again later.',
            confirmLabel: 'Remove',
        });

        if (!ok) return;

        try {
            await auth.deletePasskey(id);
            toast('That device can no longer sign you in.');
            await load({ force: true });
        } catch (err) {
            showError(err.message || 'That could not be removed.');
        }
    }

    /* -------------------------------------------------------------------- */

    nameInput.value = guessDeviceName();

    button.addEventListener('click', enrol);

    // Enter in the name field means "add it" — the field exists only to feed
    // the button beneath it.
    nameInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            enrol();
        }
    });

    list.addEventListener('click', (event) => {
        const renameButton = event.target.closest('[data-passkey-rename]');
        const removeButton = event.target.closest('[data-passkey-remove]');

        if (renameButton) rename(renameButton.dataset.passkeyRename);
        if (removeButton) remove(removeButton.dataset.passkeyRemove);
    });

    /*
     * Which of the two panels the browser has earned.
     *
     * Keyed on WebAuthn working at all, deliberately, and not on this machine
     * having a fingerprint reader of its own: a security key, or a phone held
     * up to a laptop and scanned, is a perfectly good passkey. Requiring a
     * platform authenticator would tell somebody at a desktop counter that they
     * cannot do this, when their phone in their pocket is exactly what they
     * would have used.
     */
    const supported = passkeys.isSupported();

    addPanel.classList.toggle('hidden', !supported);
    unsupported.classList.toggle('hidden', supported);

    return { open: () => load() };
}

/* -------------------------------------------------------------------------
 | Icons
 |
 | Inline rather than <x-icon>, because these rows are painted from JavaScript
 | and a Blade component cannot be called from here. Same 24x24 stroke grid as
 | resources/views/components/icon.blade.php.
 | ---------------------------------------------------------------------- */

function svg(paths, extra = '') {
    return `<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
        stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"
        class="shrink-0 ${extra}">${paths}</svg>`;
}

const cloudIcon = () => svg('<path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9"/>');
const phoneIcon = () => svg('<rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/>');
const pencilIcon = () => svg('<path d="M21.17 6.83a2.83 2.83 0 0 0-4-4L3.5 16.5 2 22l5.5-1.5z"/><path d="m15 5 4 4"/>');
const trashIcon = () => svg('<path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>'
    + '<path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M10 11v6"/><path d="M14 11v6"/>');

export default mountPasskeyManager;
