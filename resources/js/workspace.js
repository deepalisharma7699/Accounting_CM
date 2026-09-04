/**
 * The level-1 workspace — CLAUDE.md §2A.
 *
 * The shape every module has:
 *
 *     card click → CREATE FORM
 *                → "Show list" beside the heading → the form is REPLACED by the table
 *                → row → drawer (level 2) → confirm (level 3)
 *
 * This file is that shape, built once so every module inherits it. A module
 * supplies two surfaces — `[data-ws-form]` and `[data-ws-list]` in its markup —
 * and the copy that names them; everything about how they swap, what the switch
 * control says and where the user lands is decided here. There is deliberately
 * no way to write a per-module variant of it.
 *
 * Three rules do most of the work:
 *
 * - **The form and the list are alternatives, never siblings** (§2A.2). The one
 *   not in use is *detached*, not hidden — so a stray `querySelector` cannot
 *   reach into the surface that is off screen, and the accessibility tree only
 *   ever contains what is actually on it.
 * - **One switch control, in one slot, naming its destination** (§2A.3). "Show
 *   list" on the form, "Create item" on the list. Never "Hide": that strands
 *   somebody on a table with no visible route back.
 * - **Nothing is thrown away by a swap.** Both surfaces keep their DOM, so the
 *   half-typed draft and the list's search and filters survive every trip
 *   between them, and back out to the grid and in again (§2A.6, §3.6).
 */

import { registerEscape } from './shell';
import { $, esc } from './ui';

/**
 * Turn a mounted module root into a level-1 workspace.
 *
 * @param {HTMLElement} root  the module's mounted root
 * @param {object} config
 * @param {string} config.key          module key, as the shell knows it
 * @param {string} config.title        the heading
 * @param {string} config.formSubtitle the line under it, on the form
 * @param {(count: number|null) => string} config.listSubtitle  ditto, on the list
 * @param {string} config.createLabel  "Create item" — the control shown on the list
 * @param {() => number|null} config.count  what the Show control's badge says
 * @param {boolean} config.canCreate   false for a user without the write grant
 * @param {() => Promise<void>} config.onShowList  run the first time the list is shown
 * @param {() => void} config.onShowForm           run whenever the form is shown
 */
export function mountWorkspace(root, {
    key,
    title,
    formSubtitle,
    listSubtitle,
    createLabel,
    count = () => null,
    canCreate = true,
    onShowList = async () => {},
    onShowForm = () => {},
}) {
    const form = $('[data-ws-form]', root);
    const list = $('[data-ws-list]', root);

    /*
    | The frame is built where the two surfaces already were, not at the top of
    | the module root — otherwise the heading would sit outside whatever wrapper
    | the module's markup uses to constrain its width, and the workspace would be
    | a different width from the module inside it.
    */
    const container = (form ?? list).parentElement;

    const header = document.createElement('div');
    header.className = 'ws-header';
    container.prepend(header);

    const surface = document.createElement('div');
    container.insertBefore(surface, header.nextSibling);

    /*
    | Both surfaces come out of the document up front.
    |
    | `setMode` only ever *adds* one back, so without this the surface that
    | happens not to be shown first would sit in the markup for the life of the
    | module — and §2A.2 is not "one is on top", it is that exactly one of them
    | is in the DOM at a time.
    */
    form?.remove();
    list?.remove();

    /*
    | A read-only user has no create form to land on, so they land on the list
    | and are never offered a switch to a surface they cannot use. That is the
    | same judgement §2A.10 makes about read-mostly modules, applied to a person
    | rather than to a module.
    */
    let mode = canCreate ? 'form' : 'list';
    let listLoaded = false;
    let flashId = null;

    /* --- the heading and its one control --------------------------------- */

    function paintHeader() {
        const showing = mode === 'list';
        const total = count();

        header.innerHTML = `
            <div>
                <h1 class="ws-title">${esc(title)}</h1>
                <p class="ws-sub" data-ws-sub>${esc(
                    showing ? listSubtitle(total) : formSubtitle,
                )}</p>
            </div>
            <div class="ws-actions">${canCreate ? switchControl(showing, total) : ''}</div>`;

        $('[data-ws-mode]', header)?.addEventListener('click', (event) => {
            setMode(event.currentTarget.dataset.wsMode);
        });
    }

    function switchControl(showing, total) {
        if (showing) {
            return `
                <button type="button" class="btn btn-primary" data-ws-mode="form">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                    ${esc(createLabel)}
                </button>`;
        }

        /*
        | §2A.4 — the count rides on the Show control, so the form says how much
        | is behind it without anybody having to switch to find out. It is absent
        | rather than "0" until the list has been fetched: a badge reading zero
        | on a catalogue of two hundred items would be a lie told confidently.
        */
        const badge = total === null
            ? ''
            : `<span class="badge bg-muted text-secondary-foreground">${esc(String(total))}</span>`;

        return `
            <button type="button" class="btn btn-secondary" data-ws-mode="list">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round"><path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/></svg>
                Show list
                ${badge}
            </button>`;
    }

    /* --- the swap --------------------------------------------------------- */

    async function setMode(next, { animate = true } = {}) {
        if (next === mode && surface.firstChild) return;

        mode = next;

        const showing = mode === 'list' ? list : form;

        // replaceChildren detaches the other surface rather than deleting it —
        // its DOM, and everything typed into it, is still ours.
        surface.replaceChildren(showing);
        surface.className = animate ? 'mode-swap' : '';

        paintHeader();

        if (mode === 'form') {
            onShowForm();

            return;
        }

        /*
        | §2A.7 — the list is fetched on the *first* Show and held from then on.
        | A module only ever used to write never pays for a list at all.
        */
        if (!listLoaded) {
            listLoaded = true;

            try {
                await onShowList();
            } catch (error) {
                // The module paints its own error state into the table; this
                // only has to leave the list reachable so a retry is possible.
                listLoaded = false;

                throw error;
            }

            paintHeader();
        }

        /*
        | The flag is spent the moment the list is on screen — whether the row
        | was painted just now or while the list was still detached, the flash is
        | running and the next visit must not repeat it.
        */
        flashId = null;
    }

    /* --- Escape, one level at a time -------------------------------------- */

    /*
    | §2A.9 — on the list, a press returns to the form rather than leaving the
    | module. Returning false lets the shell take the next step out to the grid.
    */
    registerEscape(key, () => {
        if (mode !== 'list' || !canCreate) return false;

        setMode('form');

        return true;
    });

    /* --- the controller the module keeps ---------------------------------- */

    const api = {
        mode: () => mode,

        /** Has the list ever been fetched? Nothing should refetch what is not held. */
        hasList: () => listLoaded,

        showForm: () => setMode('form'),
        showList: () => setMode('list'),

        /** Repaint the heading — the count, or the subtitle, has changed. */
        refresh: paintHeader,

        /**
         * Flag a row so it is highlighted when the list is next painted (§2A.8).
         *
         * A clerk who writes six items in a row never sees the list in between,
         * so the flag is held rather than applied: the flash happens whenever
         * they do look, not at a moment nobody was watching.
         */
        flagNew(id) {
            flashId = String(id);
        },

        /** True once, for the row that was just created. */
        isNew(id) {
            return flashId !== null && String(id) === flashId;
        },

        clearFlag() {
            flashId = null;
        },
    };

    setMode(mode, { animate: false });

    return api;
}

/**
 * Move a form between the level-1 slot and a drawer or modal.
 *
 * One form node, relocated — never two copies of the same fields. Create is a
 * level-1 surface and edit is a level-2 one, and a module that rendered the
 * form twice would have two sets of ids, two submit handlers and two places for
 * a validation rule to be added to only one of (§4.4, §5.1).
 *
 * The *fields* travel unchanged; only the frame around them differs, because a
 * dialog needs a title bar, a close button, a Cancel and an inner scroller and
 * an inline form needs none of those. Those parts are marked
 * `data-form-chrome="modal"` or `data-form-chrome="inline"` in the markup, and
 * this shows whichever set matches where the form is going.
 */
export function adoptForm(form, host, { chrome = 'inline' } = {}) {
    form.querySelectorAll('[data-form-chrome]').forEach((part) => {
        part.classList.toggle('hidden', part.dataset.formChrome !== chrome);
        part.classList.toggle('flex', part.dataset.formChrome === chrome);
    });

    // Read by the stylesheet, which is where the dialog's padding and its
    // max-height live — those are layout, not behaviour.
    form.dataset.chrome = chrome;

    if (form.parentElement !== host) host.replaceChildren(form);
}
